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
 * API stats endpoint.
 *
 * @package    local_hlai_grading
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../../../config.php');

require_login();

$context = context_system::instance();
require_capability('local/hlai_grading:viewlogs', $context);

global $DB;

$stats = $DB->get_record_sql(
    "SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending,
        SUM(CASE WHEN status = 'done' THEN 1 ELSE 0 END) AS completed,
        SUM(CASE WHEN status IN ('failed', 'error') THEN 1 ELSE 0 END) AS failed
     FROM {local_hlai_grading_queue}"
);

$processingtime = $DB->get_field_sql(
    "SELECT AVG(timecompleted - timecreated)
       FROM {local_hlai_grading_queue}
      WHERE status = 'done' AND timecompleted IS NOT NULL"
);

$oldestpending = $DB->get_field_sql(
    "SELECT MIN(timecreated)
       FROM {local_hlai_grading_queue}
      WHERE status = 'pending'"
);

$response = [
    'pending' => (int)($stats->pending ?? 0),
    'processing' => 0,
    'completed' => (int)($stats->completed ?? 0),
    'failed' => (int)($stats->failed ?? 0),
    'total' => (int)($stats->total ?? 0),
    'oldest_pending' => $oldestpending ? (int)$oldestpending : null,
    'average_processing_time' => $processingtime ? (float)$processingtime : null,
];

core\session\manager::write_close();

header('Content-Type: application/json');
echo json_encode($response);
exit;
