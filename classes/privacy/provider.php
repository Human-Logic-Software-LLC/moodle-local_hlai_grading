<?php
namespace local_hlai_grading\privacy;

defined('MOODLE_INTERNAL') || die();

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\writer;

/**
 * Privacy API provider for the AI grading plugin.
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider {

    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('hlai_grading_queue', [
            'userid' => 'privacy:metadata:hlai_grading_queue:userid',
            'courseid' => 'privacy:metadata:hlai_grading_queue:courseid',
            'cmid' => 'privacy:metadata:hlai_grading_queue:cmid',
            'payload' => 'privacy:metadata:hlai_grading_queue:payload',
            'status' => 'privacy:metadata:hlai_grading_queue:status',
            'timecreated' => 'privacy:metadata:hlai_grading_queue:timecreated',
        ]);

        $collection->add_database_table('hlai_grading_results', [
            'userid' => 'privacy:metadata:hlai_grading_results:userid',
            'grade' => 'privacy:metadata:hlai_grading_results:grade',
            'maxgrade' => 'privacy:metadata:hlai_grading_results:maxgrade',
            'reasoning' => 'privacy:metadata:hlai_grading_results:reasoning',
            'rubric_analysis' => 'privacy:metadata:hlai_grading_results:rubric',
            'status' => 'privacy:metadata:hlai_grading_results:status',
            'timecreated' => 'privacy:metadata:hlai_grading_results:timecreated',
        ]);

        $collection->add_database_table('hlai_grading_rubric_scores', [
            'resultid' => 'privacy:metadata:hlai_grading_rubric_scores:resultid',
            'criterionid' => 'privacy:metadata:hlai_grading_rubric_scores:criterionid',
            'score' => 'privacy:metadata:hlai_grading_rubric_scores:score',
            'reasoning' => 'privacy:metadata:hlai_grading_rubric_scores:reasoning',
        ]);

        $collection->add_database_table('hlai_grading_log', [
            'userid' => 'privacy:metadata:hlai_grading_log:userid',
            'action' => 'privacy:metadata:hlai_grading_log:action',
            'details' => 'privacy:metadata:hlai_grading_log:details',
            'timecreated' => 'privacy:metadata:hlai_grading_log:timecreated',
        ]);

        $collection->add_database_table('hlai_grading_quiz_summary', [
            'userid' => 'privacy:metadata:hlai_grading_quiz_summary:userid',
            'score' => 'privacy:metadata:hlai_grading_quiz_summary:score',
            'feedback' => 'privacy:metadata:hlai_grading_quiz_summary:feedback',
            'strengths_json' => 'privacy:metadata:hlai_grading_quiz_summary:strengths',
            'improvements_json' => 'privacy:metadata:hlai_grading_quiz_summary:improvements',
        ]);

        return $collection;
    }

    public static function get_contexts_for_userid(int $userid): contextlist {
        global $DB;

        $contextlist = new contextlist();
        $hasdata = $DB->record_exists('hlai_grading_results', ['userid' => $userid]) ||
            $DB->record_exists('hlai_grading_queue', ['userid' => $userid]) ||
            $DB->record_exists('hlai_grading_log', ['userid' => $userid]) ||
            $DB->record_exists('hlai_grading_quiz_summary', ['userid' => $userid]);

        if ($hasdata) {
            $contextlist->add_system_context();
        }

        return $contextlist;
    }

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
        $data->queue = array_values($DB->get_records('hlai_grading_queue', ['userid' => $userid]));
        $data->results = array_values($DB->get_records('hlai_grading_results', ['userid' => $userid]));
        $data->rubric_scores = array_values($DB->get_records_sql(
            'SELECT rs.*
               FROM {hlai_grading_rubric_scores} rs
               JOIN {hlai_grading_results} r ON r.id = rs.resultid
              WHERE r.userid = :userid',
            ['userid' => $userid]
        ));
        $data->log = array_values($DB->get_records('hlai_grading_log', ['userid' => $userid]));
        $data->quiz_summary = array_values($DB->get_records('hlai_grading_quiz_summary', ['userid' => $userid]));

        writer::with_context($context)->export_data(
            [get_string('pluginname', 'local_hlai_grading')],
            $data
        );
    }

    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;

        if ($context->contextlevel !== CONTEXT_SYSTEM) {
            return;
        }

        $DB->delete_records('hlai_grading_queue');
        $DB->delete_records('hlai_grading_results');
        $DB->delete_records('hlai_grading_rubric_scores');
        $DB->delete_records('hlai_grading_log');
        $DB->delete_records('hlai_grading_quiz_summary');
    }

    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;

        if (!$contextlist->count()) {
            return;
        }

        $userid = $contextlist->get_user()->id;

        $DB->delete_records('hlai_grading_queue', ['userid' => $userid]);
        $DB->delete_records('hlai_grading_results', ['userid' => $userid]);
        $DB->delete_records_select(
            'hlai_grading_rubric_scores',
            'resultid IN (SELECT id FROM {hlai_grading_results} WHERE userid = :userid)',
            ['userid' => $userid]
        );
        $DB->delete_records('hlai_grading_log', ['userid' => $userid]);
        $DB->delete_records('hlai_grading_quiz_summary', ['userid' => $userid]);
    }
}
