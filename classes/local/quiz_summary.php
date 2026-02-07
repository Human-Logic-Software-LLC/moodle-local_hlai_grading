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
 * Quiz summary generation class.
 *
 * @package    local_hlai_grading
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_hlai_grading\local;

defined('MOODLE_INTERNAL') || die();

use local_hlai_grading\local\similarity;

/**
 * Quiz_summary class.
 */
class quiz_summary {
    private const TERM_LIMIT = 8;
    private const QUESTION_TEXT_LIMIT = 260;
    private const LIST_TARGET = 3;

    /**
     * Build or update the summary once all quiz AI results are reviewed.
     */
    public static function maybe_generate_for_attempt(int $attemptid, int $quizid, int $userid, string $quality): void {
        global $DB, $CFG;

        if ($attemptid <= 0 || $quizid <= 0) {
            return;
        }

        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        require_once($CFG->dirroot . '/question/engine/lib.php');

        $attempt = $DB->get_record('quiz_attempts', ['id' => $attemptid], '*', IGNORE_MISSING);
        if (!$attempt) {
            return;
        }

        $quiz = $DB->get_record('quiz', ['id' => $quizid], '*', IGNORE_MISSING);
        if (!$quiz) {
            return;
        }

        $cm = get_coursemodule_from_instance('quiz', $quiz->id, $quiz->course, false, IGNORE_MISSING);
        $context = $cm ? \context_module::instance($cm->id) : \context_course::instance($quiz->course);

        $quba = \question_engine::load_questions_usage_by_activity($attempt->uniqueid);
        $slots = $quba->get_slots();
        if (empty($slots)) {
            return;
        }

        $essayslots = [];
        foreach ($slots as $slot) {
            $qa = $quba->get_question_attempt($slot);
            $question = $qa->get_question();
            if ($question && $question->get_type_name() === 'essay') {
                $essayslots[] = (int)$slot;
            }
        }

        $results = [];
        $resultsbyslot = [];
        if (!empty($essayslots)) {
            $results = $DB->get_records('hlai_grading_results', [
                'modulename' => 'quiz',
                'attemptid' => $attemptid,
            ], 'timecreated ASC');

            foreach ($results as $result) {
                $resultsbyslot[(int)$result->slot] = $result;
                if ($result->status === 'draft') {
                    return;
                }
            }

            foreach ($essayslots as $slot) {
                if (!isset($resultsbyslot[$slot])) {
                    return;
                }
            }
        }

        $summary = self::build_summary_payload($attempt, $quiz, $resultsbyslot, $quality, $quba, $context);
        if (!$summary) {
            return;
        }

        $now = time();
        $record = (object)[
            'quizid' => $quiz->id,
            'attemptid' => $attemptid,
            'userid' => $userid ?: (int)$attempt->userid,
            'score' => $summary['score'],
            'maxscore' => $summary['maxscore'],
            'feedback' => $summary['feedback'],
            'strengths_json' => json_encode($summary['strengths']),
            'improvements_json' => json_encode($summary['improvements']),
            'confidence' => $summary['confidence'],
            'model' => $summary['model'],
            'quality' => $quality,
            'timemodified' => $now,
        ];

        $existing = $DB->get_record('hlai_grading_quiz_summary', ['attemptid' => $attemptid], '*', IGNORE_MISSING);
        if ($existing) {
            $record->id = $existing->id;
            $DB->update_record('hlai_grading_quiz_summary', $record);
        } else {
            $record->timecreated = $now;
            $DB->insert_record('hlai_grading_quiz_summary', $record);
        }
    }

    /**
     * Render the summary card for the quiz review page.
     */
    public static function render_summary_card(int $attemptid, \context $context): string {
        global $DB, $USER;

        if ($attemptid <= 0) {
            return '';
        }

        $attempt = $DB->get_record('quiz_attempts', ['id' => $attemptid], '*', IGNORE_MISSING);
        if (!$attempt) {
            return '';
        }

        $isowner = ((int)$attempt->userid === (int)$USER->id);
        if ($isowner) {
            if (!has_capability('mod/quiz:view', $context)) {
                return '';
            }
        } else if (!has_capability('mod/quiz:grade', $context)) {
            return '';
        }

        $summary = $DB->get_record('hlai_grading_quiz_summary', ['attemptid' => $attemptid], '*', IGNORE_MISSING);
        if (!$summary) {
            return '';
        }

        $strengths = json_decode($summary->strengths_json ?? '[]', true) ?: [];
        $improvements = json_decode($summary->improvements_json ?? '[]', true) ?: [];
        $feedback = trim((string)($summary->feedback ?? ''));

        $card = \html_writer::start_div('card ai-grade-explain-card mb-4 ai-quiz-summary-card');
        $card .= \html_writer::start_div('card-body');
        $card .= \html_writer::tag('h4', get_string('quizsummarytitle', 'local_hlai_grading'), ['class' => 'card-title mb-1']);
        $card .= \html_writer::tag('p', get_string('quizsummaryintro', 'local_hlai_grading'), ['class' => 'text-muted mb-3']);

        if (!is_null($summary->score) && !is_null($summary->maxscore)) {
            $card .= \html_writer::start_div('ai-explain-summary');
            $card .= \html_writer::start_div('ai-explain-grade');
            $card .= \html_writer::tag('span', format_float((float)$summary->score, 2), ['class' => 'ai-explain-grade-value']);
            $card .= \html_writer::tag('span', '/ ' . format_float((float)$summary->maxscore, 2), ['class' => 'ai-explain-grade-max']);
            $card .= \html_writer::end_div();

            if (!empty($summary->confidence)) {
                $card .= \html_writer::tag('p',
                    get_string('quizsummaryconfidence', 'local_hlai_grading', format_float((float)$summary->confidence, 2)),
                    ['class' => 'ai-explain-confidence']
                );
            }

            if ($feedback !== '') {
                $card .= \html_writer::start_div('ai-explain-overall');
                $card .= \html_writer::tag('h5', get_string('quizsummaryoverall', 'local_hlai_grading'));
                foreach (self::split_paragraphs($feedback) as $paragraph) {
                    $card .= \html_writer::tag('p', s($paragraph));
                }
                $card .= \html_writer::end_div();
            }

            $card .= \html_writer::end_div();
        }

        $card .= \html_writer::start_div('ai-explain-section');
        $card .= \html_writer::tag('h5', get_string('quizsummarystrengths', 'local_hlai_grading'));
        if (!empty($strengths)) {
            $items = '';
            foreach ($strengths as $item) {
                $items .= \html_writer::tag('li', s($item));
            }
            $card .= \html_writer::tag('ul', $items, ['class' => 'ai-explain-list']);
        } else {
            $card .= \html_writer::tag('p', get_string('quizsummarystrengths_empty', 'local_hlai_grading'), ['class' => 'text-muted']);
        }
        $card .= \html_writer::end_div();

        $card .= \html_writer::start_div('ai-explain-section');
        $card .= \html_writer::tag('h5', get_string('quizsummaryimprovements', 'local_hlai_grading'));
        if (!empty($improvements)) {
            $items = '';
            foreach ($improvements as $item) {
                $items .= \html_writer::tag('li', s($item));
            }
            $card .= \html_writer::tag('ul', $items, ['class' => 'ai-explain-list improvements']);
        } else {
            $card .= \html_writer::tag('p', get_string('quizsummaryimprovements_empty', 'local_hlai_grading'), ['class' => 'text-muted']);
        }
        $card .= \html_writer::end_div();

        $card .= \html_writer::end_div();
        $card .= \html_writer::end_div();

        return $card;
    }

    private static function build_summary_payload(
        \stdClass $attempt,
        \stdClass $quiz,
        array $resultsbyslot,
        string $quality,
        \question_usage_by_activity $quba,
        \context $context
    ): ?array {
        $details = self::build_attempt_details($quba, $context, $resultsbyslot);
        if (empty($details)) {
            return null;
        }

        $score = isset($attempt->sumgrades) ? (float)$attempt->sumgrades : 0.0;
        $maxscore = isset($quiz->sumgrades) ? (float)$quiz->sumgrades : 0.0;
        if ($score <= 0.0) {
            $score = self::sum_scores_from_details($details);
        }
        if ($maxscore <= 0.0) {
            $maxscore = self::sum_maxscores_from_details($details);
        }

        $averagescore = self::average_score($details);
        $averagesimilarity = self::average_similarity($details);

        $summarypayload = [
            'quiz_name' => (string)($quiz->name ?? 'Quiz'),
            'score' => $score,
            'max_score' => $maxscore,
            'average_score' => $averagescore,
            'average_similarity' => $averagesimilarity,
            'details' => $details,
        ];
        $ai = self::call_ai_summary($summarypayload, $quality);

        if ($ai) {
            $strengths = self::normalize_list($ai['strengths'] ?? []);
            $improvements = self::normalize_list($ai['improvements'] ?? []);
            $feedback = trim((string)($ai['overall_feedback'] ?? ($ai['feedback'] ?? '')));
            $model = $ai['model'] ?? 'gateway:summary';

            $strengths = self::ensure_count($strengths, self::default_strengths());
            $improvements = self::ensure_count($improvements, self::default_improvements());
            if ($feedback === '') {
                $feedback = self::build_fallback_feedback($score, $maxscore, $averagescore, $averagesimilarity);
            }

            return [
                'score' => $score,
                'maxscore' => $maxscore,
                'feedback' => $feedback,
                'strengths' => $strengths,
                'improvements' => $improvements,
                'confidence' => $averagesimilarity,
                'model' => $model,
            ];
        }

        $strengths = self::build_score_strengths($details);
        $improvements = self::build_score_improvements($details);

        $strengths = self::ensure_count($strengths, self::default_strengths());
        $improvements = self::ensure_count($improvements, self::default_improvements());

        return [
            'score' => $score,
            'maxscore' => $maxscore,
            'feedback' => self::build_fallback_feedback($score, $maxscore, $averagescore, $averagesimilarity),
            'strengths' => $strengths,
            'improvements' => $improvements,
            'confidence' => $averagesimilarity,
            'model' => 'semantic:summary',
        ];
    }

    private static function collect_question_data(array $results): array {
        global $DB;

        $questions = [];
        $matchedfreq = [];
        $missingfreq = [];
        $weighted = 0.0;
        $weighttotal = 0.0;

        $index = 1;
        foreach ($results as $result) {
            $queue = $DB->get_record('hlai_grading_queue', ['id' => $result->queueid], 'payload', IGNORE_MISSING);
            $payload = self::decode_payload($queue ? ($queue->payload ?? null) : null);
            $request = $payload['request'];
            $analysis = $payload['analysis'];

            if (!$analysis && !empty($request['keytext']) && !empty($request['submissiontext'])) {
                $analysis = similarity::analyze((string)$request['keytext'], (string)$request['submissiontext']);
            }

            $questiontext = $request['question'] ?? $request['questionname'] ?? ('Question ' . $index);
            $questiontext = self::compact_text((string)$questiontext);

            $score = (float)($result->grade ?? 0);
            $maxscore = (float)($result->maxgrade ?? ($request['maxmark'] ?? 0));

            $similarity = null;
            if (is_array($analysis) && isset($analysis['final_percent'])) {
                $similarity = (float)$analysis['final_percent'];
            }

            if ($similarity !== null) {
                $weight = $maxscore > 0 ? $maxscore : 1.0;
                $weighted += $similarity * $weight;
                $weighttotal += $weight;
            }

            $matched = [];
            $missing = [];
            if (is_array($analysis)) {
                $matched = $analysis['matched_terms'] ?? [];
                $missing = $analysis['missing_terms'] ?? [];
            }

            foreach ($matched as $term) {
                $term = trim((string)$term);
                if ($term === '') {
                    continue;
                }
                $matchedfreq[$term] = ($matchedfreq[$term] ?? 0) + 1;
            }

            foreach ($missing as $term) {
                $term = trim((string)$term);
                if ($term === '') {
                    continue;
                }
                $missingfreq[$term] = ($missingfreq[$term] ?? 0) + 1;
            }

            $questions[] = [
                'question' => $questiontext,
                'score' => $score,
                'maxscore' => $maxscore,
                'similarity' => $similarity,
                'matched_terms' => array_slice($matched, 0, self::TERM_LIMIT),
                'missing_terms' => array_slice($missing, 0, self::TERM_LIMIT),
            ];

            $index++;
        }

        $confidence = null;
        if ($weighttotal > 0) {
            $confidence = round($weighted / $weighttotal, 2);
        }

        return [$questions, $matchedfreq, $missingfreq, $confidence];
    }

    private static function decode_payload(?string $payload): array {
        $decoded = json_decode($payload ?? '[]', true);
        if (!is_array($decoded)) {
            $decoded = [];
        }

        $request = $decoded['request'] ?? $decoded;
        if (!is_array($request)) {
            $request = [];
        }

        $analysis = $decoded['analysis'] ?? null;
        if (!is_array($analysis)) {
            $analysis = null;
        }

        return [
            'request' => $request,
            'analysis' => $analysis,
        ];
    }

    private static function call_ai_summary(array $payload, string $quality): ?array {
        $response = null;
        try {
            $response = gateway_client::grade('quiz_summary', $payload, $quality);
        } catch (\Throwable $e) {
            return null;
        }

        if ($response === null) {
            return null;
        }

        $content = $response['content'] ?? '';
        if (is_array($content)) {
            $decoded = $content;
        } else {
            $content = trim((string)$content);
            if ($content !== '') {
                $content = preg_replace('/^```json\\s*/i', '', $content);
                $content = preg_replace('/```\\s*$/', '', $content);
            }
            $decoded = json_decode($content ?? '', true);
        }
        if (!is_array($decoded)) {
            return null;
        }

        $provider = (string)($response['provider'] ?? 'gateway');
        $decoded['model'] = $provider ? 'gateway:' . $provider : 'gateway:summary';

        return $decoded;
    }

    private static function build_attempt_details(\question_usage_by_activity $quba, \context $context, array $resultsbyslot): array {
        $details = [];
        foreach ($quba->get_slots() as $slot) {
            $qa = $quba->get_question_attempt($slot);
            $question = $qa->get_question();
            if (!$question) {
                continue;
            }

            $questiontext = self::format_question_text($question, $context);
            $questionlabel = $questiontext !== '' ? $questiontext : format_string($question->name ?? 'Question');

            $detail = [
                'slot' => (int)$slot,
                'question' => $questionlabel,
                'questiontype' => $question->get_type_name(),
                'student_response' => self::get_student_response($qa),
                'expected_answer' => self::get_correct_answer($qa, $question, $context),
                'score' => self::get_question_mark($qa),
                'maxscore' => (float)$qa->get_max_mark(),
                'similarity' => null,
            ];

            if (isset($resultsbyslot[(int)$slot])) {
                $result = $resultsbyslot[(int)$slot];
                if (isset($result->confidence)) {
                    $detail['similarity'] = (float)$result->confidence;
                }
            }

            $details[] = $detail;
        }

        return $details;
    }

    private static function format_question_text($question, \context $context): string {
        $raw = (string)($question->questiontext ?? '');
        if ($raw === '') {
            return '';
        }
        $format = $question->questiontextformat ?? FORMAT_HTML;
        $formatted = format_text($raw, (int)$format, ['context' => $context]);
        return self::compact_text($formatted);
    }

    private static function get_student_response($qa): string {
        $response = '';
        if (method_exists($qa, 'get_last_qt_var')) {
            $response = (string)$qa->get_last_qt_var('answer');
        }
        if (trim($response) === '') {
            $response = (string)$qa->get_response_summary();
        }
        return self::compact_text($response);
    }

    private static function get_correct_answer($qa, $question, \context $context): string {
        if ($question->get_type_name() === 'essay') {
            $graderkey = self::extract_question_grader_key($question, $context);
            if ($graderkey !== '') {
                return $graderkey;
            }
        }

        $rightanswer = '';
        if (method_exists($qa, 'get_right_answer_summary')) {
            $rightanswer = (string)$qa->get_right_answer_summary();
        }

        if (trim($rightanswer) === '' && method_exists($question, 'get_correct_response')) {
            $response = $question->get_correct_response();
            if (is_array($response) && method_exists($question, 'summarise_response')) {
                $rightanswer = (string)$question->summarise_response($response);
            } else if (!is_array($response)) {
                $rightanswer = (string)$response;
            }
        }

        if ($rightanswer === '') {
            $rightanswer = 'Not available';
        }

        return self::compact_text($rightanswer);
    }

    private static function get_question_mark($qa): ?float {
        if (method_exists($qa, 'get_mark')) {
            $mark = $qa->get_mark();
            if ($mark !== null) {
                return (float)$mark;
            }
        }
        if (method_exists($qa, 'get_fraction')) {
            $fraction = $qa->get_fraction();
            if ($fraction !== null) {
                return (float)$fraction * (float)$qa->get_max_mark();
            }
        }
        return null;
    }

    private static function extract_question_grader_key($question, \context $context): string {
        $raw = '';
        $format = FORMAT_HTML;

        if (is_object($question)) {
            if (!empty($question->graderinfo)) {
                $raw = (string)$question->graderinfo;
                $format = $question->graderinfoformat ?? FORMAT_HTML;
            } else if (!empty($question->options) && !empty($question->options->graderinfo)) {
                $raw = (string)$question->options->graderinfo;
                $format = $question->options->graderinfoformat ?? FORMAT_HTML;
            }
        }

        if (trim($raw) === '') {
            return '';
        }

        $formatted = format_text($raw, (int)$format, ['context' => $context]);
        return self::compact_text($formatted);
    }

    private static function average_score(array $details): ?float {
        $total = 0.0;
        $count = 0;
        foreach ($details as $detail) {
            $max = (float)($detail['maxscore'] ?? 0);
            $mark = $detail['score'] ?? null;
            if ($max > 0 && $mark !== null) {
                $total += ((float)$mark / $max) * 100;
                $count++;
            }
        }
        if ($count === 0) {
            return null;
        }
        return $total / $count;
    }

    private static function average_similarity(array $details): ?float {
        $total = 0.0;
        $count = 0;
        foreach ($details as $detail) {
            if (!array_key_exists('similarity', $detail) || $detail['similarity'] === null) {
                continue;
            }
            $total += (float)$detail['similarity'];
            $count++;
        }
        if ($count === 0) {
            return null;
        }
        return $total / $count;
    }

    private static function question_score_percent(array $detail): ?float {
        $max = (float)($detail['maxscore'] ?? 0);
        $mark = $detail['score'] ?? null;
        if ($max <= 0 || $mark === null) {
            return null;
        }
        return ((float)$mark / $max) * 100;
    }

    private static function build_score_strengths(array $details): array {
        $sorted = $details;
        usort($sorted, static function ($a, $b) {
            $ascore = self::question_score_percent($a);
            $bscore = self::question_score_percent($b);
            if ($ascore === null && $bscore === null) {
                return 0;
            }
            if ($ascore === null) {
                return 1;
            }
            if ($bscore === null) {
                return -1;
            }
            return $bscore <=> $ascore;
        });

        $strengths = [];
        foreach (array_slice($sorted, 0, self::LIST_TARGET) as $detail) {
            $score = self::question_score_percent($detail);
            $strengths[] = sprintf(
                'Strong performance on %s%s.',
                $detail['question'],
                $score === null ? '' : sprintf(' (%.1f%%)', $score)
            );
        }
        return $strengths;
    }

    private static function build_score_improvements(array $details): array {
        $sorted = $details;
        usort($sorted, static function ($a, $b) {
            $ascore = self::question_score_percent($a);
            $bscore = self::question_score_percent($b);
            if ($ascore === null && $bscore === null) {
                return 0;
            }
            if ($ascore === null) {
                return -1;
            }
            if ($bscore === null) {
                return 1;
            }
            return $ascore <=> $bscore;
        });

        $improvements = [];
        foreach (array_slice($sorted, 0, self::LIST_TARGET) as $detail) {
            $score = self::question_score_percent($detail);
            if ($score !== null) {
                $improvements[] = sprintf(
                    'Revisit %s to raise your score beyond %.1f%%.',
                    $detail['question'],
                    $score
                );
            } else {
                $improvements[] = sprintf(
                    'Revisit %s to improve accuracy and completeness.',
                    $detail['question']
                );
            }
        }

        return $improvements;
    }

    private static function sum_scores_from_details(array $details): float {
        $total = 0.0;
        foreach ($details as $detail) {
            $total += (float)($detail['score'] ?? 0);
        }
        return $total;
    }

    private static function sum_maxscores_from_details(array $details): float {
        $total = 0.0;
        foreach ($details as $detail) {
            $total += (float)($detail['maxscore'] ?? 0);
        }
        return $total;
    }

    private static function normalize_list($list): array {
        if (!is_array($list)) {
            return [];
        }
        $out = [];
        foreach ($list as $item) {
            $value = trim((string)$item);
            if ($value !== '') {
                $out[] = $value;
            }
        }
        return $out;
    }

    private static function ensure_count(array $list, array $fallback): array {
        $unique = [];
        foreach ($list as $item) {
            if (!in_array($item, $unique, true)) {
                $unique[] = $item;
            }
        }

        foreach ($fallback as $item) {
            if (count($unique) >= self::LIST_TARGET) {
                break;
            }
            if (!in_array($item, $unique, true)) {
                $unique[] = $item;
            }
        }

        return array_slice($unique, 0, self::LIST_TARGET);
    }

    private static function default_strengths(): array {
        return [
            'Key concepts - Demonstrated understanding of several core ideas.',
            'Accuracy - Responses aligned with expected answers on multiple questions.',
            'Consistency - Maintained effort across the attempt.',
        ];
    }

    private static function default_improvements(): array {
        return [
            'Depth - Add more technical detail to support your answers.',
            'Coverage - Review key terms and concepts that were missing.',
            'Structure - Organize responses around main points and steps.',
        ];
    }

    private static function build_term_bullets(array $terms, string $prefix): array {
        $bullets = [];
        foreach ($terms as $term) {
            $bullets[] = $prefix . ' ' . $term . '.';
        }
        return $bullets;
    }

    private static function top_terms(array $freqs, int $limit): array {
        if (empty($freqs)) {
            return [];
        }
        arsort($freqs);
        return array_slice(array_keys($freqs), 0, $limit);
    }

    private static function build_fallback_feedback(float $score, float $maxscore, ?float $averagescore, ?float $averagesimilarity): string {
        $paragraphs = [];
        if ($maxscore > 0) {
            $line = sprintf(
                'You scored %.2f out of %.2f, showing a developing foundation in the assessed topics.',
                $score,
                $maxscore
            );
        } else {
            $line = 'You demonstrated a developing foundation in the assessed topics.';
        }
        $stats = [];
        if ($averagescore !== null) {
            $stats[] = sprintf('Overall accuracy across questions was %.2f%%.', $averagescore);
        }
        if ($averagesimilarity !== null) {
            $stats[] = sprintf('Essay alignment averaged %.2f%%.', $averagesimilarity);
        }
        $paragraphs[] = trim($line . ' ' . implode(' ', $stats));
        $paragraphs[] = 'To reach an outstanding level, add technical detail, explain procedures step by step, and ensure each response covers all required points with precise terminology.';
        return implode("\n\n", $paragraphs);
    }

    private static function compact_text(string $text): string {
        $text = trim(strip_tags($text));
        if ($text === '') {
            return $text;
        }
        if (strlen($text) > self::QUESTION_TEXT_LIMIT) {
            $text = substr($text, 0, self::QUESTION_TEXT_LIMIT - 3) . '...';
        }
        return $text;
    }

    private static function split_paragraphs(string $text): array {
        $parts = preg_split('/\n+/', $text);
        $out = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== '') {
                $out[] = $part;
            }
        }
        return $out;
    }

    private static function sum_scores(array $results): float {
        $total = 0.0;
        foreach ($results as $result) {
            $total += (float)($result->grade ?? 0);
        }
        return $total;
    }

    private static function sum_maxscores(array $results): float {
        $total = 0.0;
        foreach ($results as $result) {
            $total += (float)($result->maxgrade ?? 0);
        }
        return $total;
    }

    private static function count_expected_questions(int $usageid): int {
        $count = 0;
        $quba = \question_engine::load_questions_usage_by_activity($usageid);
        $slots = $quba->get_slots();
        foreach ($slots as $slot) {
            if (!\question_engine::is_manual_grade_in_range($usageid, $slot)) {
                continue;
            }
            $qa = $quba->get_question_attempt($slot);
            $question = $qa->get_question();
            if ($question && in_array($question->get_type_name(), ['essay'], true)) {
                $count++;
            }
        }
        return $count;
    }
}
