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
 * Quiz rubric management page.
 *
 * @package    local_hlai_grading
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/local/hlai_grading/lib.php');

require_login();

global $DB, $OUTPUT, $PAGE, $USER;

$courseid = optional_param('courseid', 0, PARAM_INT);
$action = optional_param('action', 'list', PARAM_ALPHA);
$rubricid = optional_param('id', 0, PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_BOOL);

$course = $courseid ? get_course($courseid) : null;
$context = $course ? context_course::instance($course->id) : context_system::instance();

require_capability('local/hlai_grading:configure', $context);

$canmanageglobal = has_capability('local/hlai_grading:configure', context_system::instance());

$pageurl = new moodle_url('/local/hlai_grading/quiz_rubrics.php', ['courseid' => $courseid]);
$PAGE->set_url($pageurl);
$PAGE->set_context($context);
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('quizrubrics', 'local_hlai_grading'));
$PAGE->set_heading($course ? format_string($course->fullname) : get_string('pluginname', 'local_hlai_grading'));
$PAGE->requires->css('/local/hlai_grading/styles.css');

$errors = [];
$confirmdelete = false;
$existing = null;

if ($rubricid) {
    $existing = $DB->get_record('local_hlai_grading_quiz_rubric', ['id' => $rubricid], '*', IGNORE_MISSING);
    if (!$existing) {
        $errors[] = get_string('quizrubric_notfound', 'local_hlai_grading');
    } else {
        if (!empty($existing->courseid) && $courseid && (int)$existing->courseid !== $courseid) {
            throw new moodle_exception('invalidcourse');
        }
        if (!empty($existing->courseid) && !$courseid) {
            throw new moodle_exception('invalidcourse');
        }
        if (empty($existing->courseid) && !$canmanageglobal) {
            require_capability('local/hlai_grading:configure', context_system::instance());
        }
    }
}

if ($action === 'delete' && $existing) {
    require_sesskey();
    if ($confirm) {
        local_hlai_grading_delete_quiz_rubric($existing->id);
        redirect($pageurl, get_string('quizrubric_deleted', 'local_hlai_grading'), null, \core\output\notification::NOTIFY_SUCCESS);
    }
    $confirmdelete = true;
}

$formdata = (object)[
    'name' => '', 'visibility' => 'course', 'items' => '',
];

$submitted = ($action === 'save' && data_submitted());
if ($submitted) {
    require_sesskey();

    $formdata->name = trim(optional_param('name', '', PARAM_TEXT));
    $formdata->visibility = optional_param('visibility', 'course', PARAM_ALPHA);
    $formdata->items = optional_param('items', '', PARAM_RAW);

    if ($formdata->name === '') {
        $errors[] = get_string('quizrubric_name_required', 'local_hlai_grading');
    }

    $parsed = local_hlai_grading_parse_quiz_rubric_items($formdata->items);
    if (!empty($parsed['errors'])) {
        $errors = array_merge($errors, $parsed['errors']);
    }

    if ($formdata->visibility === 'global' && !$canmanageglobal) {
        $errors[] = get_string('quizrubric_visibility_global_denied', 'local_hlai_grading');
    }

    if ($formdata->visibility !== 'global' && !$courseid) {
        $errors[] = get_string('quizrubric_course_required', 'local_hlai_grading');
    }

    if (empty($errors)) {
        local_hlai_grading_save_quiz_rubric(
            $existing ? (int)$existing->id : null,
            $formdata->name,
            $courseid ?: null,
            (int)$USER->id,
            $formdata->visibility,
            $parsed['items']
        );

        redirect($pageurl, get_string('quizrubric_saved', 'local_hlai_grading'), null, \core\output\notification::NOTIFY_SUCCESS);
    }
}

if (!$submitted && $existing) {
    $formdata->name = (string)($existing->name ?? '');
    $formdata->visibility = $existing->visibility ?? 'course';
    $items = local_hlai_grading_get_quiz_rubric_items((int)$existing->id);
    $formdata->items = local_hlai_grading_format_quiz_rubric_items($items);
}

$rubrics = local_hlai_grading_get_quiz_rubrics($courseid, $context);
$itemcounts = [];
if (!empty($rubrics)) {
    [$insql, $params] = $DB->get_in_or_equal(array_keys($rubrics), SQL_PARAMS_NAMED);
    $countrecords = $DB->get_records_sql(
        "SELECT rubricid, COUNT(1) AS itemcount
           FROM {local_hlai_grading_quiz_rubric_item}
          WHERE rubricid {$insql}
       GROUP BY rubricid",
        $params
    );
    foreach ($countrecords as $record) {
        $itemcounts[(int)$record->rubricid] = (int)$record->itemcount;
    }
}

$totalcount = count($rubrics);
$coursecount = 0;
$globalcount = 0;
$criteriacount = 0;
foreach ($rubrics as $rubric) {
    if (empty($rubric->courseid)) {
        $globalcount++;
    } else {
        $coursecount++;
    }
    $criteriacount += $itemcounts[$rubric->id] ?? 0;
}

echo $OUTPUT->header();

echo html_writer::start_div('local-hlai-grading local-hlai-iksha');

echo html_writer::start_div('hlai-page-header hlai-page-header--split');
echo html_writer::start_div('hlai-page-header__text');
echo html_writer::tag('h2', get_string('quizrubric_manage', 'local_hlai_grading'), ['class' => 'hlai-page-title']);
echo html_writer::tag('p', get_string('quizrubric_manage_intro', 'local_hlai_grading'), ['class' => 'hlai-page-subtitle']);
echo html_writer::end_div();
$backurl = new moodle_url('/local/hlai_grading/view.php', [
    'courseid' => $courseid ?: SITEID, 'module' => 'quiz',
]);
echo html_writer::start_div('hlai-page-actions');
echo html_writer::tag(
    'a',
    html_writer::tag('i', '', ['class' => 'fa fa-arrow-left', 'aria-hidden' => 'true']) . ' ' .
        get_string('returntoreviewbutton', 'local_hlai_grading'),
    [
        'href' => $backurl->out(false), 'class' => 'button is-light',
    ]
);
echo html_writer::end_div();
echo html_writer::end_div();

foreach ($errors as $error) {
    echo $OUTPUT->notification($error, 'notifyproblem');
}

if ($confirmdelete && $existing) {
    $confirmurl = new moodle_url('/local/hlai_grading/quiz_rubrics.php', [
        'courseid' => $courseid, 'action' => 'delete', 'id' => $existing->id, 'confirm' => 1, 'sesskey' => sesskey(),
    ]);
    $cancelurl = new moodle_url('/local/hlai_grading/quiz_rubrics.php', ['courseid' => $courseid]);

    echo html_writer::start_div('iksha-widget mb-4');
    echo html_writer::start_tag('header', ['class' => 'iksha-widget__header']);
    echo html_writer::start_div('iksha-widget__title-group');
    echo html_writer::div(
        html_writer::tag('i', '', ['class' => 'fa fa-exclamation-triangle', 'aria-hidden' => 'true']),
        'iksha-widget__icon iksha-widget__icon--warning'
    );
    echo html_writer::tag(
        'h3',
        get_string('quizrubric_delete_confirm', 'local_hlai_grading', format_string($existing->name)),
        ['class' => 'iksha-widget__title']
    );
    echo html_writer::end_div();
    echo html_writer::end_tag('header');
    echo html_writer::start_div('iksha-widget__body');
    echo html_writer::start_div('iksha-form-actions');
    echo html_writer::link($confirmurl, get_string('delete'), ['class' => 'button is-danger']);
    echo html_writer::link($cancelurl, get_string('cancel'), ['class' => 'button is-light']);
    echo html_writer::end_div();
    echo html_writer::end_div();
    echo html_writer::end_div();
}

echo html_writer::start_div('iksha-kpi-grid iksha-kpi-grid--4 mb-4');
echo html_writer::start_div('iksha-kpi iksha-kpi--primary');
echo html_writer::div(
    html_writer::tag('i', '', ['class' => 'fa fa-list-alt', 'aria-hidden' => 'true']),
    'iksha-kpi__icon'
);
echo html_writer::start_div('iksha-kpi__content');
echo html_writer::tag('span', number_format($totalcount), ['class' => 'iksha-kpi__value']);
echo html_writer::tag('span', get_string('quizrubric_kpi_total', 'local_hlai_grading'), ['class' => 'iksha-kpi__label']);
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::start_div('iksha-kpi iksha-kpi--info');
echo html_writer::div(
    html_writer::tag('i', '', ['class' => 'fa fa-book', 'aria-hidden' => 'true']),
    'iksha-kpi__icon'
);
echo html_writer::start_div('iksha-kpi__content');
echo html_writer::tag('span', number_format($coursecount), ['class' => 'iksha-kpi__value']);
echo html_writer::tag('span', get_string('quizrubric_kpi_course', 'local_hlai_grading'), ['class' => 'iksha-kpi__label']);
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::start_div('iksha-kpi iksha-kpi--success');
echo html_writer::div(
    html_writer::tag('i', '', ['class' => 'fa fa-globe', 'aria-hidden' => 'true']),
    'iksha-kpi__icon'
);
echo html_writer::start_div('iksha-kpi__content');
echo html_writer::tag('span', number_format($globalcount), ['class' => 'iksha-kpi__value']);
echo html_writer::tag('span', get_string('quizrubric_kpi_global', 'local_hlai_grading'), ['class' => 'iksha-kpi__label']);
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::start_div('iksha-kpi iksha-kpi--warning');
echo html_writer::div(
    html_writer::tag('i', '', ['class' => 'fa fa-tasks', 'aria-hidden' => 'true']),
    'iksha-kpi__icon'
);
echo html_writer::start_div('iksha-kpi__content');
echo html_writer::tag('span', number_format($criteriacount), ['class' => 'iksha-kpi__value']);
echo html_writer::tag('span', get_string('quizrubric_kpi_criteria', 'local_hlai_grading'), ['class' => 'iksha-kpi__label']);
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::start_div('columns');

echo html_writer::start_div('column is-7');
echo html_writer::start_div('iksha-widget');
echo html_writer::start_tag('header', ['class' => 'iksha-widget__header']);
echo html_writer::start_div('iksha-widget__title-group');
echo html_writer::div(
    html_writer::tag('i', '', ['class' => 'fa fa-clipboard', 'aria-hidden' => 'true']),
    'iksha-widget__icon iksha-widget__icon--primary'
);
echo html_writer::tag('h3', get_string('quizrubrics', 'local_hlai_grading'), ['class' => 'iksha-widget__title']);
echo html_writer::end_div();
echo html_writer::end_tag('header');
echo html_writer::start_div('iksha-widget__body');

if (empty($rubrics)) {
    echo html_writer::div(get_string('quizrubric_list_empty', 'local_hlai_grading'), 'iksha-empty');
} else {
    echo html_writer::start_div('iksha-table-wrapper');
    echo html_writer::start_tag('table', ['class' => 'iksha-table generaltable']);
    echo html_writer::start_tag('thead');
    echo html_writer::start_tag('tr');
    echo html_writer::tag('th', get_string('quizrubric_name', 'local_hlai_grading'));
    echo html_writer::tag('th', get_string('quizrubric_visibility', 'local_hlai_grading'));
    echo html_writer::tag('th', get_string('quizrubric_items_short', 'local_hlai_grading'));
    echo html_writer::tag('th', get_string('actions'));
    echo html_writer::end_tag('tr');
    echo html_writer::end_tag('thead');
    echo html_writer::start_tag('tbody');

    foreach ($rubrics as $rubric) {
        $isglobal = empty($rubric->courseid);
        $canedit = $isglobal ? $canmanageglobal : true;
        $visibilitylabel = $isglobal
            ? get_string('quizrubric_visibility_global', 'local_hlai_grading')
            : get_string('quizrubric_visibility_course', 'local_hlai_grading');
        $visibilityclass = $isglobal ? 'iksha-badge--info' : 'iksha-badge--primary';
        $itemcount = $itemcounts[$rubric->id] ?? 0;

        $namecell = html_writer::tag('div', format_string($rubric->name), ['class' => 'has-text-weight-bold']);
        $namecell .= html_writer::tag(
            'div',
            get_string('lastmodified') . ': ' . userdate($rubric->timemodified),
            ['class' => 'iksha-table__meta']
        );

        echo html_writer::start_tag('tr');
        echo html_writer::tag('td', $namecell);
        echo html_writer::tag('td', html_writer::tag('span', $visibilitylabel, [
            'class' => 'iksha-badge ' . $visibilityclass . ' iksha-badge--sm',
        ]));
        echo html_writer::tag('td', html_writer::tag('span', (string)$itemcount, [
            'class' => 'iksha-badge iksha-badge--neutral iksha-badge--sm',
        ]));

        if ($canedit) {
            $editurl = new moodle_url('/local/hlai_grading/quiz_rubrics.php', [
                'courseid' => $courseid, 'action' => 'edit', 'id' => $rubric->id,
            ]);
            $deleteurl = new moodle_url('/local/hlai_grading/quiz_rubrics.php', [
                'courseid' => $courseid, 'action' => 'delete', 'id' => $rubric->id, 'sesskey' => sesskey(),
            ]);
            $actions = html_writer::start_div('iksha-actions');
            $actions .= html_writer::link($editurl, get_string('edit'), ['class' => 'button is-light is-small']);
            $actions .= html_writer::link($deleteurl, get_string('delete'), ['class' => 'button is-danger is-small']);
            $actions .= html_writer::end_div();
            echo html_writer::tag('td', $actions);
        } else {
            echo html_writer::tag('td', html_writer::tag('span', '-', ['class' => 'hlai-muted']));
        }

        echo html_writer::end_tag('tr');
    }

    echo html_writer::end_tag('tbody');
    echo html_writer::end_tag('table');
    echo html_writer::end_div();
}

echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::start_div('column is-5');
echo html_writer::start_div('iksha-widget');
echo html_writer::start_tag('header', ['class' => 'iksha-widget__header']);
echo html_writer::start_div('iksha-widget__title-group');
echo html_writer::div(
    html_writer::tag('i', '', ['class' => 'fa fa-pencil-square-o', 'aria-hidden' => 'true']),
    'iksha-widget__icon iksha-widget__icon--info'
);
echo html_writer::tag('h3', $existing
    ? get_string('quizrubric_form_edit', 'local_hlai_grading')
    : get_string('quizrubric_form_new', 'local_hlai_grading'), ['class' => 'iksha-widget__title']);
echo html_writer::end_div();
echo html_writer::end_tag('header');
echo html_writer::start_div('iksha-widget__body');

echo html_writer::start_tag('form', [
    'method' => 'post', 'action' => $pageurl->out(false), 'class' => 'iksha-form', 'id' => 'rubric-form',
]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'save']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
if ($existing) {
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $existing->id]);
}

echo html_writer::start_div('field');
echo html_writer::tag('label', get_string('quizrubric_name', 'local_hlai_grading'), ['class' => 'label']);
echo html_writer::start_div('control');
echo html_writer::empty_tag('input', [
    'type' => 'text', 'name' => 'name', 'value' => s($formdata->name), 'class' => 'input', 'required' => 'required',
]);
echo html_writer::end_div();
echo html_writer::end_div();

if ($courseid) {
    echo html_writer::start_div('field');
    echo html_writer::tag('label', get_string('quizrubric_visibility', 'local_hlai_grading'), ['class' => 'label']);
    echo html_writer::start_div('control');
    echo html_writer::start_div('select is-fullwidth');
    echo html_writer::start_tag('select', ['name' => 'visibility']);
    $visibilities = [
        'course' => get_string('quizrubric_visibility_course', 'local_hlai_grading'),
        'global' => get_string('quizrubric_visibility_global', 'local_hlai_grading'),
    ];
    foreach ($visibilities as $value => $label) {
        $selected = ($formdata->visibility === $value) ? ['selected' => 'selected'] : [];
        echo html_writer::tag('option', $label, ['value' => $value] + $selected);
    }
    echo html_writer::end_tag('select');
    echo html_writer::end_div();
    echo html_writer::end_div();
    echo html_writer::end_div();
} else {
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'visibility', 'value' => 'global']);
    echo html_writer::div(get_string('quizrubric_visibility_global', 'local_hlai_grading'), 'iksha-inline-meta');
}

echo html_writer::start_div('field');
echo html_writer::tag('label', get_string('quizrubric_items', 'local_hlai_grading'), ['class' => 'label']);
echo html_writer::start_div('control');
echo html_writer::tag('textarea', s($formdata->items), [
    'name' => 'items', 'rows' => 8, 'class' => 'textarea',
    'placeholder' => get_string('quizrubric_items_example', 'local_hlai_grading'),
]);
echo html_writer::end_div();
echo html_writer::tag('p', get_string('quizrubric_items_help', 'local_hlai_grading'), ['class' => 'iksha-help']);
echo html_writer::tag('div', s(get_string('quizrubric_items_example', 'local_hlai_grading')), ['class' => 'iksha-format-example']);
echo html_writer::end_div();

echo html_writer::start_div('iksha-form-actions');
echo html_writer::tag('button', get_string('quizrubric_save', 'local_hlai_grading'), [
    'type' => 'submit', 'class' => 'button is-primary',
]);
if ($existing) {
    $cancelurl = new moodle_url('/local/hlai_grading/quiz_rubrics.php', ['courseid' => $courseid]);
    echo html_writer::link($cancelurl, get_string('cancel'), ['class' => 'button is-light']);
}
echo html_writer::end_div();

echo html_writer::end_tag('form');

echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::end_div();
echo html_writer::end_div();

echo $OUTPUT->footer();
