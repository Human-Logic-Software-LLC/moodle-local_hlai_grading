<?php
if (!defined('CLI_SCRIPT')) {
    define('CLI_SCRIPT', true);
}
require('C:\\xampp81\\htdocs\\moodle41\\config.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');
require_once($CFG->dirroot . '/question/engine/lib.php');
require_once($CFG->dirroot . '/local/hlai_grading/classes/local/similarity.php');

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
$results = $DB->get_records('hlai_grading_results', ['modulename' => 'quiz', 'instanceid' => $quizid], 'attemptid ASC, slot ASC');
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
        $queue = $DB->get_record('hlai_grading_queue', ['id' => $result->queueid], 'id, payload', IGNORE_MISSING);
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
        } elseif (!empty($question->options) && !empty($question->options->graderinfo)) {
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
                $options = $DB->get_record('qtype_essay_options', ['questionid' => $question->id], 'graderinfo,graderinfoformat', IGNORE_MISSING);
                if ($options && trim((string)$options->graderinfo) !== '') {
                    $formatted = format_text((string)$options->graderinfo, (int)($options->graderinfoformat ?? FORMAT_HTML), ['context' => $context]);
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

        $DB->update_record('hlai_grading_results', $result);
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
            $DB->update_record('hlai_grading_queue', $queue);
        }
    }
}

echo "Regraded results: {$updated}\n";
echo "Skipped results: {$skipped}\n";
echo "Errors: {$errors}\n";
?>
