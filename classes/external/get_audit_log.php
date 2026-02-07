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
 * External function to get audit log.
 *
 * @package    local_hlai_grading
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_hlai_grading\external;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/externallib.php');

use context_system;
use external_api;
use external_function_parameters;
use external_multiple_structure;
use external_single_structure;
use external_value;

/**
 * REST export for the hlai_grading_log audit trail.
 */
class get_audit_log extends external_api {
    /**
     * Define parameters for the execute function.
     *
     * @return external_function_parameters The parameter definition.
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'queueid' => new external_value(PARAM_INT, 'Filter by queue id', \VALUE_DEFAULT, null),
            'resultid' => new external_value(PARAM_INT, 'Filter by result id', \VALUE_DEFAULT, null),
            'userid' => new external_value(PARAM_INT, 'Filter by acting user id', \VALUE_DEFAULT, null),
            'limit' => new external_value(PARAM_INT, 'Max rows (1-200)', \VALUE_DEFAULT, 50),
        ]);
    }

    /**
     * Execute the audit log retrieval.
     *
     * @param int|null $queueid Filter by queue ID.
     * @param int|null $resultid Filter by result ID.
     * @param int|null $userid Filter by user ID.
     * @param int $limit Maximum rows to return.
     * @return array The audit log entries.
     */
    public static function execute($queueid = null, $resultid = null, $userid = null, $limit = 50): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'queueid' => $queueid,
            'resultid' => $resultid,
            'userid' => $userid,
            'limit' => $limit,
        ]);

        $limitnum = min(max(1, (int)$params['limit']), 200);
        $conditions = [];
        $sqlparams = [];
        if (!empty($params['queueid'])) {
            $conditions[] = 'queueid = :queueid';
            $sqlparams['queueid'] = $params['queueid'];
        }
        if (!empty($params['resultid'])) {
            $conditions[] = 'resultid = :resultid';
            $sqlparams['resultid'] = $params['resultid'];
        }
        if (!empty($params['userid'])) {
            $conditions[] = 'userid = :userid';
            $sqlparams['userid'] = $params['userid'];
        }
        $where = $conditions ? ('WHERE ' . implode(' AND ', $conditions)) : '';

        $sql = "SELECT id, queueid, resultid, action, userid, details, timecreated
                FROM {local_hlai_grading_log}
                $where
                ORDER BY timecreated DESC
                LIMIT $limitnum";
        $records = $DB->get_records_sql($sql, $sqlparams);

        self::validate_context(context_system::instance());
        require_capability('local/hlai_grading:releasegrades', context_system::instance());

        $entries = [];
        foreach ($records as $record) {
            $entries[] = [
                'id' => (int)$record->id,
                'queueid' => (int)$record->queueid,
                'resultid' => (int)$record->resultid,
                'action' => $record->action,
                'userid' => (int)$record->userid,
                'details' => $record->details ?? '',
                'timecreated' => (int)$record->timecreated,
            ];
        }

        return $entries;
    }

    /**
     * Define the return structure.
     *
     * @return external_multiple_structure The return definition.
     */
    public static function execute_returns(): external_multiple_structure {
        return new external_multiple_structure(new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Log ID'),
            'queueid' => new external_value(PARAM_INT, 'Queue ID'),
            'resultid' => new external_value(PARAM_INT, 'Result ID'),
            'action' => new external_value(PARAM_TEXT, 'Action name'),
            'userid' => new external_value(PARAM_INT, 'Acting user ID'),
            'details' => new external_value(PARAM_RAW, 'Details'),
            'timecreated' => new external_value(PARAM_INT, 'Timestamp'),
        ]));
    }
}
