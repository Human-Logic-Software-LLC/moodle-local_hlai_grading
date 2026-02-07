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
 * External function to get queue statistics.
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
use external_single_structure;
use external_value;


/**
 * REST handler for queue statistics (Spec §9.3 "Get Queue Stats").
 */
class get_queue_stats extends external_api {
    /**
     * No parameters are required for queue stats.
     *
     * @return external_function_parameters The result.
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    /**
     * Return aggregate queue metrics for monitoring dashboards.
     *
     * @return array The result array.
     */
    public static function execute(): array {
        global $DB;

        self::validate_parameters(self::execute_parameters(), []);

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/hlai_grading:viewlogs', $context);

        $stats = $DB->get_record_sql(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending,
                    SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) AS processing,
                    SUM(CASE WHEN status = 'done' THEN 1 ELSE 0 END) AS completed,
                    SUM(CASE WHEN status IN ('failed', 'error') THEN 1 ELSE 0 END) AS failed
               FROM {local_hlai_grading_queue}"
        );

        $oldestpending = $DB->get_field_sql(
            "SELECT MIN(timecreated)
               FROM {local_hlai_grading_queue}
              WHERE status = 'pending'"
        );

        $averageprocessing = $DB->get_field_sql(
            "SELECT AVG(timecompleted - timecreated)
               FROM {local_hlai_grading_queue}
              WHERE status = 'done'
                AND timecompleted IS NOT NULL"
        );

        $response = [
            'pending' => (int)($stats->pending ?? 0),
            'processing' => (int)($stats->processing ?? 0),
            'completed' => (int)($stats->completed ?? 0),
            'failed' => (int)($stats->failed ?? 0),
            'total' => (int)($stats->total ?? 0),
        ];

        if (!empty($oldestpending)) {
            $response['oldest_pending'] = (int)$oldestpending;
        }
        if (!empty($averageprocessing) && (float)$averageprocessing > 0) {
            $response['average_processing_time'] = (float)$averageprocessing;
        }

        return $response;
    }

    /**
     * Define return structure.
     *
     * @return external_single_structure The result.
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'pending' => new external_value(PARAM_INT, 'Pending queue items'),
            'processing' => new external_value(PARAM_INT, 'Items currently processing'),
            'completed' => new external_value(PARAM_INT, 'Completed queue items'),
            'failed' => new external_value(PARAM_INT, 'Failed queue items'),
            'total' => new external_value(PARAM_INT, 'Total queue items tracked'),
            'oldest_pending' => new external_value(
                PARAM_INT,
                'Unix timestamp for the oldest pending item',
                \VALUE_OPTIONAL
            ), 'average_processing_time' => new external_value(
                PARAM_FLOAT,
                'Average seconds from queue to completion',
                \VALUE_OPTIONAL
            ),
        ]);
    }
}
