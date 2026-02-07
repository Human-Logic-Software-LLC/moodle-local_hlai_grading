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
 * Database upgrade steps.
 *
 * @package    local_hlai_grading
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrade the local_hlai_grading plugin database schema.
 *
 * @param int $oldversion The old version of the plugin.
 * @return bool True on success.
 */
function xmldb_local_hlai_grading_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    // SPEC compliance upgrade: Add new tables and fields for workflow management.
    if ($oldversion < 2025110700) {
        // Add new fields to existing queue table.
        $table = new xmldb_table('local_hlai_grading_queue');

        // Add modulename field.
        $field = new xmldb_field('modulename', XMLDB_TYPE_CHAR, '50', null, null, null, null, 'cmid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Add instanceid field.
        $field = new xmldb_field('instanceid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'modulename');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Add submissionid field.
        $field = new xmldb_field('submissionid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'instanceid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Add attemptid field.
        $field = new xmldb_field('attemptid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'submissionid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Add questionid field.
        $field = new xmldb_field('questionid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'attemptid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Add retries field.
        $field = new xmldb_field('retries', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '0', 'status');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Add nextrun field.
        $field = new xmldb_field('nextrun', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'retries');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Add timecompleted field.
        $field = new xmldb_field('timecompleted', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'timecreated');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Create hlai_grading_results table.
        $table = new xmldb_table('local_hlai_grading_results');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('queueid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('modulename', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
            $table->add_field('instanceid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('submissionid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('attemptid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('questionid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('grade', XMLDB_TYPE_NUMBER, '10, 5', null, null, null, null);
            $table->add_field('maxgrade', XMLDB_TYPE_NUMBER, '10, 5', null, null, null, null);
            $table->add_field('grademethod', XMLDB_TYPE_CHAR, '20', null, null, null, null);
            $table->add_field('reasoning', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('rubric_analysis', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('confidence', XMLDB_TYPE_NUMBER, '5, 2', null, null, null, null);
            $table->add_field('model', XMLDB_TYPE_CHAR, '50', null, null, null, null);
            $table->add_field('quality', XMLDB_TYPE_CHAR, '20', null, null, null, null);
            $table->add_field('tokens_used', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('processing_time', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'draft');
            $table->add_field('reviewed', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('reviewer_id', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('timereviewed', XMLDB_TYPE_INTEGER, '10', null, null, null, null);

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('queueidx', XMLDB_INDEX_NOTUNIQUE, ['queueid']);
            $table->add_index('useridx', XMLDB_INDEX_NOTUNIQUE, ['userid']);
            $table->add_index('statusidx', XMLDB_INDEX_NOTUNIQUE, ['status']);

            $dbman->create_table($table);
        }

        // Create hlai_grading_rubric_scores table.
        $table = new xmldb_table('local_hlai_grading_rubric_scores');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('resultid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('criterionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('levelid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('score', XMLDB_TYPE_NUMBER, '10, 5', null, null, null, null);
            $table->add_field('reasoning', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('resultidx', XMLDB_INDEX_NOTUNIQUE, ['resultid']);

            $dbman->create_table($table);
        }

        // Create hlai_grading_log table.
        $table = new xmldb_table('local_hlai_grading_log');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('queueid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('resultid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('action', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('details', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('queueidx', XMLDB_INDEX_NOTUNIQUE, ['queueid']);
            $table->add_index('resultidx', XMLDB_INDEX_NOTUNIQUE, ['resultid']);
            $table->add_index('actionidx', XMLDB_INDEX_NOTUNIQUE, ['action']);

            $dbman->create_table($table);
        }

        // Create hlai_grading_config table.
        $table = new xmldb_table('local_hlai_grading_config');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('name', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
            $table->add_field('value', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('nameuniq', XMLDB_INDEX_UNIQUE, ['name']);

            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2025110700, 'local', 'hlai_grading');
    }

    if ($oldversion < 2025110701) {
        $table = new xmldb_table('local_hlai_grading_act_settings');
        if (!$dbman->table_exists('local_hlai_grading_act_settings')) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('assignid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('enabled', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('quality', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'balanced');
            $table->add_field('custominstructions', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('autorelease', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('assignuniq', XMLDB_INDEX_UNIQUE, ['assignid']);

            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2025110701, 'local', 'hlai_grading');
    }

    if ($oldversion < 2025110702) {
        // Add slot field to results table.
        $table = new xmldb_table('local_hlai_grading_results');
        $field = new xmldb_field('slot', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'questionid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Create new activity settings table.
        $table = new xmldb_table('local_hlai_grading_act_settings');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('modulename', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
            $table->add_field('instanceid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('enabled', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('quality', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'balanced');
            $table->add_field('custominstructions', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('autorelease', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('activityuniq', XMLDB_INDEX_UNIQUE, ['modulename', 'instanceid']);

            $dbman->create_table($table);
        }

        // Migrate existing assignment settings if old table exists.
        if ($dbman->table_exists('local_hlai_grading_act_settings')) {
            $records = $DB->get_records('local_hlai_grading_act_settings');
            foreach ($records as $record) {
                $new = new \stdClass();
                $new->modulename = 'assign';
                $new->instanceid = $record->assignid;
                $new->enabled = $record->enabled;
                $new->quality = $record->quality;
                $new->custominstructions = $record->custominstructions;
                $new->autorelease = $record->autorelease;
                $new->timecreated = $record->timecreated;
                $new->timemodified = $record->timemodified;
                $DB->insert_record('local_hlai_grading_activity_settings', $new);
            }

            $oldtable = new xmldb_table('local_hlai_grading_assign_settings');
            if ($dbman->table_exists($oldtable)) {
                $dbman->drop_table($oldtable);
            }
        }

        upgrade_plugin_savepoint(true, 2025110702, 'local', 'hlai_grading');
    }

    if ($oldversion < 2025111000) {
        $table = new xmldb_table('local_hlai_grading_results');

        $field = new xmldb_field('prompttokens', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'tokens_used');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('completiontokens', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'prompttokens');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2025111000, 'local', 'hlai_grading');
    }

    if ($oldversion < 2025111300) {
        $table = new xmldb_table('local_hlai_grading_results');

        $field = new xmldb_field('strengths_json', XMLDB_TYPE_TEXT, null, null, null, null, null, 'rubric_analysis');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('improvements_json', XMLDB_TYPE_TEXT, null, null, null, null, null, 'strengths_json');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('examples_json', XMLDB_TYPE_TEXT, null, null, null, null, null, 'improvements_json');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('rubric_version_id', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'processing_time');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $table = new xmldb_table('local_hlai_grading_rubric_scores');

        $field = new xmldb_field('criterionname', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'criterionid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('levelname', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'levelid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('maxscore', XMLDB_TYPE_NUMBER, '10, 5', null, null, null, null, 'score');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2025111300, 'local', 'hlai_grading');
    }

    if ($oldversion < 2025120100) {
        // Add rubricid to activity settings for quiz use.
        $table = new xmldb_table('local_hlai_grading_act_settings');
        $field = new xmldb_field('rubricid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'autorelease');
        if ($dbman->table_exists($table) && !$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Create quiz rubric table.
        $rubrictable = new xmldb_table('local_hlai_grading_quiz_rubric');
        if (!$dbman->table_exists($rubrictable)) {
            $rubrictable->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $rubrictable->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $rubrictable->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $rubrictable->add_field('ownerid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $rubrictable->add_field('visibility', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'course');
            $rubrictable->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $rubrictable->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);

            $rubrictable->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $rubrictable->add_index('courseidx', XMLDB_INDEX_NOTUNIQUE, ['courseid']);

            $dbman->create_table($rubrictable);
        }

        $itemtable = new xmldb_table('local_hlai_grading_quiz_rubric_item');
        if (!$dbman->table_exists($itemtable)) {
            $itemtable->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $itemtable->add_field('rubricid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $itemtable->add_field('sortorder', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $itemtable->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $itemtable->add_field('maxscore', XMLDB_TYPE_NUMBER, '10, 5', null, XMLDB_NOTNULL, null, '0');
            $itemtable->add_field('description', XMLDB_TYPE_TEXT, null, null, null, null, null);

            $itemtable->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $itemtable->add_key('rubricfk', XMLDB_KEY_FOREIGN, ['rubricid'], 'local_hlai_grading_quiz_rubric', ['id']);

            $dbman->create_table($itemtable);
        }

        upgrade_plugin_savepoint(true, 2025120100, 'local', 'hlai_grading');
    }

    if ($oldversion < 2025120104) {
        $table = new xmldb_table('local_hlai_grading_quiz_summary');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('quizid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('attemptid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('score', XMLDB_TYPE_NUMBER, '10, 5', null, null, null, null);
            $table->add_field('maxscore', XMLDB_TYPE_NUMBER, '10, 5', null, null, null, null);
            $table->add_field('feedback', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('strengths_json', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('improvements_json', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('confidence', XMLDB_TYPE_NUMBER, '5, 2', null, null, null, null);
            $table->add_field('model', XMLDB_TYPE_CHAR, '50', null, null, null, null);
            $table->add_field('quality', XMLDB_TYPE_CHAR, '20', null, null, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('attemptidx', XMLDB_INDEX_UNIQUE, ['attemptid']);
            $table->add_index('useridx', XMLDB_INDEX_NOTUNIQUE, ['userid']);
            $table->add_index('quizidx', XMLDB_INDEX_NOTUNIQUE, ['quizid']);

            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2025120104, 'local', 'hlai_grading');
    }

    return true;
}
