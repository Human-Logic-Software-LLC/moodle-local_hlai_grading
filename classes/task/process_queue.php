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
 * Scheduled task to process grading queue.
 *
 * @package    local_hlai_grading
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_hlai_grading\task;

use core\task\scheduled_task;
use local_hlai_grading\local\similarity;

/**
 * Process_queue class.
 */
class process_queue extends scheduled_task {
    /**
     * Get the task name.
     *
     * @return string The task name.
     */
    public function get_name() {
        return get_string('task_process_queue', 'local_hlai_grading');
    }

    /**
     * Execute the queue processing task.
     *
     * @return void
     */
    public function execute() {
        global $DB, $CFG;

        // If plugin is disabled, do nothing.
        if (!get_config('local_hlai_grading', 'enable')) {
            return;
        }

        require_once($CFG->dirroot . '/local/hlai_grading/lib.php');

        $pushgrades   = (bool)get_config('local_hlai_grading', 'pushgrades');
        $pushfeedback = (bool)get_config('local_hlai_grading', 'pushfeedback');

        // SPEC: Fetch pending items that are ready to run (respect nextrun for retries).
        $now = time();
        $sql = "SELECT * FROM {local_hlai_grading_queue}
                WHERE status = :status
                AND (nextrun IS NULL OR nextrun <= :now)
                ORDER BY timecreated ASC";
        $items = $DB->get_records_sql($sql, ['status' => 'pending', 'now' => $now], 0, 20);

        if (!$items) {
            return;
        }

        require_once($CFG->libdir . '/gradelib.php');

        foreach ($items as $item) {
            try {
                $payload = json_decode($item->payload ?? '[]', true) ?: [];
                if (isset($payload['request']) && is_array($payload['request'])) {
                    $payload = $payload['request'];
                }
                // Unwrap nested "request" envelopes if present (some events nest the data).
                while (isset($payload['request']) && is_array($payload['request'])) {
                    $payload = $payload['request'];
                }

                $courseid = (int)($payload['courseid'] ?? 0);
                $assignid = (int)($payload['assignid'] ?? 0);
                $modulename = $payload['modulename'] ?? null;
                $modulename = $modulename ?: ($assignid ? 'assign' : '');
                if (empty($modulename)) {
                    throw new \Exception("Queue item {$item->id}: missing modulename in payload");
                }
                $instanceid = (int)($payload['instanceid'] ?? $assignid ?? 0);
                if (!$instanceid) {
                    throw new \Exception("Queue item {$item->id}: missing instanceid for modulename {$modulename}");
                }
                $userid   = (int)($payload['userid'] ?? 0);
                $cmid     = (int)($payload['cmid'] ?? 0);
                $context  = $this->resolve_context($cmid, $courseid);
                $activitiesettings = local_hlai_grading_get_activity_settings($modulename, $instanceid);
                $qualitysetting = $activitiesettings->quality ?? (get_config('local_hlai_grading', 'defaultquality') ?: 'balanced');

                // Build normalized grading payload.
                $question  = $payload['question'] ?? $payload['assignment'] ?? 'Grade this student submission.';
                $student   = $payload['submissiontext'] ?? $payload['submission'] ?? $payload['content'] ?? '';
                if (trim($student) === '') {
                    throw new \Exception(
                        "Queue item {$item->id}: empty student submission" .
                        " for {$modulename} {$instanceid} (cmid {$cmid})"
                    );
                }
                $keytext = trim((string)($payload['keytext'] ?? $payload['answerkey'] ?? $payload['graderinfo'] ?? ''));
                $custominstructions = trim((string)($activitiesettings->custominstructions ?? ''));

                $rubricsnapshot = $payload['rubric_snapshot'] ?? null;
                if (!$rubricsnapshot && $modulename === 'assign') {
                    $rubricsnapshot = \local_hlai_grading\rubric_analyzer::get_rubric('assign', $instanceid, $cmid);
                }
                $rubricjson = $payload['rubric_json'] ?? null;
                if (!$rubricjson && $rubricsnapshot) {
                    $rubricjson = \local_hlai_grading\rubric_analyzer::rubric_to_json($rubricsnapshot);
                }

                $analysis = null;
                $providerlabel = 'keymatch';
                $grademethod = 'keymatch';
                $rubriccriteria = [];
                $rubricanalysisjson = null;
                $ai = null;
                \local_hlai_grading\event\grading_started::create([
                    'objectid' => $item->id,
                    'context' => $context,
                    'courseid' => $courseid,
                    'relateduserid' => $userid ?: null,
                    'other' => [
                        'modulename' => $payload['modulename'] ?? 'assign',
                        'instanceid' => $instanceid,
                    ],
                ])->trigger();

                $maxgrade = 0.0;
                if ($modulename === 'quiz') {
                    $maxgrade = (float)($payload['maxmark'] ?? 0);
                } else if ($modulename === 'assign') {
                    $assignrecord = $DB->get_record('assign', ['id' => $instanceid], 'id,grade', MUST_EXIST);
                    $maxgrade = (float)($assignrecord->grade ?? 0);
                }
                if ($maxgrade <= 0) {
                    $maxgrade = (float)($payload['maxgrade'] ?? 100);
                }

                if ($rubricjson) {
                    try {
                        $gatewaypayload = [
                            'module' => $modulename,
                            'instanceid' => $instanceid,
                            'courseid' => $courseid,
                            'question' => $question,
                            'submission' => $student,
                            'rubric_json' => $rubricjson,
                            'custom_instructions' => $custominstructions,
                            'answer_key' => $keytext,
                        ];
                        $airesponse = $this->request_ai_grade($gatewaypayload, $qualitysetting);
                        $providerlabel = $airesponse['provider'] ?? 'gateway';
                        $analysis = $this->normalize_ai_response($airesponse['content'] ?? '');
                        if (!empty($analysis['criteria']) && is_array($analysis['criteria'])) {
                            if ($rubricsnapshot) {
                                $mapped = \local_hlai_grading\rubric_analyzer::map_scores_to_rubric($analysis, $rubricsnapshot);
                                if (!empty($mapped['criteria'])) {
                                    $rubriccriteria = $mapped['criteria'];
                                    $rubricanalysisjson = json_encode(
                                        $rubriccriteria,
                                        JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
                                    );
                                    $rubricmax = (float)($mapped['max_score'] ?? ($rubricsnapshot['maxscore'] ?? 0));
                                    $rubricscore = (float)($mapped['calculated_score'] ?? 0);
                                    if ($rubricmax > 0 && $maxgrade > 0) {
                                        $grade = ($rubricscore / $rubricmax) * $maxgrade;
                                    } else {
                                        $grade = $rubricscore;
                                    }
                                    $ai = [
                                        'score' => $grade,
                                        'max_score' => $maxgrade > 0 ? $maxgrade : $rubricmax,
                                        'feedback' => (string)($analysis['feedback'] ?? ''),
                                        'criteria' => $rubriccriteria,
                                        'raw' => $analysis,
                                    ];
                                    $grademethod = $rubricsnapshot['method'] ?? 'rubric';
                                }
                            } else {
                                $rubricscore = 0.0;
                                $rubricmax = 0.0;
                                foreach ($analysis['criteria'] as $criterion) {
                                    $rubricscore += (float)($criterion['score'] ?? 0);
                                    $rubricmax += (float)($criterion['max_score'] ?? 0);
                                }
                                if ($rubricmax > 0 && $maxgrade > 0) {
                                    $grade = ($rubricscore / $rubricmax) * $maxgrade;
                                } else {
                                    $grade = $rubricscore;
                                }
                                $ai = [
                                    'score' => $grade,
                                    'max_score' => $maxgrade > 0 ? $maxgrade : $rubricmax,
                                    'feedback' => (string)($analysis['feedback'] ?? ''),
                                    'criteria' => $analysis['criteria'],
                                    'raw' => $analysis,
                                ];
                                $grademethod = 'rubric';
                            }
                        }
                    } catch (\Throwable $e) {
                        debugging('[hlai_grading] Rubric grading failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
                        $ai = null;
                    }
                }

                if (!$ai) {
                    if ($keytext === '') {
                        throw new \Exception("Queue item {$item->id}: missing answer key for {$modulename} {$instanceid}");
                    }

                    $analysis = similarity::analyze($keytext, $student);
                    if (empty($analysis['key_terms_count'])) {
                        throw new \Exception(
                            "Queue item {$item->id}: answer key contains no usable terms" .
                            " for {$modulename} {$instanceid}"
                        );
                    }
                    $providerlabel = $analysis['method'] ?? 'keymatch';

                    $scorepercent = (float)$analysis['final_percent'];
                    $grade = ($scorepercent / 100) * $maxgrade;
                    $ai = [
                        'score' => $grade,
                        'max_score' => $maxgrade,
                        'feedback' => $analysis['reasoning'],
                        'criteria' => [],
                        'raw' => ['similarity' => $analysis],
                    ];
                }

                // Store grading result in hlai_grading_results (draft storage).
                $result = new \stdClass();
                $result->queueid      = $item->id;
                $result->modulename   = $payload['modulename'] ?? 'assign';
                $result->instanceid   = $instanceid;
                $submissionid = $payload['submissionid'] ?? null;
                if (!$submissionid && $result->modulename === 'assign' && $assignid && $userid) {
                    $submissionid = $this->find_submission_id($assignid, $userid);
                }
                $result->submissionid = $submissionid;
                $result->attemptid    = $payload['attemptid'] ?? null;
                $result->questionid   = $payload['questionid'] ?? null;
                $result->slot         = $payload['slot'] ?? null;
                $result->userid       = $userid;
                $result->grade        = $ai['score'] ?? null;
                $result->maxgrade     = $ai['max_score'] ?? 100;
                $rubricversion = null;
                if (is_array($rubricsnapshot)) {
                    if (!empty($rubricsnapshot['version'])) {
                        $rubricversion = (int)$rubricsnapshot['version'];
                    } else if (!empty($rubricsnapshot['definitionid'])) {
                        $rubricversion = (int)$rubricsnapshot['definitionid'];
                    }
                }
                $result->rubric_version_id = $rubricversion;

                $result->grademethod  = $grademethod;
                $result->reasoning    = $ai['feedback'] ?? '';
                $result->rubric_analysis = $rubricanalysisjson;
                $strengths = [];
                $improvements = [];
                if (is_array($analysis) && !empty($analysis['matched_terms']) && is_array($analysis['matched_terms'])) {
                    $strengths = similarity::format_term_list($analysis['matched_terms'], '');
                }
                if (is_array($analysis) && !empty($analysis['missing_terms']) && is_array($analysis['missing_terms'])) {
                    $improvements = similarity::format_term_list($analysis['missing_terms'], '');
                }
                $result->strengths_json = $this->encode_optional_json($strengths);
                $result->improvements_json = $this->encode_optional_json($improvements);
                $result->examples_json = null;
                $result->confidence   = (is_array($analysis) && isset($analysis['final_percent']))
                    ? round((float)$analysis['final_percent'], 2)
                    : null;
                if ($grademethod === 'keymatch') {
                    $result->model = ($providerlabel === 'semantic') ? 'gateway:semantic' : 'local:overlap';
                } else {
                    $result->model = 'gateway:rubric';
                }
                $result->quality      = $qualitysetting;
                $result->tokens_used  = null;
                $result->prompttokens = null;
                $result->completiontokens = null;
                $result->processing_time = null;
                $result->status       = 'draft'; // SPEC: Start as draft.
                $result->reviewed     = 0;
                $result->reviewer_id  = null;
                $result->timecreated  = time();
                $result->timereviewed = null;

                $resultid = $DB->insert_record('local_hlai_grading_results', $result);
                if (!empty($rubriccriteria)) {
                    try {
                        $this->store_rubric_scores($resultid, $rubriccriteria, $rubricsnapshot);
                    } catch (\Throwable $e) {
                        debugging('[hlai_grading] Failed to store rubric scores: ' . $e->getMessage(), DEBUG_DEVELOPER);
                    }
                }

                \local_hlai_grading\event\grading_completed::create([
                    'objectid' => $resultid,
                    'context' => $context,
                    'courseid' => $courseid,
                    'relateduserid' => $userid ?: null,
                    'other' => [
                        'queueid' => $item->id,
                        'grade' => $result->grade,
                        'status' => $result->status,
                    ],
                ])->trigger();

                // Auto-release logic.
                if (!empty($activitiesettings->autorelease)) {
                    $result->id = $resultid;
                    $this->auto_release_result($result, $courseid, $ai, $pushfeedback, $context);

                    $item->status  = 'done';
                    $item->timecompleted = time();
                    $item->payload = json_encode([
                        'request'  => $payload,
                        'analysis' => $analysis,
                        'resultid' => $resultid,
                        'provider' => $providerlabel,
                    ]);
                    $DB->update_record('local_hlai_grading_queue', $item);

                    \local_hlai_grading\local\workflow_manager::log_action(
                        $item->id,
                        $resultid,
                        'autoreleased',
                        0,
                        'Auto-release enabled'
                    );

                    continue;
                }

                // SPEC: Optionally push to gradebook after human review (legacy)
                // But in strict spec mode, this should only happen on teacher release.
                if ($pushgrades && $courseid && $assignid && $userid) {
                    $this->push_grade($courseid, $assignid, $userid, $ai, $pushfeedback);
                }

                // Mark queue as done.
                $item->status  = 'done';
                $item->timecompleted = time();
                $item->payload = json_encode([
                    'request'  => $payload,
                    'analysis' => $analysis,
                    'resultid' => $resultid,
                    'provider' => $providerlabel,
                ]);
                $DB->update_record('local_hlai_grading_queue', $item);

                // SPEC: Set workflow to 'inreview' so teacher can see it.
                if ($assignid && $userid) {
                    \local_hlai_grading\local\workflow_manager::set_assign_state($assignid, $userid, 'inreview');
                }

                // SPEC: Log the action.
                \local_hlai_grading\local\workflow_manager::log_action(
                    $item->id,
                    $resultid,
                    'processed',
                    0,
                    'Key-match grading completed and stored as draft'
                );
            } catch (\Throwable $e) {
                // SPEC: Retry logic with exponential backoff.
                $item->retries = ($item->retries ?? 0) + 1;
                $maxretries = 3; // SPEC requirement.

                $debuginfo = null;
                if (method_exists($e, 'getDebugInfo')) {
                    $debuginfo = $e->getDebugInfo();
                } else if (property_exists($e, 'debuginfo')) {
                    $debuginfo = $e->debuginfo;
                }

                $errorpayload = [
                    'request' => $payload,
                    'error' => $e->getMessage(),
                    'retries' => $item->retries,
                ];
                if (!empty($debuginfo)) {
                    $errorpayload['debuginfo'] = $debuginfo;
                }

                if ($item->retries >= $maxretries) {
                    // Max retries reached, mark as failed.
                    $item->status  = 'failed';
                    $errorpayload['trace'] = $e->getTraceAsString();
                } else {
                    // Retry with exponential backoff: 5min, 10min, 15min.
                    $item->status  = 'pending';
                    $item->nextrun = time() + (300 * $item->retries); // 300s = 5 minutes
                }

                $item->payload = json_encode($errorpayload);
                $DB->update_record('local_hlai_grading_queue', $item);

                // SPEC: Log failure.
                \local_hlai_grading\local\workflow_manager::log_action(
                    $item->id,
                    null,
                    $item->status === 'failed' ? 'failed' : 'retry',
                    0,
                    sprintf('Error (attempt %d/%d): %s', $item->retries, $maxretries, $e->getMessage())
                );

                \local_hlai_grading\event\grading_failed::create([
                    'objectid' => $item->id,
                    'context' => $context ?? \context_system::instance(),
                    'courseid' => $courseid,
                    'relateduserid' => $userid ?: null,
                    'other' => [
                        'message' => $e->getMessage(),
                        'retries' => $item->retries,
                    ],
                ])->trigger();
            }
        }
    }

    /**
     * Try to make sense of whatever AI Hub returned.
     *
     * @param mixed $response Response.
     * @return array The result array.
     */
    protected function normalize_ai_response($response): array {
        // Hub might give us an object, array or raw JSON string.
        if (is_string($response)) {
            $decoded = json_decode($response, true);
        } else if (is_object($response) && property_exists($response, 'content')) {
            // The AI Hub response object contains content and provider fields.
            $content = $response->content;

            // The content might have JSON wrapped in markdown code blocks.
            // Strip opening and closing fences if present.
            $content = preg_replace('/^\x60\x60\x60json\s*/m', '', $content);
            $content = preg_replace('/\s*\x60\x60\x60$/m', '', $content);
            $content = trim($content);

            $decoded = json_decode($content, true);
        } else {
            $decoded = (array)$response;
        }

        if (!is_array($decoded)) {
            $decoded = [];
        }

        // Fallbacks.
        $score    = $decoded['score'] ?? $decoded['grade'] ?? 0;
        $maxscore = $decoded['max_score'] ?? $decoded['maxscore'] ?? 100;
        $feedback = $decoded['feedback'] ?? $decoded['comment'] ?? '';
        $criteria = $decoded['criteria'] ?? [];

        return [
            'score'     => (float)$score,
            'max_score' => (float)$maxscore,
            'feedback'  => (string)$feedback,
            'criteria'  => $criteria,
            'raw'       => $decoded,
        ];
    }

    /**
     * Push the grade into Moodle's gradebook (or use user-provided pusher).
     *
     * @param int   $courseid Courseid.
     * @param int   $assignid Assignid.
     * @param int   $userid Userid.
     * @param array $ai Ai.
     * @param bool  $pushfeedback Pushfeedback.
     * @return void
     */
    protected function push_grade(int $courseid, int $assignid, int $userid, array $ai, bool $pushfeedback): void {
        global $CFG;

        // If user created a dedicated pusher class, let's use it.
        if (class_exists('\local_hlai_grading\local\grade_pusher')) {
            $pusher = new \local_hlai_grading\local\grade_pusher();
            $pusher->push_to_assignment($courseid, $assignid, $userid, $ai, $pushfeedback);
            return;
        }

        // Fallback: Moodle grade_update.
        require_once($CFG->libdir . '/gradelib.php');

        // Find grade item to get grademax.
        $gi = \grade_item::fetch([
            'courseid'     => $courseid,
            'itemtype'     => 'mod',
            'itemmodule'   => 'assign',
            'iteminstance' => $assignid,
            'itemnumber'   => 0,
        ]);

        $max = $gi ? (float)$gi->grademax : 100.0;

        $score = (float)($ai['score'] ?? 0);
        $maxai = (float)($ai['max_score'] ?? 0);

        // If AI said "score 7 out of 10", scale to Moodle max.
        if ($maxai > 0) {
            $rawgrade = ($score / $maxai) * $max;
        } else {
            // If AI gave 0..1, scale; if gave 0..100, just clamp.
            $rawgrade = ($score <= 1.0) ? $score * $max : min($score, $max);
        }

        $feedback = $pushfeedback ? ($ai['feedback'] ?? '') : '';

        $grades = [
            'userid'         => $userid,
            'rawgrade'       => $rawgrade,
            'feedback'       => $feedback,
            'feedbackformat' => FORMAT_MOODLE,
        ];

        grade_update('mod/assign', $courseid, 'mod', 'assign', $assignid, 0, $grades);
    }

    /**
     * Automatically release a draft result.
     *
     * @param \stdClass $result Newly created result record (with ->id set)
     * @param int $courseid Course ID context
     * @param array $ai Normalized AI payload
     * @param bool $pushfeedback Whether feedback should be pushed with grades
     * @param \context $context Moodle context for event logging
     * @return void
     */
    protected function auto_release_result(
        \stdClass $result,
        int $courseid,
        array $ai,
        bool $pushfeedback,
        \context $context
    ): void {
        global $DB, $CFG;

        $now = time();
        $updaterecord = (object)[
            'id' => $result->id,
            'status' => 'released',
            'reviewed' => 1,
            'reviewer_id' => 0,
            'timereviewed' => $now,
        ];
        $DB->update_record('local_hlai_grading_results', $updaterecord);

        if ($result->modulename === 'assign' && $result->instanceid && $result->userid && $courseid) {
            $this->push_grade($courseid, $result->instanceid, $result->userid, $ai, $pushfeedback);
            \local_hlai_grading\local\workflow_manager::set_assign_state($result->instanceid, $result->userid, 'released');
            if (!empty($ai['criteria']) && is_array($ai['criteria'])) {
                $cm = get_coursemodule_from_instance('assign', $result->instanceid, $courseid, false, IGNORE_MISSING);
                if ($cm) {
                    require_once($CFG->dirroot . '/local/hlai_grading/classes/local/rubric_sync.php');
                    $sync = new \local_hlai_grading\local\rubric_sync();
                    $sync->write_rubric($courseid, $cm->id, $result->userid, $ai['criteria'], $result->reasoning ?? '');
                }
            }
        }

        if ($result->modulename === 'quiz' && !empty($result->attemptid) && !empty($result->slot)) {
            require_once($CFG->dirroot . '/mod/quiz/locallib.php');
            require_once($CFG->dirroot . '/question/engine/lib.php');

            $attempt = $DB->get_record('quiz_attempts', ['id' => $result->attemptid], '*', MUST_EXIST);
            $quiz = $DB->get_record('quiz', ['id' => $result->instanceid], '*', MUST_EXIST);
            $quba = \question_engine::load_questions_usage_by_activity($attempt->uniqueid);
            $slot = (int)$result->slot;

            if ($slot) {
                $qa = $quba->get_question_attempt($slot);
                $maxmark = $qa->get_max_mark();
                $mark = (float)($result->grade ?? 0);
                $maxgrade = (float)($result->maxgrade ?? $maxmark);
                if ($maxgrade > 0 && abs($maxgrade - $maxmark) > 0.0001) {
                    $mark = ($mark / $maxgrade) * $maxmark;
                }
                $mark = min($mark, $maxmark);
                $comment = $result->reasoning ?? '';

                $quba->manual_grade($slot, $comment, $mark, FORMAT_HTML);
                \question_engine::save_questions_usage_by_activity($quba);

                $attempt->sumgrades = $quba->get_total_mark();
                $attempt->timemodified = time();
                $DB->update_record('quiz_attempts', $attempt);

                quiz_save_best_grade($quiz, $attempt->userid);
                quiz_update_grades($quiz, $attempt->userid);
            }

            $quality = $result->quality ?: (get_config('local_hlai_grading', 'defaultquality') ?: 'balanced');
            try {
                \local_hlai_grading\local\quiz_summary::maybe_generate_for_attempt(
                    (int)$result->attemptid,
                    (int)$quiz->id,
                    (int)$result->userid,
                    $quality
                );
            } catch (\Throwable $e) {
                debugging('Quiz summary generation failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }

        $eventdata = [
            'objectid' => $result->id,
            'context' => $context,
            'courseid' => $courseid,
            'relateduserid' => $result->userid,
            'other' => [
                'queueid' => $result->queueid,
                'action' => 'autoreleased',
                'grade' => $result->grade,
            ],
        ];
        \local_hlai_grading\event\grade_reviewed::create($eventdata)->trigger();
                \local_hlai_grading\event\grade_released::create($eventdata)->trigger();
    }

    /**
     * Resolve best-guess context for logging/events.
     *
     * @param int|null $cmid Course module ID.
     * @param int|null $courseid Course ID.
     * @return \context The resolved context.
     */
    protected function resolve_context(?int $cmid, ?int $courseid): \context {
        if ($cmid) {
            return \context_module::instance($cmid);
        }
        if ($courseid) {
            return \context_course::instance($courseid);
        }
        return \context_system::instance();
    }

    /**
     * Attempt to locate the latest submission id for an assignment/user pair.
     *
     * @param int $assignid Assignment ID.
     * @param int $userid User ID.
     * @return int|null The submission ID or null.
     */
    protected function find_submission_id(int $assignid, int $userid): ?int {
        global $DB;

        $records = $DB->get_records(
            'assign_submission',
            ['assignment' => $assignid, 'userid' => $userid],
            'attemptnumber DESC, id DESC',
            'id',
            0,
            1
        );

        if (!empty($records)) {
            $record = reset($records);
            return (int)$record->id;
        }

        return null;
    }

    /**
     * Normalize a string list (strengths, improvements) from AI data.
     *
     * @param array $ai Ai.
     * @param string $key Key.
     * @return array The result array.
     */
    protected function extract_string_list(array $ai, string $key): array {
        $source = $ai[$key] ?? ($ai['raw'][$key] ?? []);
        if (!is_array($source)) {
            return [];
        }
        $list = [];
        foreach ($source as $item) {
            $value = trim((string)$item);
            if ($value !== '') {
                $list[] = $value;
            }
        }
        return $list;
    }

    /**
     * Normalize highlighted example data if present.
     *
     * @param array $ai Ai.
     * @return array The result array.
     */
    protected function extract_examples(array $ai): array {
        $source = $ai['highlighted_examples'] ?? ($ai['raw']['highlighted_examples'] ?? []);
        if (!is_array($source)) {
            return [];
        }
        $examples = [];
        foreach ($source as $example) {
            if (!is_array($example)) {
                continue;
            }
            $label = trim((string)($example['label'] ?? ''));
            $text = trim((string)($example['text'] ?? ''));
            $comment = trim((string)($example['comment'] ?? ''));
            $type = trim((string)($example['type'] ?? ''));
            if ($label === '' && $text === '' && $comment === '') {
                continue;
            }
            $examples[] = [
                'label' => $label,
                'text' => $text,
                'comment' => $comment,
                'type' => $type,
            ];
        }
        return $examples;
    }

    /**
     * Encode a list to JSON or return null when empty.
     *
     * @param array $data Data.
     * @return string|null The result.
     */
    protected function encode_optional_json(array $data): ?string {
        if (empty($data)) {
            return null;
        }
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * Request a rubric-aware grade from the configured gateway.
     *
     * @param array $payload Payload.
     * @param string $quality Quality.
     * @return array{provider:string,content:mixed} The result.
     */
    protected function request_ai_grade(array $payload, string $quality): array {
        return \local_hlai_grading\local\gateway_client::grade('grade_rubric', $payload, $quality);
    }

    /**
     * Persist per-criterion rubric scores for dashboard analytics.
     *
     * @param int $resultid Resultid.
     * @param array $criteria Criteria.
     * @param array|null $rubricsnapshot Rubricsnapshot.
     * @return void
     */
    protected function store_rubric_scores(int $resultid, array $criteria, ?array $rubricsnapshot): void {
        global $DB;

        if (empty($criteria)) {
            return;
        }

        $now = time();
        $snapshotcriteria = [];
        if (is_array($rubricsnapshot) && !empty($rubricsnapshot['criteria']) && is_array($rubricsnapshot['criteria'])) {
            $snapshotcriteria = $rubricsnapshot['criteria'];
        }

        foreach ($criteria as $criterion) {
            $criterionid = (int)($criterion['criterionid'] ?? 0);
            $record = (object)[
                'resultid' => $resultid,
                'criterionid' => $criterionid ?: 0,
                'criterionname' => $criterion['name'] ?? null,
                'levelid' => null,
                'levelname' => null,
                'score' => $criterion['score'] ?? null,
                'maxscore' => $criterion['max_score'] ?? null,
                'reasoning' => $criterion['feedback'] ?? '',
                'timecreated' => $now,
            ];

            if ($criterionid && !empty($snapshotcriteria[$criterionid]['levels'])) {
                $match = $this->match_rubric_level(
                    $snapshotcriteria[$criterionid]['levels'],
                    (float)($criterion['score'] ?? 0)
                );
                if ($match) {
                    $record->levelid = !empty($match['id']) ? (int)$match['id'] : null;
                    $record->levelname = $match['label'] ?? null;
                }
            }

            $DB->insert_record('local_hlai_grading_rubric_scores', $record);
        }
    }

    /**
     * Select the rubric level that best matches a score.
     *
     * @param array $levels Levels.
     * @param float $score Score.
     * @return array|null The result.
     */
    protected function match_rubric_level(array $levels, float $score): ?array {
        if (empty($levels)) {
            return null;
        }

        $closest = null;
        $closestdiff = PHP_FLOAT_MAX;

        foreach ($levels as $level) {
            $levelscore = (float)($level['score'] ?? 0);
            if ($levelscore == $score) {
                return $level;
            }
            $diff = abs($levelscore - $score);
            if ($diff < $closestdiff) {
                $closestdiff = $diff;
                $closest = $level;
            }
        }

        return $closest;
    }
}
