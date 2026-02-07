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
 * External function to get AI statuses.
 *
 * @package    local_hlai_grading
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_hlai_grading\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;
use external_multiple_structure;

/**
 * External API for AI grading status.
 */
class get_ai_statuses extends external_api {
    /**
     * Parameter definition for get_ai_statuses.
     *
     * @return external_function_parameters The result.
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module ID'),
        ]);
    }

    /**
     * Get AI grading statuses for all users in an assignment.
     *
     * @param int $cmid Course module ID.
     * @return array The result array.
     */
    public static function execute($cmid) {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid,
        ]);

        $cm = get_coursemodule_from_id('assign', $params['cmid'], 0, false, MUST_EXIST);
        $assignid = $cm->instance;

        $context = \context_module::instance($cm->id);

        if (!has_capability('mod/assign:grade', $context)) {
            $allowed = false;
            if (\core\session\manager::is_loggedinas()) {
                $realuser = \core\session\manager::get_realuser();
                if ($realuser && has_capability('mod/assign:grade', $context, $realuser->id)) {
                    $allowed = true;
                }
            }
            if (!$allowed) {
                require_capability('mod/assign:grade', $context);
            }
        }

        $results = $DB->get_records_sql("
            SELECT r.*
              FROM {local_hlai_grading_results} r
             WHERE r.instanceid = :assignid
        ", ['assignid' => $assignid]);

        $statuses = [];
        foreach ($results as $result) {
            $statuses[$result->userid] = [
                'userid' => $result->userid,
                'exists' => true,
                'status' => $result->status,
                'grade' => format_float($result->grade, 2) . '/' . format_float($result->maxgrade, 2),
                'confidence' => (int)($result->confidence ?? 0),
                'resultid' => (int)$result->id,
            ];
        }

        $pendingqueues = $DB->get_records_sql("
            SELECT *
              FROM {local_hlai_grading_queue}
             WHERE status = :status
               AND component = :component
        ", ['status' => 'pending', 'component' => 'mod_assign']);

        foreach ($pendingqueues as $queue) {
            $payload = json_decode($queue->payload ?? '[]', true) ?? [];
            if ((int)($payload['assignid'] ?? 0) !== (int)$assignid) {
                continue;
            }
            $userid = (int)($payload['userid'] ?? 0);
            if (!$userid || isset($statuses[$userid])) {
                continue;
            }
            $statuses[$userid] = [
                'userid' => $userid,
                'exists' => true,
                'status' => 'pending',
                'grade' => '-',
                'confidence' => 0,
                'resultid' => 0,
            ];
        }

        return array_values($statuses);
    }

    /**
     * Return definition for get_ai_statuses.
     *
     * @return external_multiple_structure The result.
     */
    public static function execute_returns() {
        return new external_multiple_structure(
            new external_single_structure([
                'userid' => new external_value(PARAM_INT, 'User ID'),
                'exists' => new external_value(PARAM_BOOL, 'Whether AI grading exists'),
                'status' => new external_value(PARAM_TEXT, 'AI grading status'),
                'grade' => new external_value(PARAM_TEXT, 'AI grade text'),
                'confidence' => new external_value(PARAM_INT, 'AI confidence score'),
                'resultid' => new external_value(PARAM_INT, 'Result ID'),
            ])
        );
    }
}
