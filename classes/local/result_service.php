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
 * Result service class.
 *
 * @package    local_hlai_grading
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_hlai_grading\local;

use context_module;
use local_hlai_grading\local\grade_pusher;
use local_hlai_grading\local\quiz_summary;
use local_hlai_grading\local\rubric_sync;
use local_hlai_grading\local\workflow_manager;
use moodle_exception;
use question_engine;
use stdClass;

/**
 * Handles release/reject operations outside the UI.
 */
class result_service {
    /**
     * Load all context data needed to manage an AI result.
     *
     * @param int $resultid Result ID
     * @return array Context data bundle
     */
    public static function get_result_context(int $resultid): array {
        global $DB, $CFG;

        $result = $DB->get_record('local_hlai_grading_results', ['id' => $resultid], '*', MUST_EXIST);
        $queue = $DB->get_record('local_hlai_grading_queue', ['id' => $result->queueid], '*', IGNORE_MISSING);
        if (!$queue) {
            $queue = (object)[
                'id' => $result->queueid,
                'submissionid' => $result->submissionid ?? 0,
                'attemptid' => $result->attemptid ?? 0,
                'questionid' => $result->questionid ?? 0,
                'payload' => null,
            ];
        }
        $modulename = $result->modulename ?? 'assign';
        $assigninstance = null;

        switch ($modulename) {
            case 'assign':
                require_once($CFG->dirroot . '/mod/assign/locallib.php');
                $activity = $DB->get_record('assign', ['id' => $result->instanceid], '*', MUST_EXIST);
                $course = $DB->get_record('course', ['id' => $activity->course], '*', MUST_EXIST);
                $cm = get_coursemodule_from_instance('assign', $activity->id, $course->id, false, MUST_EXIST);
                $context = context_module::instance($cm->id);
                $assigninstance = new \assign($context, $cm, $course);
                break;
            case 'quiz':
                require_once($CFG->dirroot . '/mod/quiz/locallib.php');
                require_once($CFG->dirroot . '/question/engine/lib.php');
                $activity = $DB->get_record('quiz', ['id' => $result->instanceid], '*', MUST_EXIST);
                $course = $DB->get_record('course', ['id' => $activity->course], '*', MUST_EXIST);
                $cm = get_coursemodule_from_instance('quiz', $activity->id, $course->id, false, MUST_EXIST);
                $context = context_module::instance($cm->id);
                break;
            default:
                throw new moodle_exception('unsupportedmod', 'local_hlai_grading', '', s($modulename));
        }

        return [
            'result' => $result,
            'queue' => $queue,
            'course' => $course,
            'activity' => $activity,
            'cm' => $cm,
            'context' => $context,
            'assigninstance' => $assigninstance,
            'modulename' => $modulename,
        ];
    }

    /**
     * Release the AI grade to the learner.
     *
     * @param array $data Context data from get_result_context()
     * @param int $reviewerid Acting user ID
     * @return array Result payload
     */
    public static function release(array $data, int $reviewerid): array {
        global $DB, $CFG;

        $result = $data['result'];
        $queue = $data['queue'];
        $course = $data['course'];
        $cm = $data['cm'];
        $context = $data['context'];
        $assigninstance = $data['assigninstance'];
        $modulename = $data['modulename'];
        $activity = $data['activity'];
        $rubriccriteria = [];
        if (!empty($result->rubric_analysis)) {
            $decoded = json_decode($result->rubric_analysis, true);
            if (is_array($decoded)) {
                $rubriccriteria = $decoded;
            }
        }

        if ($modulename === 'assign' && $assigninstance) {
            $ai = [
                'score' => (float)$result->grade,
                'max_score' => (float)($result->maxgrade ?? $assigninstance->get_instance()->grade),
                'feedback' => $result->reasoning ?? '',
                'criteria' => $rubriccriteria,
                'raw' => ['criteria' => $rubriccriteria],
            ];

            $pushed = false;
            if (class_exists('\local_hlai_grading\local\grade_pusher')) {
                $pusher = new grade_pusher();
                $pushed = $pusher->push_to_assignment($course->id, $activity->id, $result->userid, $ai, true);
            }

            if ($pushed && !empty($rubriccriteria)) {
                require_once($CFG->dirroot . '/local/hlai_grading/classes/local/rubric_sync.php');
                $sync = new rubric_sync();
                $sync->write_rubric($course->id, $cm->id, $result->userid, $rubriccriteria, $result->reasoning ?? '');
            }

            if (!$pushed) {
                require_once($CFG->dirroot . '/grade/grading/lib.php');
                $instance = $assigninstance->get_instance();
                $attemptnumber = 0;
                $subid = $result->submissionid ?? $queue->submissionid ?? 0;
                if (!empty($subid)) {
                    $submission = $DB->get_record('assign_submission', ['id' => $subid], 'id,attemptnumber');
                    if ($submission) {
                        $attemptnumber = (int)$submission->attemptnumber;
                    }
                }

                $formdata = new stdClass();
                $formdata->grade = $result->grade;
                $formdata->attemptnumber = $attemptnumber;
                $formdata->sendstudentnotifications = false;

                if (!empty($instance->markingworkflow)) {
                    $formdata->workflowstate = ASSIGN_MARKING_WORKFLOW_STATE_RELEASED;
                    $formdata->allocatedmarker = 0;
                }

                if (!empty($result->reasoning)) {
                    $formdata->assignfeedbackcomments_editor = [
                        'text' => $result->reasoning, 'format' => FORMAT_HTML,
                    ];
                }

                $assigninstance->save_grade($result->userid, $formdata);
                assign_update_grades($instance, $result->userid);

                if (!empty($rubriccriteria)) {
                    require_once($CFG->dirroot . '/local/hlai_grading/classes/local/rubric_sync.php');
                    $sync = new rubric_sync();
                    $sync->write_rubric($course->id, $cm->id, $result->userid, $rubriccriteria, $result->reasoning ?? '');
                }
            }

            workflow_manager::set_assign_state($activity->id, $result->userid, 'released');
        } else if ($modulename === 'quiz') {
            $attempt = $DB->get_record('quiz_attempts', ['id' => $result->attemptid], '*', MUST_EXIST);
            $quba = question_engine::load_questions_usage_by_activity($attempt->uniqueid);
            $slot = (int)($result->slot ?? 0);
            if (!$slot) {
                throw new moodle_exception('invalidslot', 'local_hlai_grading');
            }
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
            question_engine::save_questions_usage_by_activity($quba);

            $attempt->sumgrades = $quba->get_total_mark();
            $attempt->timemodified = time();
            $DB->update_record('quiz_attempts', $attempt);

            quiz_save_best_grade($activity, $attempt->userid);
            quiz_update_grades($activity, $attempt->userid);
        }

        $result->status = 'released';
        $result->reviewed = 1;
        $result->reviewer_id = $reviewerid;
        $result->timereviewed = time();
        $DB->update_record('local_hlai_grading_results', $result);

        if ($modulename === 'quiz' && !empty($result->attemptid)) {
            $quality = $result->quality ?: (get_config('local_hlai_grading', 'defaultquality') ?: 'balanced');
            try {
                quiz_summary::maybe_generate_for_attempt(
                    (int)$result->attemptid,
                    (int)$activity->id,
                    (int)$result->userid,
                    $quality
                );
            } catch (\Throwable $e) {
                debugging('Quiz summary generation failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }

        workflow_manager::log_action(
            $result->queueid,
            $result->id,
            'released',
            $reviewerid,
            'Teacher released AI grade to student'
        );

        $eventdata = [
            'objectid' => $result->id,
            'context' => $context,
            'courseid' => $course->id,
            'relateduserid' => $result->userid,
            'other' => [
                'queueid' => $result->queueid,
                'action' => 'released',
                'grade' => $result->grade,
            ],
        ];
        \local_hlai_grading\event\grade_reviewed::create($eventdata)->trigger();
        \local_hlai_grading\event\grade_released::create($eventdata)->trigger();

        return [
            'resultid' => $result->id, 'status' => $result->status, 'timereviewed' => $result->timereviewed,
        ];
    }

    /**
     * Reject the AI grade so instructors can grade manually.
     *
     * @param array $data Context data from get_result_context()
     * @param int $reviewerid Acting user ID
     * @param string|null $details Optional details for the audit log
     * @return array Result payload
     */
    public static function reject(array $data, int $reviewerid, ?string $details = null): array {
        global $DB;

        $result = $data['result'];
        $course = $data['course'];
        $context = $data['context'];

        $result->status = 'rejected';
        $result->reviewed = 1;
        $result->reviewer_id = $reviewerid;
        $result->timereviewed = time();
        $DB->update_record('local_hlai_grading_results', $result);

        if ($result->modulename === 'quiz' && !empty($result->attemptid)) {
            $quality = $result->quality ?: (get_config('local_hlai_grading', 'defaultquality') ?: 'balanced');
            try {
                quiz_summary::maybe_generate_for_attempt(
                    (int)$result->attemptid,
                    (int)$result->instanceid,
                    (int)$result->userid,
                    $quality
                );
            } catch (\Throwable $e) {
                debugging('Quiz summary generation failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }

        workflow_manager::log_action(
            $result->queueid,
            $result->id,
            'rejected',
            $reviewerid,
            $details ?? 'Teacher rejected AI grade, will grade manually'
        );

        $eventdata = [
            'objectid' => $result->id,
            'context' => $context,
            'courseid' => $course->id,
            'relateduserid' => $result->userid,
            'other' => [
                'queueid' => $result->queueid,
                'action' => 'rejected',
                'grade' => $result->grade,
            ],
        ];
        \local_hlai_grading\event\grade_reviewed::create($eventdata)->trigger();

        return [
            'resultid' => $result->id, 'status' => $result->status, 'timereviewed' => $result->timereviewed,
        ];
    }
}
