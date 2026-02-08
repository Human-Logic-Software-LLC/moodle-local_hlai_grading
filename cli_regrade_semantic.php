<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * CLI script to regrade using semantic analysis.
 *
 * @package    local_hlai_grading
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');
require_once($CFG->dirroot . '/question/engine/lib.php');
require_once($CFG->dirroot . '/local/hlai_grading/classes/local/similarity.php');

/**
 * Normalize text by stripping HTML tags, decoding entities, and collapsing whitespace.
 *
 * @param string $text The raw text to normalize.
 * @return string The cleaned plain-text string.
 */
function normalize_text_local(string $text): string {
    $plain = trim(strip_tags($text));
    if ($plain === '') {
        return '';
    }
    $plain = html_entity_decode($plain, ENT_QUOTES | ENT_HTML5);
    $plain = preg_replace('/\s+/u', ' ', $plain);
    return trim($plain);
}

global $DB;

$quizid = 6;
$results = $DB->get_records(
    'local_hlai_grading_results',
    ['modulename' => 'quiz', 'instanceid' => $quizid],
    'attemptid ASC, slot ASC'
);
if (!$results) {
    echo "No quiz results found for quiz {$quizid}.\n";
    exit(0);
}

$grouped = [];
foreach ($results as $result) {
    $grouped[$result->attemptid][] = $result;
}

$updated = 0;
$skipped = 0;
$errors = 0;

foreach ($grouped as $attemptid => $attemptresults) {
    $attempt = $DB->get_record('quiz_attempts', ['id' => $attemptid], '*', IGNORE_MISSING);
    if (!$attempt) {
        continue;
    }

    $quiz = $DB->get_record('quiz', ['id' => $attempt->quiz], 'id, course', IGNORE_MISSING);
    if (!$quiz) {
        continue;
    }

    $cm = get_coursemodule_from_instance('quiz', $quiz->id, $quiz->course, false, IGNORE_MISSING);
    if (!$cm) {
        continue;
    }

    $context = \context_module::instance($cm->id);
    $quba = \question_engine::load_questions_usage_by_activity($attempt->uniqueid);

    foreach ($attemptresults as $result) {
        $slot = (int)($result->slot ?? 0);
        if (!$slot) {
            $skipped++;
            continue;
        }

        $qa = $quba->get_question_attempt($slot);
        $question = $qa->get_question();
        if (!$question || $question->get_type_name() !== 'essay') {
            $skipped++;
            continue;
        }

        $student = trim((string)$qa->get_last_qt_var('answer'));
        if ($student === '') {
            $student = trim((string)$qa->get_response_summary());
        }

        $queuepayload = [];
        $queue = $DB->get_record('local_hlai_grading_queue', ['id' => $result->queueid], 'id, payload', IGNORE_MISSING);
        if ($queue) {
            $queuepayload = json_decode($queue->payload ?? '[]', true) ?: [];
            if (isset($queuepayload['request']) && is_array($queuepayload['request'])) {
                $queuepayload = $queuepayload['request'];
            }
        }

        if ($student === '' && !empty($queuepayload['submissiontext'])) {
            $student = (string)$queuepayload['submissiontext'];
        }

        $student = normalize_text_local($student);
        if ($student === '') {
            $skipped++;
            continue;
        }

        $graderraw = '';
        $graderformat = FORMAT_HTML;
        if (!empty($question->graderinfo)) {
            $graderraw = (string)$question->graderinfo;
            $graderformat = $question->graderinfoformat ?? FORMAT_HTML;
        } else if (!empty($question->options) && !empty($question->options->graderinfo)) {
            $graderraw = (string)$question->options->graderinfo;
            $graderformat = $question->options->graderinfoformat ?? FORMAT_HTML;
        }
        $graderkey = '';
        if ($graderraw !== '') {
            $formatted = format_text($graderraw, (int)$graderformat, ['context' => $context]);
            $graderkey = normalize_text_local($formatted);
        }
        if ($graderkey === '') {
            if (!empty($queuepayload['keytext'])) {
                $graderkey = normalize_text_local((string)$queuepayload['keytext']);
            } else {
                $options = $DB->get_record(
                    'qtype_essay_options',
                    ['questionid' => $question->id],
                    'graderinfo,graderinfoformat',
                    IGNORE_MISSING
                );
                if ($options && trim((string)$options->graderinfo) !== '') {
                    $formatted = format_text(
                        (string)$options->graderinfo,
                        (int)($options->graderinfoformat ?? FORMAT_HTML),
                        ['context' => $context]
                    );
                    $graderkey = normalize_text_local($formatted);
                }
            }
        }
        if ($graderkey === '') {
            $skipped++;
            continue;
        }

        try {
            $analysis = \local_hlai_grading\local\similarity::analyze($graderkey, $student);
        } catch (Throwable $e) {
            $errors++;
            continue;
        }

        $maxmark = (float)$qa->get_max_mark();
        $scorepercent = isset($analysis['final_percent']) ? (float)$analysis['final_percent'] : 0.0;
        $grade = ($scorepercent / 100) * $maxmark;

        $strengths = \local_hlai_grading\local\similarity::format_term_list($analysis['matched_terms'] ?? [], '');
        $improvements = \local_hlai_grading\local\similarity::format_term_list($analysis['missing_terms'] ?? [], '');

        $result->grade = $grade;
        $result->maxgrade = $maxmark;
        $result->reasoning = $analysis['reasoning'] ?? $result->reasoning;
        $result->strengths_json = json_encode($strengths);
        $result->improvements_json = json_encode($improvements);
        $result->confidence = isset($analysis['final_percent']) ? round((float)$analysis['final_percent'], 2) : $result->confidence;
        $result->model = ($analysis['method'] ?? 'semantic') . ':local';

        $DB->update_record('local_hlai_grading_results', $result);
        $updated++;

        if ($queue) {
            $payload = json_decode($queue->payload ?? '[]', true) ?: [];
            if (isset($payload['request']) && is_array($payload['request'])) {
                $payload['analysis'] = $analysis;
            } else {
                $payload = [
                    'request' => $payload,
                    'analysis' => $analysis,
                    'resultid' => $result->id,
                    'provider' => $analysis['method'] ?? 'semantic',
                ];
            }
            $queue->payload = json_encode($payload);
            $DB->update_record('local_hlai_grading_queue', $queue);
        }
    }
}

echo "Regraded results: {$updated}\n";
echo "Skipped results: {$skipped}\n";
echo "Errors: {$errors}\n";
