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
 * Assignment grading integration page.
 *
 * @package    local_hlai_grading
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/assign/locallib.php');

$cmid = required_param('id', PARAM_INT);
$action = optional_param('action', 'grading', PARAM_ALPHA);

$cm = get_coursemodule_from_id('assign', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$assign = new assign(context_module::instance($cm->id), $cm, $course);

require_login($course, true, $cm);

$PAGE->set_url('/local/hlai_grading/assign_grading.php', ['id' => $cmid]);
$PAGE->set_title($assign->get_instance()->name);
$PAGE->set_heading($course->fullname);
$PAGE->requires->css('/local/hlai_grading/styles.css');

echo $OUTPUT->header();

echo html_writer::start_div('local-hlai-grading local-hlai-iksha');
echo html_writer::start_div('columns');
echo html_writer::start_div('column is-12');
echo html_writer::start_div('iksha-widget');
echo html_writer::start_tag('header', ['class' => 'iksha-widget__header']);
echo html_writer::start_div('iksha-widget__title-group');
echo html_writer::div(
    html_writer::tag('i', '', ['class' => 'fa fa-graduation-cap', 'aria-hidden' => 'true']),
    'iksha-widget__icon iksha-widget__icon--primary'
);
echo html_writer::tag('h3', format_string($assign->get_instance()->name), ['class' => 'iksha-widget__title']);
echo html_writer::end_div();
echo html_writer::end_tag('header');
echo html_writer::start_div('iksha-widget__body');

// Show AI grading summary banner.
$renderer = $PAGE->get_renderer('local_hlai_grading');
echo $renderer->render_ai_summary_banner($assign->get_instance()->id);

// Get all submissions for this assignment.
$submissions = $assign->list_participants(null, true);

if (empty($submissions)) {
    echo $OUTPUT->notification('No submissions yet', 'notifymessage');
} else {
    echo html_writer::start_div('iksha-table-wrapper');
    echo html_writer::start_tag('table', ['class' => 'iksha-table generaltable']);

    // Header.
    echo html_writer::start_tag('thead');
    echo html_writer::start_tag('tr');
    echo html_writer::tag('th', 'Student');
    echo html_writer::tag('th', 'Status');
    echo html_writer::tag('th', 'AI Status');
    echo html_writer::tag('th', 'Grade');
    echo html_writer::tag('th', 'Last Modified');
    echo html_writer::tag('th', 'Actions');
    echo html_writer::end_tag('tr');
    echo html_writer::end_tag('thead');

    // Body.
    echo html_writer::start_tag('tbody');

    foreach ($submissions as $userid => $participant) {
        $submission = $assign->get_user_submission($userid, false);
        $grade = $assign->get_user_grade($userid, false);

        $user = $DB->get_record('user', ['id' => $userid]);

        echo html_writer::start_tag('tr');

        // Student name.
        echo html_writer::tag('td', s(fullname($user)));

        // Submission status.
        $status = 'Not submitted';
        if ($submission) {
            $status = get_string('submissionstatus_' . $submission->status, 'assign');
        }
        echo html_writer::tag('td', $status);

        $aistatus = $renderer->render_ai_status_badge($userid, $assign->get_instance()->id);
        echo html_writer::tag('td', $aistatus);

        // Current grade.
        $gradetext = '-';
        if ($grade && $grade->grade >= 0) {
            $gradetext = sprintf('%.2f / %.2f', $grade->grade, $assign->get_instance()->grade);
        }
        echo html_writer::tag('td', $gradetext);

        // Last modified.
        $modified = '-';
        if ($submission && $submission->timemodified) {
            $modified = userdate($submission->timemodified);
        }
        echo html_writer::tag('td', $modified);

        // Actions.
        $gradeurl = new moodle_url('/mod/assign/view.php', [
            'id' => $cmid, 'action' => 'grader', 'userid' => $userid,
        ]);
        $gradelink = html_writer::link($gradeurl, 'Grade', ['class' => 'button is-light is-small']);
        echo html_writer::tag('td', $gradelink);

        echo html_writer::end_tag('tr');
    }

    echo html_writer::end_tag('tbody');
    echo html_writer::end_tag('table');
    echo html_writer::end_div();

    // Bulk actions.
    echo html_writer::start_div('mt-3');
    echo html_writer::tag('h4', 'Bulk Actions');

    // Check if there are ungraded submissions.
    $ungraded = 0;
    foreach ($submissions as $userid => $participant) {
        $grade = $assign->get_user_grade($userid, false);
        if (!$grade || $grade->grade < 0) {
            $ungraded++;
        }
    }

    if ($ungraded > 0) {
        echo html_writer::tag('p', sprintf('%d submissions not yet graded', $ungraded));
        // Could add "Grade all with AI" button here if needed.
    }

    echo html_writer::end_div();
}

echo html_writer::end_div(); // Iksha-widget__body.
echo html_writer::end_div(); // Iksha-widget.
echo html_writer::end_div(); // Column.
echo html_writer::end_div(); // Columns.
echo html_writer::end_div(); // Local-hlai-grading.

echo $OUTPUT->footer();
