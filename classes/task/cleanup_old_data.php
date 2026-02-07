<?php
namespace local_hlai_grading\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Scheduled task that purges aged AI grading artefacts to honour retention rules.
 */
class cleanup_old_data extends \core\task\scheduled_task {

    public function get_name(): string {
        return get_string('task_cleanup_old_data', 'local_hlai_grading');
    }

    public function execute(): void {
        global $DB;

        $months = (int)get_config('local_hlai_grading', 'dataretentionmonths');
        if ($months <= 0) {
            return;
        }

        $cutoff = time() - ($months * 30 * DAYSECS);

        // Queue: remove completed/failed items older than cutoff.
        $DB->delete_records_select(
            'hlai_grading_queue',
            'timecompleted IS NOT NULL AND timecompleted < :cutoff AND status IN (:statusdone, :statusfailed, :statuserror)',
            [
                'cutoff' => $cutoff,
                'statusdone' => 'done',
                'statusfailed' => 'failed',
                'statuserror' => 'error',
            ]
        );

        // Results: keep only drafts whilst purging released/rejected artefacts.
        $DB->delete_records_select(
            'hlai_grading_results',
            'timecreated < :cutoff AND status IN (:released, :rejected)',
            [
                'cutoff' => $cutoff,
                'released' => 'released',
                'rejected' => 'rejected',
            ]
        );

        // Rubric scores reference results by timecreated.
        $DB->delete_records_select(
            'hlai_grading_rubric_scores',
            'timecreated < :cutoff',
            ['cutoff' => $cutoff]
        );

        // Logs: remove anything outside retention window.
        $DB->delete_records_select(
            'hlai_grading_log',
            'timecreated < :cutoff',
            ['cutoff' => $cutoff]
        );
    }
}
