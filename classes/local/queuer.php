<?php
namespace local_hlai_grading\local;

defined('MOODLE_INTERNAL') || die();

use local_hlai_grading\local\workflow_manager;

class queuer {

    /**
     * Put a submission into the hlai_grading_queue table.
     *
     * @param int $userid
     * @param int|null $courseid
     * @param int|null $cmid
     * @param string $eventname
     * @param array $payload
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

        $queueid = $DB->insert_record('hlai_grading_queue', $record);

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
