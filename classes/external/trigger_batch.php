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
 * External function to trigger batch grading.
 *
 * @package    local_hlai_grading
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_hlai_grading\external;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/externallib.php');

use context_module;
use external_api;
use external_function_parameters;
use external_multiple_structure;
use external_single_structure;
use external_value;
use local_hlai_grading\local\content_extractor;
use local_hlai_grading\local\queuer;
use local_hlai_grading\local\rate_limiter;
use local_hlai_grading\local\workflow_manager;
use local_hlai_grading\rubric_analyzer;
use moodle_exception;

/**
 * REST handler for the batch grading trigger (Spec §9.3 "POST /grade/batch").
 */
class trigger_batch extends external_api {
    /**
     * Parameters mirror the JSON body from the spec.
     *
     * @return external_function_parameters The result.
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'modulename' => new external_value(
                PARAM_ALPHA,
                'Module type (currently only assign supported)',
                \VALUE_DEFAULT,
                'assign'
            ),
            'instanceid' => new external_value(PARAM_INT, 'Module instance ID (assignment or quiz)'),
            'userids' => new external_multiple_structure(
                new external_value(PARAM_INT, 'Specific user IDs to queue'),
                'Optional list of target user IDs',
                \VALUE_DEFAULT,
                []
            ),
        ]);
    }

    /**
     * Queue submissions for AI grading.
     *
     * @param string $modulename Modulename.
     * @param int $instanceid Instanceid.
     * @param array $userids Userids.
     * @return array The result array.
     */
    public static function execute(string $modulename, int $instanceid, array $userids = []): array {
        global $CFG, $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'modulename' => $modulename,
            'instanceid' => $instanceid,
            'userids' => $userids,
        ]);

        $module = $params['modulename'] ?: 'assign';

        if ($module !== 'assign') {
            throw new moodle_exception('invalidmodule', 'local_hlai_grading', '', s($module));
        }

        require_once($CFG->dirroot . '/mod/assign/locallib.php');

        $assignment = $DB->get_record('assign', ['id' => $params['instanceid']], '*', MUST_EXIST);
        $course = $DB->get_record('course', ['id' => $assignment->course], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('assign', $assignment->id, $assignment->course, false, MUST_EXIST);
        $context = context_module::instance($cm->id);

        self::validate_context($context);
        require_capability('local/hlai_grading:batchgrade', $context);
        require_capability('mod/assign:grade', $context);

        rate_limiter::enforce($USER->id, 'batchgrade');

        $assigninstance = new \assign($context, $cm, $course);

        $targetuserids = $params['userids'] ?? [];
        if (empty($targetuserids)) {
            $targetuserids = $DB->get_fieldset_select(
                'assign_submission',
                'userid',
                'assignment = :assignment AND status = :status',
                [
                    'assignment' => $assignment->id,
                    'status' => 'submitted',
                ]
            );
        }

        $targetuserids = array_values(
            array_unique(
                array_filter(array_map('intval', $targetuserids))
            )
        );

        if (empty($targetuserids)) {
            return [
                'queued' => 0,
                'already_graded' => 0,
                'errors' => [],
            ];
        }

        $rubricsnapshot = rubric_analyzer::get_rubric('assign', $assignment->id, $cm->id);
        $rubricjson = $rubricsnapshot ? rubric_analyzer::rubric_to_json($rubricsnapshot) : null;

        $queued = 0;
        $alreadygraded = 0;
        $errors = [];

        $queuer = new queuer();

        foreach ($targetuserids as $targetuserid) {
            try {
                $submission = $assigninstance->get_user_submission($targetuserid, false);
                if (!$submission || $submission->status !== 'submitted') {
                    continue;
                }

                $exists = $DB->record_exists_select(
                    'local_hlai_grading_results',
                    'modulename = :mod AND instanceid = :instance AND userid = :userid AND status <> :rejected',
                    [
                        'mod' => 'assign',
                        'instance' => $assignment->id,
                        'userid' => $targetuserid,
                        'rejected' => 'rejected',
                    ]
                );
                if ($exists) {
                    $alreadygraded++;
                    continue;
                }

                $extracted = content_extractor::extract_from_assignment($assigninstance, $submission);

                $payload = [
                    'userid' => $targetuserid,
                    'courseid' => $course->id,
                    'cmid' => $cm->id,
                    'assignid' => $assignment->id,
                    'modulename' => 'assign',
                    'instanceid' => $assignment->id,
                    'submissionid' => $submission->id,
                    'submissiontext' => $extracted['text'] ?? '',
                    'assignment' => $assignment->name,
                    'rubric_json' => $rubricjson,
                    'submissionfiles' => $extracted['files'] ?? [],
                ];

                if ($rubricsnapshot) {
                    $payload['rubric_snapshot'] = $rubricsnapshot;
                }

                $queueid = $queuer->queue_submission(
                    $targetuserid,
                    $course->id,
                    $cm->id,
                    'manual_batch',
                    $payload
                );

                workflow_manager::log_action(
                    $queueid,
                    null,
                    'batchqueued',
                    $USER->id,
                    'Batch web service request'
                );

                $queued++;
            } catch (\Throwable $e) {
                $errors[] = [
                    'userid' => $targetuserid,
                    'message' => $e->getMessage(),
                ];
            }
        }

        return [
            'queued' => $queued,
            'already_graded' => $alreadygraded,
            'errors' => array_values($errors),
        ];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure The result.
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'queued' => new external_value(PARAM_INT, 'Number of submissions queued'),
            'already_graded' => new external_value(PARAM_INT, 'Submissions skipped due to existing grades'),
            'errors' => new external_multiple_structure(
                new external_single_structure([
                    'userid' => new external_value(PARAM_INT, 'User ID that failed to queue'),
                    'message' => new external_value(PARAM_TEXT, 'Reason the user could not be queued'),
                ]),
                'Errors encountered while queueing',
                \VALUE_DEFAULT,
                []
            ),
        ]);
    }
}
