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
 * Privacy API provider implementation.
 *
 * @package    local_hlai_grading
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_hlai_grading\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\writer;

/**
 * Provider class.
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * Get the list of metadata stored by this plugin.
     *
     * @param collection $collection The metadata collection.
     * @return collection The updated collection.
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('local_hlai_grading_queue', [
            'userid' => 'privacy:metadata:hlai_grading_queue:userid',
            'courseid' => 'privacy:metadata:hlai_grading_queue:courseid',
            'cmid' => 'privacy:metadata:hlai_grading_queue:cmid',
            'payload' => 'privacy:metadata:hlai_grading_queue:payload',
            'status' => 'privacy:metadata:hlai_grading_queue:status',
            'timecreated' => 'privacy:metadata:hlai_grading_queue:timecreated',
        ]);

        $collection->add_database_table('local_hlai_grading_results', [
            'userid' => 'privacy:metadata:hlai_grading_results:userid',
            'grade' => 'privacy:metadata:hlai_grading_results:grade',
            'maxgrade' => 'privacy:metadata:hlai_grading_results:maxgrade',
            'reasoning' => 'privacy:metadata:hlai_grading_results:reasoning',
            'rubric_analysis' => 'privacy:metadata:hlai_grading_results:rubric',
            'status' => 'privacy:metadata:hlai_grading_results:status',
            'timecreated' => 'privacy:metadata:hlai_grading_results:timecreated',
        ]);

        $collection->add_database_table('local_hlai_grading_rubric_scores', [
            'resultid' => 'privacy:metadata:hlai_grading_rubric_scores:resultid',
            'criterionid' => 'privacy:metadata:hlai_grading_rubric_scores:criterionid',
            'score' => 'privacy:metadata:hlai_grading_rubric_scores:score',
            'reasoning' => 'privacy:metadata:hlai_grading_rubric_scores:reasoning',
        ]);

        $collection->add_database_table('local_hlai_grading_log', [
            'userid' => 'privacy:metadata:hlai_grading_log:userid',
            'action' => 'privacy:metadata:hlai_grading_log:action',
            'details' => 'privacy:metadata:hlai_grading_log:details',
            'timecreated' => 'privacy:metadata:hlai_grading_log:timecreated',
        ]);

        $collection->add_database_table('local_hlai_grading_quiz_summary', [
            'userid' => 'privacy:metadata:hlai_grading_quiz_summary:userid',
            'score' => 'privacy:metadata:hlai_grading_quiz_summary:score',
            'feedback' => 'privacy:metadata:hlai_grading_quiz_summary:feedback',
            'strengths_json' => 'privacy:metadata:hlai_grading_quiz_summary:strengths',
            'improvements_json' => 'privacy:metadata:hlai_grading_quiz_summary:improvements',
        ]);

        return $collection;
    }

    /**
     * Get the list of contexts that contain user information for the specified user.
     *
     * @param int $userid The user ID.
     * @return contextlist The list of contexts.
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        global $DB;

        $contextlist = new contextlist();
        $hasdata = $DB->record_exists('local_hlai_grading_results', ['userid' => $userid]) ||
            $DB->record_exists('local_hlai_grading_queue', ['userid' => $userid]) ||
            $DB->record_exists('local_hlai_grading_log', ['userid' => $userid]) ||
            $DB->record_exists('local_hlai_grading_quiz_summary', ['userid' => $userid]);

        if ($hasdata) {
            $contextlist->add_system_context();
        }

        return $contextlist;
    }

    /**
     * Export all user data for the specified user.
     *
     * @param approved_contextlist $contextlist The approved context list.
     * @return void
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        if (!$contextlist->count()) {
            return;
        }

        $userid = $contextlist->get_user()->id;
        $context = \context_system::instance();
        if (!$contextlist->contextid_in_list($context->id)) {
            return;
        }

        $data = new \stdClass();
        $data->queue = array_values($DB->get_records('local_hlai_grading_queue', ['userid' => $userid]));
        $data->results = array_values($DB->get_records('local_hlai_grading_results', ['userid' => $userid]));
        $data->rubric_scores = array_values($DB->get_records_sql(
            'SELECT rs.*
               FROM {local_hlai_grading_rubric_scores} rs
               JOIN {local_hlai_grading_results} r ON r.id = rs.resultid
              WHERE r.userid = :userid',
            ['userid' => $userid]
        ));
        $data->log = array_values($DB->get_records('local_hlai_grading_log', ['userid' => $userid]));
        $data->quiz_summary = array_values($DB->get_records('local_hlai_grading_quiz_summary', ['userid' => $userid]));

        writer::with_context($context)->export_data(
            [get_string('pluginname', 'local_hlai_grading')],
            $data
        );
    }

    /**
     * Delete all user data for all users in the specified context.
     *
     * @param \context $context The context.
     * @return void
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;

        if ($context->contextlevel !== CONTEXT_SYSTEM) {
            return;
        }

        $DB->delete_records('local_hlai_grading_queue');
        $DB->delete_records('local_hlai_grading_results');
        $DB->delete_records('local_hlai_grading_rubric_scores');
        $DB->delete_records('local_hlai_grading_log');
        $DB->delete_records('local_hlai_grading_quiz_summary');
    }

    /**
     * Delete all user data for the specified user.
     *
     * @param approved_contextlist $contextlist The approved context list.
     * @return void
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;

        if (!$contextlist->count()) {
            return;
        }

        $userid = $contextlist->get_user()->id;

        $DB->delete_records('local_hlai_grading_queue', ['userid' => $userid]);
        $DB->delete_records('local_hlai_grading_results', ['userid' => $userid]);
        $DB->delete_records_select(
            'local_hlai_grading_rubric_scores',
            'resultid IN (SELECT id FROM {local_hlai_grading_results} WHERE userid = :userid)',
            ['userid' => $userid]
        );
        $DB->delete_records('local_hlai_grading_log', ['userid' => $userid]);
        $DB->delete_records('local_hlai_grading_quiz_summary', ['userid' => $userid]);
    }
}
