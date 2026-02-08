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
 * Scheduled task to cleanup old data.
 *
 * @package    local_hlai_grading
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_hlai_grading\task;

/**
 * Cleanup_old_data class.
 */
class cleanup_old_data extends \core\task\scheduled_task {
    /**
     * Get the task name.
     *
     * @return string The task name.
     */
    public function get_name(): string {
        return get_string('task_cleanup_old_data', 'local_hlai_grading');
    }

    /**
     * Execute the cleanup task.
     *
     * @return void
     */
    public function execute(): void {
        global $DB;

        $months = (int)get_config('local_hlai_grading', 'dataretentionmonths');
        if ($months <= 0) {
            return;
        }

        $cutoff = time() - ($months * 30 * DAYSECS);

        // Queue: remove completed/failed items older than cutoff.
        $DB->delete_records_select(
            'local_hlai_grading_queue',
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
            'local_hlai_grading_results',
            'timecreated < :cutoff AND status IN (:released, :rejected)',
            [
                'cutoff' => $cutoff,
                'released' => 'released',
                'rejected' => 'rejected',
            ]
        );

        // Rubric scores reference results by timecreated.
        $DB->delete_records_select(
            'local_hlai_grading_rubric_scores',
            'timecreated < :cutoff',
            ['cutoff' => $cutoff]
        );

        // Logs: remove anything outside retention window.
        $DB->delete_records_select(
            'local_hlai_grading_log',
            'timecreated < :cutoff',
            ['cutoff' => $cutoff]
        );
    }
}
