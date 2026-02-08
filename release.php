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
 * Grade review and release page.
 *
 * @package    local_hlai_grading
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/../../mod/assign/locallib.php');
require_once(__DIR__ . '/../../grade/grading/lib.php');

$id = required_param('id', PARAM_INT); // Record ID from hlai_grading_results.
$confirm = optional_param('confirm', 0, PARAM_BOOL);
$action = optional_param('action', 'release', PARAM_ALPHA); // Release, reject, or modify.

global $DB, $USER, $OUTPUT, $PAGE, $CFG;

$result = $DB->get_record('local_hlai_grading_results', ['id' => $id], '*', MUST_EXIST);
$queue  = $DB->get_record('local_hlai_grading_queue', ['id' => $result->queueid], '*', IGNORE_MISSING);
if (!$queue) {
    $queue = (object)[
        'id' => $result->queueid,
        'submissionid' => $result->submissionid ?? 0,
        'attemptid' => $result->attemptid ?? 0,
        'questionid' => $result->questionid ?? 0,
        'payload' => null,
    ];
}

$activity = null;
$assigninstance = null;
$modulename = $result->modulename ?? 'assign';

switch ($modulename) {
    case 'assign':
        $activity = $DB->get_record('assign', ['id' => $result->instanceid], '*', MUST_EXIST);
        $course = $DB->get_record('course', ['id' => $activity->course], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('assign', $activity->id, $course->id, false, MUST_EXIST);
        require_once($CFG->dirroot . '/mod/assign/locallib.php');
        $assigninstance = new \assign(context_module::instance($cm->id), $cm, $course);
        break;
    case 'quiz':
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        require_once($CFG->dirroot . '/question/engine/lib.php');
        $activity = $DB->get_record('quiz', ['id' => $result->instanceid], '*', MUST_EXIST);
        $course = $DB->get_record('course', ['id' => $activity->course], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('quiz', $activity->id, $course->id, false, MUST_EXIST);
        break;
    default:
        throw new moodle_exception('unsupportedmod', 'local_hlai_grading', '', s($modulename));
}

require_course_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('local/hlai_grading:releasegrades', $context);
$requiredcap = $modulename === 'quiz' ? 'mod/quiz:grade' : 'mod/assign:grade';
require_capability($requiredcap, $context);

$PAGE->set_url('/local/hlai_grading/release.php', ['id' => $id]);
$PAGE->set_context($context);
$PAGE->set_title(get_string('releasegradedraft', 'local_hlai_grading'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->requires->css('/local/hlai_grading/styles.css');
$dashboardurl = new moodle_url('/local/hlai_grading/view.php', [
    'courseid' => $course->id, 'assignid' => $activity->id, 'module' => $modulename,
]);

// Handle release confirmation.
if ($confirm && confirm_sesskey()) {
    $bundle = [
        'result' => $result,
        'queue' => $queue,
        'course' => $course,
        'activity' => $activity,
        'cm' => $cm,
        'context' => $context,
        'assigninstance' => $assigninstance,
        'modulename' => $modulename,
    ];

    if ($action === 'release') {
        try {
            \local_hlai_grading\local\result_service::release($bundle, $USER->id);
        } catch (\Throwable $releaseerror) {
            redirect(
                $dashboardurl,
                get_string('releasefailed', 'local_hlai_grading') . ': ' . $releaseerror->getMessage(),
                null,
                \core\output\notification::NOTIFY_ERROR
            );
        }

        redirect(
            $dashboardurl,
            get_string('released', 'local_hlai_grading'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    } else if ($action === 'reject') {
        \local_hlai_grading\local\result_service::reject($bundle, $USER->id);

        redirect(
            $dashboardurl,
            get_string('rejected', 'local_hlai_grading'),
            null,
            \core\output\notification::NOTIFY_WARNING
        );
    }
}

// Fetch student submission content.
$submissiontext = '';
$questiontext = '';
$user = $DB->get_record('user', ['id' => $result->userid], '*', MUST_EXIST);
if ($assigninstance) {
    try {
        $submission = $assigninstance->get_user_submission($result->userid, false);

        if ($submission) {
            // Online text submissions.
            $textplugin = $assigninstance->get_submission_plugin_by_type('onlinetext');
            if ($textplugin && $textplugin->is_enabled()) {
                $text = $textplugin->get_editor_text('onlinetext', $submission->id);
                if ($text) {
                    $submissiontext = format_text($text, FORMAT_HTML);
                }
            }

            // File submissions fallback.
            if (empty($submissiontext)) {
                $fileplugin = $assigninstance->get_submission_plugin_by_type('file');
                if ($fileplugin && $fileplugin->is_enabled()) {
                    $files = $fileplugin->get_files($submission, $user);
                    if (!empty($files)) {
                        $submissiontext = html_writer::tag('p', get_string('submittedfiles', 'local_hlai_grading') . ':');
                        $submissiontext .= html_writer::start_tag('ul');
                        foreach ($files as $file) {
                            $submissiontext .= html_writer::tag('li', s($file->get_filename()));
                        }
                        $submissiontext .= html_writer::end_tag('ul');
                    }
                }
            }
        }
    } catch (\Exception $e) {
        $submissiontext = html_writer::tag(
            'p',
            get_string('submissionerror', 'local_hlai_grading', $e->getMessage()),
            ['class' => 'text-danger']
        );
    }
} else if ($modulename === 'quiz' && !empty($result->attemptid) && !empty($result->slot)) {
    try {
        $attempt = $DB->get_record('quiz_attempts', ['id' => $result->attemptid], '*', MUST_EXIST);
        $quba = question_engine::load_questions_usage_by_activity($attempt->uniqueid);
        $qa = $quba->get_question_attempt((int)$result->slot);
        $question = $qa->get_question();
        $questiontext = format_text($question->questiontext, $question->questiontextformat ?? FORMAT_HTML, ['context' => $context]);
        $answer = $qa->get_last_qt_var('answer') ?? $qa->get_response_summary();
        if ($answer) {
            $submissiontext = format_text($answer, FORMAT_HTML, ['context' => $context]);
        }
    } catch (\Exception $e) {
        $submissiontext = html_writer::tag(
            'p',
            get_string('submissionerror', 'local_hlai_grading', $e->getMessage()),
            ['class' => 'text-danger']
        );
    }
}

// Display confirmation page.
echo $OUTPUT->header();
echo html_writer::start_div('local-hlai-grading local-hlai-iksha');

// Show the AI grading result details.
echo html_writer::start_div('ai-grading-review card p-3 mb-3');

echo html_writer::tag('h3', get_string('aigradingreview', 'local_hlai_grading'));

// User info.
echo html_writer::tag('p', html_writer::tag('strong', get_string('columnstudent', 'local_hlai_grading') . ': ') . fullname($user));

// Question details for quizzes.
if (!empty($questiontext)) {
    echo html_writer::tag('h4', get_string('question', 'quiz'));
    echo html_writer::tag('div', $questiontext, ['class' => 'alert alert-light border']);
}

// Student submission content.
if (!empty($submissiontext)) {
    echo html_writer::tag('h4', get_string('studentsubmission', 'local_hlai_grading'));
    echo html_writer::tag('div', $submissiontext, ['class' => 'alert alert-secondary border']);
}

// Grade info.
echo html_writer::tag('p', html_writer::tag('strong', get_string('grade') . ': ') .
    sprintf('%s / %s', number_format($result->grade, 2), number_format($result->maxgrade, 2)));

$similarity = $result->confidence ?? null;
if ($similarity !== null && $similarity !== '') {
    echo html_writer::tag('p', html_writer::tag('strong', get_string('similarityscore', 'local_hlai_grading') . ': ') .
        format_float((float)$similarity, 2) . '%');
}

$modellabel = '';
if (!empty($result->model)) {
    $modellabel = html_writer::tag('p', html_writer::tag('strong', get_string('modelused', 'local_hlai_grading') . ': ') .
        s($result->model));
    echo $modellabel;
}

// Similarity breakdown.
if (!empty($result->reasoning)) {
    echo html_writer::tag('h4', get_string('similaritybreakdown', 'local_hlai_grading'));
    echo html_writer::tag('div', nl2br(s($result->reasoning)), ['class' => 'alert alert-info']);
}

$matchedterms = json_decode($result->strengths_json ?? '[]', true) ?: [];
if (!empty($matchedterms)) {
    echo html_writer::tag('h4', get_string('similaritymatched', 'local_hlai_grading'));
    $items = '';
    foreach ($matchedterms as $term) {
        $items .= html_writer::tag('li', s($term));
    }
    echo html_writer::tag('ul', $items, ['class' => 'list-unstyled']);
}

$partialterms = [];
if (!empty($queue->payload)) {
    $payload = json_decode($queue->payload ?? '[]', true) ?: [];
    if (isset($payload['request']) && is_array($payload['request'])) {
        $payload = $payload['request'];
    }
    while (isset($payload['request']) && is_array($payload['request'])) {
        $payload = $payload['request'];
    }
    if (!empty($payload['analysis']['partial_terms']) && is_array($payload['analysis']['partial_terms'])) {
        $partialterms = $payload['analysis']['partial_terms'];
    }
}
if (!empty($partialterms)) {
    echo html_writer::tag('h4', get_string('similaritypartial', 'local_hlai_grading'));
    $items = '';
    foreach ($partialterms as $term) {
        $items .= html_writer::tag('li', s($term));
    }
    echo html_writer::tag('ul', $items, ['class' => 'list-unstyled']);
}

$missingterms = json_decode($result->improvements_json ?? '[]', true) ?: [];
if (!empty($missingterms)) {
    echo html_writer::tag('h4', get_string('similaritymissing', 'local_hlai_grading'));
    $items = '';
    foreach ($missingterms as $term) {
        $items .= html_writer::tag('li', s($term));
    }
    echo html_writer::tag('ul', $items, ['class' => 'list-unstyled']);
}

// Rubric breakdown.
$rubricrendered = false;
$decodedcriteria = [];
if (!empty($result->rubric_analysis)) {
    $decoded = json_decode($result->rubric_analysis, true);
    if (is_array($decoded)) {
        $decodedcriteria = $decoded;
    }
}

if ($modulename === 'assign' && $assigninstance) {
    $gradingmanager = get_grading_manager($context, 'mod_assign', 'submissions');
    if ($gradingmanager) {
        $method = $gradingmanager->get_active_method();
        if (in_array($method, ['rubric', 'rubric_ranges'], true)) {
            $controller = $gradingmanager->get_controller($method);
            $grade = $assigninstance->get_user_grade($result->userid, true);
            if ($controller && $grade) {
                $instances = $controller->get_active_instances($grade->id);

                // Backfill legacy AI results that predate rubric syncing so teachers always see the Moodle rubric view.
                if (empty($instances) && !empty($decodedcriteria)) {
                    try {
                        require_once($CFG->dirroot . '/local/hlai_grading/classes/local/rubric_sync.php');
                        $sync = new \local_hlai_grading\local\rubric_sync();
                        if (
                            $sync->write_rubric(
                                $course->id,
                                $cm->id,
                                $result->userid,
                                $decodedcriteria,
                                $result->reasoning ?? ''
                            )
                        ) {
                            $instances = $controller->get_active_instances($grade->id);
                        }
                    } catch (\Throwable $e) {
                        debugging('Failed to backfill rubric instance: ' . $e->getMessage(), DEBUG_DEVELOPER);
                    }
                }

                if (!empty($instances)) {
                    $formlib = $CFG->dirroot . '/grade/grading/form/' . $method . '/lib.php';
                    if (file_exists($formlib)) {
                        require_once($formlib);
                        echo html_writer::tag('h4', get_string('rubricbreakdown', 'local_hlai_grading'));
                        $renderer = $PAGE->get_renderer('gradingform_' . $method);
                        echo $renderer->display_instances($instances, '', false);
                        $rubricrendered = true;
                    }
                }
            }
        }
    }
}

if (!$rubricrendered && $modulename !== 'assign' && !empty($result->rubric_analysis)) {
    $criteria = $decodedcriteria;
    if (!empty($criteria)) {
        echo html_writer::tag('h4', get_string('rubricbreakdown', 'local_hlai_grading'));
        echo html_writer::start_div('iksha-table-wrapper');
        echo html_writer::start_tag('table', ['class' => 'iksha-table']);
        echo html_writer::start_tag('thead');
        echo html_writer::start_tag('tr');
        echo html_writer::tag('th', get_string('criterion', 'local_hlai_grading'));
        echo html_writer::tag('th', get_string('score', 'local_hlai_grading'));
        echo html_writer::tag('th', get_string('feedback'));
        echo html_writer::end_tag('tr');
        echo html_writer::end_tag('thead');
        echo html_writer::start_tag('tbody');

        foreach ($criteria as $crit) {
            echo html_writer::start_tag('tr');
            echo html_writer::tag('td', s($crit['name'] ?? ''));
            echo html_writer::tag('td', sprintf('%s / %s', $crit['score'] ?? 0, $crit['max_score'] ?? 0));
            echo html_writer::tag('td', s($crit['feedback'] ?? ''));
            echo html_writer::end_tag('tr');
        }

        echo html_writer::end_tag('tbody');
        echo html_writer::end_tag('table');
        echo html_writer::end_div();
    }
}

echo html_writer::end_div();

// Action buttons.
echo html_writer::start_div('ai-grading-actions');

// Release button.
$releaseurl = new moodle_url('/local/hlai_grading/release.php', [
    'id' => $id, 'action' => 'release', 'confirm' => 1, 'sesskey' => sesskey(),
]);
echo html_writer::link($releaseurl, get_string('releasegrade', 'local_hlai_grading'), [
    'class' => 'btn btn-success mr-2',
]);

// Reject button.
$rejecturl = new moodle_url('/local/hlai_grading/release.php', [
    'id' => $id, 'action' => 'reject', 'confirm' => 1, 'sesskey' => sesskey(),
]);
echo html_writer::link($rejecturl, get_string('rejectgrade', 'local_hlai_grading'), [
    'class' => 'btn btn-warning mr-2',
]);

// Cancel button.
$cancelurl = $dashboardurl ?? new moodle_url('/local/hlai_grading/view.php', ['courseid' => $course->id]);
echo html_writer::link($cancelurl, get_string('cancel'), [
    'class' => 'btn btn-secondary',
]);

echo html_writer::end_div();

echo html_writer::end_div();
echo $OUTPUT->footer();
