<?php
namespace local_hlai_grading\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Manages Moodle's marking workflow states for assignments
 *
 * Per spec: submitted → inmarking → inreview → released
 */
class workflow_manager {

    /**
     * Move a user's assignment submission into a workflow state
     *
     * @param int $assignid Assignment instance ID
     * @param int $userid User ID
     * @param string $state One of: submitted|inmarking|inreview|released
     */
    public static function set_assign_state(int $assignid, int $userid, string $state): void {
        global $DB;

        // Check if assignment actually uses marking workflow
        $usesworkflow = $DB->get_field('assign', 'markingworkflow', ['id' => $assignid]);
        if (!$usesworkflow) {
            return;
        }

        // assign_user_flags holds workflowstate
        $flags = $DB->get_record('assign_user_flags', ['assignment' => $assignid, 'userid' => $userid]);
        if ($flags) {
            $flags->workflowstate = $state;
            $flags->timemodified = time();
            $DB->update_record('assign_user_flags', $flags);
        } else {
            $flags = new \stdClass();
            $flags->assignment = $assignid;
            $flags->userid = $userid;
            $flags->workflowstate = $state;
            $flags->allocatedmarker = 0;
            $flags->mailed = 0;
            $flags->extensionduedate = 0;
            $flags->timecreated = time();
            $flags->timemodified = time();
            $DB->insert_record('assign_user_flags', $flags);
        }
    }

    /**
     * Log an action to the audit trail
     *
     * @param int $queueid Queue record ID
     * @param int|null $resultid Result record ID (if exists)
     * @param string $action Action name (e.g., 'queued', 'processed', 'released', 'failed')
     * @param int|null $userid User performing action (0 for system)
     * @param string|null $details Additional details
     */
    public static function log_action(int $queueid, ?int $resultid, string $action, ?int $userid = 0, ?string $details = null): void {
        global $DB;

        $log = new \stdClass();
        $log->queueid = $queueid;
        $log->resultid = $resultid;
        $log->action = $action;
        $log->userid = $userid ?? 0;
        $log->details = $details;
        $log->timecreated = time();

        $DB->insert_record('hlai_grading_log', $log);
    }
}
