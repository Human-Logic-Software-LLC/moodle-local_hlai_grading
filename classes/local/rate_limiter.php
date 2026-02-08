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
 * Rate limiting utilities.
 *
 * @package    local_hlai_grading
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_hlai_grading\local;

/**
 * Rate_limiter class.
 */
class rate_limiter {
    /**
     * Ensure an action has not exceeded the configured threshold.
     *
     * @param int $userid Userid.
     * @param string $action Action.
     * @param int $limit Limit.
     * @param int $windowseconds Windowseconds.
     */
    public static function enforce(int $userid, string $action, int $limit = 10, int $windowseconds = 3600): void {
        global $DB;

        if ($userid <= 0) {
            return;
        }

        $since = time() - $windowseconds;
        $params = [
            'userid' => $userid, 'action' => $action, 'since' => $since,
        ];
        $count = $DB->count_records_select(
            'local_hlai_grading_log',
            'userid = :userid AND action = :action AND timecreated >= :since',
            $params
        );

        if ($count >= $limit) {
            throw new \moodle_exception('ratelimitexceeded', 'local_hlai_grading');
        }
    }
}
