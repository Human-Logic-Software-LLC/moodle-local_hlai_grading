<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/local/hlai_grading/lib.php');

require_login();

global $DB, $OUTPUT, $PAGE;

$cmid = required_param('cmid', PARAM_INT);
$courseid = optional_param('courseid', 0, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);

$cm = get_coursemodule_from_id('quiz', $cmid, $courseid, false, MUST_EXIST);
$course = get_course($cm->course);
$quiz = $DB->get_record('quiz', ['id' => $cm->instance], '*', MUST_EXIST);
$context = context_module::instance($cm->id);

require_capability('mod/quiz:manage', $context);

$pageurl = new moodle_url('/local/hlai_grading/quiz_rubric_select.php', ['cmid' => $cmid, 'courseid' => $course->id]);
$PAGE->set_url($pageurl);
$PAGE->set_context($context);
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('quizrubric_select', 'local_hlai_grading'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->requires->css('/local/hlai_grading/styles.css');

$errors = [];

$settings = local_hlai_grading_get_activity_settings('quiz', (int)$quiz->id);
$selectedid = (int)($settings->rubricid ?? 0);

$rubrics = local_hlai_grading_get_quiz_rubrics($course->id, $context);
if ($selectedid && !isset($rubrics[$selectedid])) {
    $selectedid = 0;
}

if ($action === 'save' && data_submitted()) {
    require_sesskey();
    $newid = optional_param('rubricid', 0, PARAM_INT);

    if ($newid && !isset($rubrics[$newid])) {
        $errors[] = get_string('quizrubric_select_invalid', 'local_hlai_grading');
    } else {
        $quality = $settings->quality ?? (get_config('local_hlai_grading', 'defaultquality') ?: 'balanced');
        local_hlai_grading_save_activity_settings(
            'quiz',
            (int)$quiz->id,
            (int)($settings->enabled ?? 0),
            $quality,
            (string)($settings->custominstructions ?? ''),
            (int)($settings->autorelease ?? 0),
            $newid ?: null
        );

        redirect($pageurl, get_string('quizrubric_select_saved', 'local_hlai_grading'), null, \core\output\notification::NOTIFY_SUCCESS);
    }
}

$selectedrubric = $selectedid && isset($rubrics[$selectedid]) ? $rubrics[$selectedid] : null;
$selecteditems = $selectedrubric ? local_hlai_grading_get_quiz_rubric_items((int)$selectedrubric->id) : [];

echo $OUTPUT->header();

echo html_writer::start_div('local-hlai-grading local-hlai-iksha');

echo html_writer::start_div('hlai-page-header');
echo html_writer::tag('h2', get_string('quizrubric_select', 'local_hlai_grading'), ['class' => 'hlai-page-title']);
echo html_writer::tag('p', get_string('quizrubric_select_intro', 'local_hlai_grading'), ['class' => 'hlai-page-subtitle']);
echo html_writer::end_div();

foreach ($errors as $error) {
    echo $OUTPUT->notification($error, 'notifyproblem');
}

 $managecontext = context_course::instance($course->id);
 if (has_capability('local/hlai_grading:configure', $managecontext)) {
     $manageurl = new moodle_url('/local/hlai_grading/quiz_rubrics.php', ['courseid' => $course->id]);
     echo html_writer::div(
         html_writer::link($manageurl, get_string('quizrubric_manage_link', 'local_hlai_grading'), ['class' => 'button is-light']),
         'mb-4'
     );
 }

echo html_writer::start_div('columns');

echo html_writer::start_div('column is-5');
echo html_writer::start_div('iksha-widget');
echo html_writer::start_tag('header', ['class' => 'iksha-widget__header']);
echo html_writer::start_div('iksha-widget__title-group');
echo html_writer::div(
    html_writer::tag('i', '', ['class' => 'fa fa-list-alt', 'aria-hidden' => 'true']),
    'iksha-widget__icon iksha-widget__icon--primary'
);
echo html_writer::tag('h3', get_string('quizrubric_select_rubric', 'local_hlai_grading'), ['class' => 'iksha-widget__title']);
echo html_writer::end_div();
echo html_writer::end_tag('header');
echo html_writer::start_div('iksha-widget__body');

echo html_writer::start_tag('form', [
    'method' => 'post',
    'action' => $pageurl->out(false),
    'class' => 'iksha-form',
]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'save']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);

echo html_writer::start_div('field');
echo html_writer::tag('label', get_string('quizrubric_select_rubric', 'local_hlai_grading'), ['class' => 'label']);
echo html_writer::start_div('control');
echo html_writer::start_div('select is-fullwidth');
echo html_writer::start_tag('select', ['name' => 'rubricid']);
echo html_writer::tag('option', get_string('quizrubric_select_none', 'local_hlai_grading'), ['value' => 0]);
foreach ($rubrics as $rubric) {
    $label = format_string($rubric->name);
    if ($rubric->visibility === 'global') {
        $label .= ' (' . get_string('quizrubric_visibility_global', 'local_hlai_grading') . ')';
    }
    $attrs = ['value' => $rubric->id];
    if ((int)$rubric->id === $selectedid) {
        $attrs['selected'] = 'selected';
    }
    echo html_writer::tag('option', $label, $attrs);
}
echo html_writer::end_tag('select');
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::tag('p', get_string('quizrubric_select_help', 'local_hlai_grading'), ['class' => 'iksha-help']);
echo html_writer::end_div();

echo html_writer::start_div('iksha-form-actions');
echo html_writer::tag('button', get_string('savechanges'), ['type' => 'submit', 'class' => 'button is-primary']);
echo html_writer::end_div();

echo html_writer::end_tag('form');

echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::start_div('column is-7');
echo html_writer::start_div('iksha-widget');
echo html_writer::start_tag('header', ['class' => 'iksha-widget__header']);
echo html_writer::start_div('iksha-widget__title-group');
echo html_writer::div(
    html_writer::tag('i', '', ['class' => 'fa fa-bar-chart', 'aria-hidden' => 'true']),
    'iksha-widget__icon iksha-widget__icon--info'
);
echo html_writer::tag('h3', get_string('quizrubric_preview', 'local_hlai_grading'), ['class' => 'iksha-widget__title']);
echo html_writer::end_div();
echo html_writer::end_tag('header');
echo html_writer::start_div('iksha-widget__body');

if (!$selectedrubric) {
    echo html_writer::div(get_string('quizrubric_preview_empty', 'local_hlai_grading'), 'iksha-empty');
} else {
    $visibility = $selectedrubric->visibility === 'global'
        ? get_string('quizrubric_visibility_global', 'local_hlai_grading')
        : get_string('quizrubric_visibility_course', 'local_hlai_grading');

    echo html_writer::tag('p', format_string($selectedrubric->name), ['class' => 'has-text-weight-bold']);
    echo html_writer::tag('p', $visibility, ['class' => 'iksha-table__meta mb-3']);

    if (!empty($selecteditems)) {
        echo html_writer::start_div('iksha-table-wrapper');
        echo html_writer::start_tag('table', ['class' => 'iksha-table generaltable']);
        echo html_writer::start_tag('thead');
        echo html_writer::start_tag('tr');
        echo html_writer::tag('th', get_string('criterion', 'local_hlai_grading'));
        echo html_writer::tag('th', get_string('score', 'local_hlai_grading'));
        echo html_writer::tag('th', get_string('description'));
        echo html_writer::end_tag('tr');
        echo html_writer::end_tag('thead');
        echo html_writer::start_tag('tbody');

        foreach ($selecteditems as $item) {
            echo html_writer::start_tag('tr');
            echo html_writer::tag('td', s($item->name));
            echo html_writer::tag('td', s(format_float((float)$item->maxscore, 2)));
            echo html_writer::tag('td', s($item->description ?? ''));
            echo html_writer::end_tag('tr');
        }

        echo html_writer::end_tag('tbody');
        echo html_writer::end_tag('table');
        echo html_writer::end_div();
    } else {
        echo html_writer::div(get_string('quizrubric_items_empty', 'local_hlai_grading'), 'iksha-empty');
    }
}

echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::end_div();
echo html_writer::end_div();

echo $OUTPUT->footer();
