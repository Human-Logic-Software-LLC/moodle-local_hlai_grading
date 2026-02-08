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
 * Queue management class.
 *
 * @package    local_hlai_grading
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_hlai_grading\local;

use local_hlai_grading\local\workflow_manager;

/**
 * Queuer class.
 */
class queuer {
    /**
     * Put a submission into the hlai_grading_queue table.
     *
     * @param int $userid Userid.
     * @param int|null $courseid Courseid.
     * @param int|null $cmid Cmid.
     * @param string $eventname Eventname.
     * @param array $payload Payload.
     * @return int new id
     */
    public function queue_submission(int $userid, ?int $courseid, ?int $cmid, string $eventname, array $payload = []): int {
        global $DB;

        $component = 'mod_' . ($payload['modulename'] ?? 'assign');

        $record = (object)[
            'userid'      => $userid,
            'courseid'    => $courseid,
            'cmid'        => $cmid,
            'component'   => $component,
            'eventname'   => $eventname,
            'payload'     => json_encode($payload),
            'status'      => 'pending',
            'timecreated' => time(),
        ];

        $queueid = $DB->insert_record('local_hlai_grading_queue', $record);

        $context = $this->resolve_context($cmid, $courseid);
        \local_hlai_grading\event\submission_queued::create([
            'objectid' => $queueid,
            'context' => $context,
            'courseid' => $courseid,
            'relateduserid' => $userid ?: null,
            'other' => [
                'modulename' => $payload['modulename'] ?? 'assign',
                'instanceid' => $payload['instanceid'] ?? null,
            ],
        ])->trigger();

        workflow_manager::log_action($queueid, null, 'queued', 0, 'Submission queued for AI grading');

        return $queueid;
    }

    /**
     * Attempt to derive the most specific context we can.
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
}
