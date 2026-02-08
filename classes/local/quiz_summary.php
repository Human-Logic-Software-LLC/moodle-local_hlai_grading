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

use local_hlai_grading\local\similarity;

/**
 * Quiz_summary class.
 */
class quiz_summary {
    /** Maximum number of terms to include in term lists. */
    private const TERM_LIMIT = 8;
    /** Maximum character length for truncated question text. */
    private const QUESTION_TEXT_LIMIT = 260;
    /** Target number of items in strengths/improvements lists. */
    private const LIST_TARGET = 3;

    /**
     * Build or update the summary once all quiz AI results are reviewed.
     *
     * @param int $attemptid The quiz attempt ID.
     * @param int $quizid The quiz ID.
     * @param int $userid The user ID.
     * @param string $quality The AI quality level.
     * @return void
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
            $results = $DB->get_records('local_hlai_grading_results', [
                'modulename' => 'quiz', 'attemptid' => $attemptid,
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

        $existing = $DB->get_record('local_hlai_grading_quiz_summary', ['attemptid' => $attemptid], '*', IGNORE_MISSING);
        if ($existing) {
            $record->id = $existing->id;
            $DB->update_record('local_hlai_grading_quiz_summary', $record);
        } else {
            $record->timecreated = $now;
            $DB->insert_record('local_hlai_grading_quiz_summary', $record);
        }
    }

    /**
     * Render the summary card for the quiz review page.
     *
     * @param int $attemptid The quiz attempt ID.
     * @param \context $context The module or course context.
     * @return string The rendered HTML string.
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

        $summary = $DB->get_record('local_hlai_grading_quiz_summary', ['attemptid' => $attemptid], '*', IGNORE_MISSING);
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
            $card .= \html_writer::tag(
                'span',
                '/ ' . format_float((float)$summary->maxscore, 2),
                ['class' => 'ai-explain-grade-max']
            );
            $card .= \html_writer::end_div();

            if (!empty($summary->confidence)) {
                $card .= \html_writer::tag(
                    'p',
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
            $card .= \html_writer::tag(
                'p',
                get_string('quizsummarystrengths_empty', 'local_hlai_grading'),
                ['class' => 'text-muted']
            );
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
            $card .= \html_writer::tag(
                'p',
                get_string('quizsummaryimprovements_empty', 'local_hlai_grading'),
                ['class' => 'text-muted']
            );
        }
        $card .= \html_writer::end_div();

        $card .= \html_writer::end_div();
        $card .= \html_writer::end_div();

        return $card;
    }

    /**
     * Build the summary payload from attempt data.
     *
     * @param \stdClass $attempt The quiz attempt record.
     * @param \stdClass $quiz The quiz record.
     * @param array $resultsbyslot Grading results indexed by slot.
     * @param string $quality The AI quality level.
     * @param \question_usage_by_activity $quba The question usage instance.
     * @param \context $context The module or course context.
     * @return array|null The summary payload array or null if no details.
     */
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

    /**
     * Collect question data from grading results.
     *
     * @param array $results The grading result records.
     * @return array An array containing questions, matched frequencies, missing frequencies, and confidence.
     */
    private static function collect_question_data(array $results): array {
        global $DB;

        $questions = [];
        $matchedfreq = [];
        $missingfreq = [];
        $weighted = 0.0;
        $weighttotal = 0.0;

        $index = 1;
        foreach ($results as $result) {
            $queue = $DB->get_record('local_hlai_grading_queue', ['id' => $result->queueid], 'payload', IGNORE_MISSING);
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

    /**
     * Decode a JSON payload string into request and analysis arrays.
     *
     * @param string|null $payload The JSON payload string.
     * @return array An associative array with 'request' and 'analysis' keys.
     */
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
            'request' => $request, 'analysis' => $analysis,
        ];
    }

    /**
     * Call the AI gateway to generate a quiz summary.
     *
     * @param array $payload The summary payload to send.
     * @param string $quality The AI quality level.
     * @return array|null The decoded AI response or null on failure.
     */
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
                $content = preg_replace('/^\x60\x60\x60json\\s*/i', '', $content);
                $content = preg_replace('/\x60\x60\x60\\s*$/', '', $content);
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

    /**
     * Build detailed question data from a question usage instance.
     *
     * @param \question_usage_by_activity $quba The question usage instance.
     * @param \context $context The module or course context.
     * @param array $resultsbyslot Grading results indexed by slot number.
     * @return array The list of question detail arrays.
     */
    private static function build_attempt_details(
        \question_usage_by_activity $quba,
        \context $context,
        array $resultsbyslot
    ): array {
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

    /**
     * Format question text for display, stripping HTML and truncating.
     *
     * @param object $question The question object.
     * @param \context $context The context for formatting.
     * @return string The formatted and compacted question text.
     */
    private static function format_question_text($question, \context $context): string {
        $raw = (string)($question->questiontext ?? '');
        if ($raw === '') {
            return '';
        }
        $format = $question->questiontextformat ?? FORMAT_HTML;
        $formatted = format_text($raw, (int)$format, ['context' => $context]);
        return self::compact_text($formatted);
    }

    /**
     * Extract the student response text from a question attempt.
     *
     * @param object $qa The question attempt object.
     * @return string The compacted student response.
     */
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

    /**
     * Retrieve the correct/expected answer for a question.
     *
     * @param object $qa The question attempt object.
     * @param object $question The question object.
     * @param \context $context The context for formatting.
     * @return string The expected answer text.
     */
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

    /**
     * Get the mark awarded for a question attempt.
     *
     * @param object $qa The question attempt object.
     * @return float|null The mark or null if not available.
     */
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

    /**
     * Extract the grader key (graderinfo) from an essay question.
     *
     * @param object $question The question object.
     * @param \context $context The context for formatting.
     * @return string The formatted grader key text, or empty string.
     */
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

    /**
     * Calculate the average score percentage across question details.
     *
     * @param array $details The question detail arrays.
     * @return float|null The average score percentage or null if no scored questions.
     */
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

    /**
     * Calculate the average similarity across question details.
     *
     * @param array $details The question detail arrays.
     * @return float|null The average similarity or null if none available.
     */
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

    /**
     * Calculate the score percentage for a single question detail.
     *
     * @param array $detail The question detail array.
     * @return float|null The score percentage or null if not calculable.
     */
    private static function question_score_percent(array $detail): ?float {
        $max = (float)($detail['maxscore'] ?? 0);
        $mark = $detail['score'] ?? null;
        if ($max <= 0 || $mark === null) {
            return null;
        }
        return ((float)$mark / $max) * 100;
    }

    /**
     * Build a list of strength statements from the highest-scoring questions.
     *
     * @param array $details The question detail arrays.
     * @return array A list of strength description strings.
     */
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

    /**
     * Build a list of improvement statements from the lowest-scoring questions.
     *
     * @param array $details The question detail arrays.
     * @return array A list of improvement description strings.
     */
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

    /**
     * Sum all scores from question detail arrays.
     *
     * @param array $details The question detail arrays.
     * @return float The total score.
     */
    private static function sum_scores_from_details(array $details): float {
        $total = 0.0;
        foreach ($details as $detail) {
            $total += (float)($detail['score'] ?? 0);
        }
        return $total;
    }

    /**
     * Sum all maximum scores from question detail arrays.
     *
     * @param array $details The question detail arrays.
     * @return float The total maximum score.
     */
    private static function sum_maxscores_from_details(array $details): float {
        $total = 0.0;
        foreach ($details as $detail) {
            $total += (float)($detail['maxscore'] ?? 0);
        }
        return $total;
    }

    /**
     * Normalize a list by trimming and removing empty entries.
     *
     * @param mixed $list The input list to normalize.
     * @return array The cleaned list of non-empty strings.
     */
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

    /**
     * Ensure a list has at least LIST_TARGET unique items, filling from fallback.
     *
     * @param array $list The primary list of items.
     * @param array $fallback Fallback items to fill if needed.
     * @return array The list with at most LIST_TARGET unique items.
     */
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

    /**
     * Return the default strength statements.
     *
     * @return array The default list of strength strings.
     */
    private static function default_strengths(): array {
        return [
            'Key concepts - Demonstrated understanding of several core ideas.',
            'Accuracy - Responses aligned with expected answers on multiple questions.',
            'Consistency - Maintained effort across the attempt.',
        ];
    }

    /**
     * Return the default improvement statements.
     *
     * @return array The default list of improvement strings.
     */
    private static function default_improvements(): array {
        return [
            'Depth - Add more technical detail to support your answers.',
            'Coverage - Review key terms and concepts that were missing.',
            'Structure - Organize responses around main points and steps.',
        ];
    }

    /**
     * Build bullet-point strings from a list of terms.
     *
     * @param array $terms The list of term strings.
     * @param string $prefix The prefix to prepend to each bullet.
     * @return array The list of formatted bullet strings.
     */
    private static function build_term_bullets(array $terms, string $prefix): array {
        $bullets = [];
        foreach ($terms as $term) {
            $bullets[] = $prefix . ' ' . $term . '.';
        }
        return $bullets;
    }

    /**
     * Return the top N most frequent terms from a frequency array.
     *
     * @param array $freqs Associative array of term => frequency.
     * @param int $limit Maximum number of terms to return.
     * @return array The top terms sorted by frequency.
     */
    private static function top_terms(array $freqs, int $limit): array {
        if (empty($freqs)) {
            return [];
        }
        arsort($freqs);
        return array_slice(array_keys($freqs), 0, $limit);
    }

    /**
     * Build fallback feedback text when AI summary is unavailable.
     *
     * @param float $score The total score achieved.
     * @param float $maxscore The maximum possible score.
     * @param float|null $averagescore The average score percentage.
     * @param float|null $averagesimilarity The average similarity percentage.
     * @return string The fallback feedback text.
     */
    private static function build_fallback_feedback(
        float $score,
        float $maxscore,
        ?float $averagescore,
        ?float $averagesimilarity
    ): string {
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
        $paragraphs[] = 'To reach an outstanding level, add technical detail, explain procedures step by step, ' .
            'and ensure each response covers all required points with precise terminology.';
        return implode("\n\n", $paragraphs);
    }

    /**
     * Strip HTML tags, trim, and truncate text to the question text limit.
     *
     * @param string $text The input text.
     * @return string The compacted text.
     */
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

    /**
     * Split text into non-empty paragraphs by newline boundaries.
     *
     * @param string $text The input text.
     * @return array The list of non-empty paragraph strings.
     */
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

    /**
     * Sum all grades from result records.
     *
     * @param array $results The grading result records.
     * @return float The total grade.
     */
    private static function sum_scores(array $results): float {
        $total = 0.0;
        foreach ($results as $result) {
            $total += (float)($result->grade ?? 0);
        }
        return $total;
    }

    /**
     * Sum all maximum grades from result records.
     *
     * @param array $results The grading result records.
     * @return float The total maximum grade.
     */
    private static function sum_maxscores(array $results): float {
        $total = 0.0;
        foreach ($results as $result) {
            $total += (float)($result->maxgrade ?? 0);
        }
        return $total;
    }

    /**
     * Count the number of expected essay questions in a question usage.
     *
     * @param int $usageid The question usage ID.
     * @return int The count of essay questions requiring manual grading.
     */
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
