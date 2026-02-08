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
 * AI grading dashboard view page.
 *
 * @package    local_hlai_grading
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require('../../config.php');

$courseid = optional_param('courseid', 0, PARAM_INT);
$instanceid = optional_param('assignid', 0, PARAM_INT); // Legacy param name used for any module instance.
$module = optional_param('module', 'assign', PARAM_ALPHA);
$supportedmodules = ['assign', 'quiz'];
if (!in_array($module, $supportedmodules, true)) {
    throw new moodle_exception('invalidmodule', 'local_hlai_grading');
}

$courseid = $courseid ?: ($instanceid ? (int)$DB->get_field($module, 'course', ['id' => $instanceid], IGNORE_MISSING) : 0);
if (!$courseid) {
    throw new moodle_exception('missingparam', 'error', '', 'courseid');
}

$course = get_course($courseid);
$activity = null;
$cm = null;

if ($instanceid) {
    $cm = get_coursemodule_from_instance($module, $instanceid, $course->id, false, MUST_EXIST);
    $activity = $DB->get_record($module, ['id' => $instanceid], '*', MUST_EXIST);
    require_course_login($course, false, $cm);
    $context = context_module::instance($cm->id);
} else {
    require_course_login($course);
    $context = context_course::instance($course->id);
}

require_capability('local/hlai_grading:viewresults', $context);
$cangrade = has_capability('local/hlai_grading:releasegrades', $context);

$urlparams = ['courseid' => $courseid, 'module' => $module];
if ($instanceid) {
    $urlparams['assignid'] = $instanceid;
}
$PAGE->set_url('/local/hlai_grading/view.php', $urlparams);
$PAGE->set_context($context);
$PAGE->set_title(get_string('dashboardheading', 'local_hlai_grading'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->requires->css('/local/hlai_grading/styles.css');

$resolvesource = function (?string $model): string {
    if (!$model) {
        return 'unknown';
    }
    if (
        strpos($model, 'proxy:') === 0 || strpos($model, 'aihub:') === 0
        || strpos($model, 'gateway:') === 0 || strpos($model, 'hub:') === 0
    ) {
        return 'aihub';
    }
    if (strpos($model, 'keymatch:') === 0 || strpos($model, 'local:') === 0) {
        return 'keymatch';
    }
    return 'unknown';
};

$baseparams = ['courseid' => $courseid, 'modname' => $module];
$wheresql = 'cm.course = :courseid AND r.modulename = :modname';

if ($instanceid) {
    $baseparams['assignid'] = $instanceid;
    $wheresql .= ' AND r.instanceid = :assignid';
}

$action = optional_param('action', '', PARAM_ALPHA);
if ($action === 'releaseall') {
    require_sesskey();
    require_capability('local/hlai_grading:releasegrades', $context);

    $drafts = $DB->get_records_sql("
        SELECT r.id
          FROM {local_hlai_grading_results} r
          JOIN {course_modules} cm ON cm.instance = r.instanceid
          JOIN {modules} m ON m.id = cm.module AND m.name = r.modulename
         WHERE r.status = 'draft'
           AND {$wheresql}
      ORDER BY r.timecreated ASC
    ", $baseparams);

    if (!$drafts) {
        redirect($PAGE->url, get_string('releaseallnone', 'local_hlai_grading'), null, \core\output\notification::NOTIFY_INFO);
    }

    $released = 0;
    $failed = 0;
    $errors = [];
    foreach ($drafts as $draft) {
        try {
            $bundle = \local_hlai_grading\local\result_service::get_result_context((int)$draft->id);
            \local_hlai_grading\local\result_service::release($bundle, $USER->id);
            $released++;
        } catch (\Throwable $e) {
            $failed++;
            if (count($errors) < 2) {
                $errors[] = $e->getMessage();
            }
        }
    }

    if ($failed > 0) {
        $msg = get_string('releaseallpartial', 'local_hlai_grading', (object)[
            'released' => $released, 'failed' => $failed,
        ]);
        if (!empty($errors)) {
            $msg .= ' ' . get_string('releaseallerror', 'local_hlai_grading', implode(' | ', $errors));
        }
        redirect($PAGE->url, $msg, null, \core\output\notification::NOTIFY_WARNING);
    }

    redirect(
        $PAGE->url,
        get_string('releaseallsuccess', 'local_hlai_grading', $released),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

echo $OUTPUT->header();
echo html_writer::start_div('local-hlai-grading local-hlai-iksha');

if ($activity) {
    $heading = get_string('dashboardassignmentheading', 'local_hlai_grading', format_string($activity->name));
} else {
    $heading = get_string('dashboardcourseheading', 'local_hlai_grading', format_string($course->fullname));
}

echo html_writer::start_div('hlai-page-header');
echo html_writer::tag('h2', $heading, ['class' => 'hlai-page-title']);
echo html_writer::end_div();

// Module filter tabs (assign vs quiz).
$modulelabels = [
    'assign' => get_string('pluginname', 'assign'), 'quiz' => get_string('pluginname', 'quiz'),
];
echo html_writer::start_div('tabs');
echo html_writer::start_tag('ul');
foreach ($supportedmodules as $modcode) {
    $taburl = new moodle_url('/local/hlai_grading/view.php', ['courseid' => $courseid, 'module' => $modcode]);
    if ($instanceid && $module === $modcode) {
        $taburl->param('assignid', $instanceid);
    }
    $classes = $module === $modcode ? 'is-active' : '';
    $label = $modulelabels[$modcode] ?? $modcode;
    echo html_writer::tag('li', html_writer::link($taburl, $label), ['class' => $classes]);
}
echo html_writer::end_tag('ul');
echo html_writer::end_div();

$studentfields = \core_user\fields::for_name();
$studentfieldslist = $studentfields->get_required_fields();
$studentfieldssql = $studentfields->get_sql('u', false, 'student');

$pendingparams = array_merge($baseparams, $studentfieldssql->params);

$pending = $DB->get_records_sql("
    SELECT r.*,
           cm.id AS cmid,
           u.id AS studentid
           {$studentfieldssql->selects}
      FROM {local_hlai_grading_results} r
      JOIN {user} u ON u.id = r.userid
      {$studentfieldssql->joins}
      JOIN {course_modules} cm ON cm.instance = r.instanceid
      JOIN {modules} m ON m.id = cm.module AND m.name = r.modulename
     WHERE r.status = 'draft'
       AND {$wheresql}
  ORDER BY r.timecreated DESC
", $pendingparams);

$rejectedparams = array_merge($baseparams, $studentfieldssql->params);
$rejected = $DB->get_records_sql("
    SELECT r.*,
           cm.id AS cmid,
           u.id AS studentid
           {$studentfieldssql->selects}
      FROM {local_hlai_grading_results} r
      JOIN {user} u ON u.id = r.userid
      {$studentfieldssql->joins}
      JOIN {course_modules} cm ON cm.instance = r.instanceid
      JOIN {modules} m ON m.id = cm.module AND m.name = r.modulename
     WHERE r.status = 'rejected'
       AND {$wheresql}
  ORDER BY r.timereviewed DESC, r.timecreated DESC
", $rejectedparams);

$reviewerfields = \core_user\fields::for_name();
$reviewerfieldslist = $reviewerfields->get_required_fields();
$reviewerfieldssql = $reviewerfields->get_sql('rev', false, 'reviewer');
$releasedparams = array_merge($baseparams, $studentfieldssql->params, $reviewerfieldssql->params);

$released = $DB->get_records_sql("
    SELECT r.*,
           u.id AS studentid,
           rev.id AS reviewerid
           {$studentfieldssql->selects}
           {$reviewerfieldssql->selects}
      FROM {local_hlai_grading_results} r
      JOIN {user} u ON u.id = r.userid
      {$studentfieldssql->joins}
      JOIN {course_modules} cm ON cm.instance = r.instanceid
      JOIN {modules} m ON m.id = cm.module AND m.name = r.modulename
 LEFT JOIN {user} rev ON rev.id = r.reviewer_id
     {$reviewerfieldssql->joins}
     WHERE r.status = 'released'
       AND {$wheresql}
  ORDER BY r.timereviewed DESC
    ", $releasedparams, 0, 50);

// Summary counts for KPI cards.
$countsql = "
    SELECT COUNT(1)
      FROM {local_hlai_grading_results} r
      JOIN {course_modules} cm ON cm.instance = r.instanceid
      JOIN {modules} m ON m.id = cm.module AND m.name = r.modulename
     WHERE r.status = :status
       AND {$wheresql}
";
$pendingcount = $DB->count_records_sql($countsql, array_merge($baseparams, ['status' => 'draft']));
$rejectedcount = $DB->count_records_sql($countsql, array_merge($baseparams, ['status' => 'rejected']));
$releasedcount = $DB->count_records_sql($countsql, array_merge($baseparams, ['status' => 'released']));

echo html_writer::start_div('iksha-kpi-grid iksha-kpi-grid--3 mb-4');
echo html_writer::start_div('iksha-kpi iksha-kpi--warning');
echo html_writer::div(
    html_writer::tag('i', '', ['class' => 'fa fa-hourglass-half', 'aria-hidden' => 'true']),
    'iksha-kpi__icon'
);
echo html_writer::start_div('iksha-kpi__content');
echo html_writer::tag('span', number_format($pendingcount), ['class' => 'iksha-kpi__value']);
echo html_writer::tag('span', get_string('dashboardpendingheading', 'local_hlai_grading'), ['class' => 'iksha-kpi__label']);
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::start_div('iksha-kpi iksha-kpi--warning');
echo html_writer::div(
    html_writer::tag('i', '', ['class' => 'fa fa-user-times', 'aria-hidden' => 'true']),
    'iksha-kpi__icon'
);
echo html_writer::start_div('iksha-kpi__content');
echo html_writer::tag('span', number_format($rejectedcount), ['class' => 'iksha-kpi__value']);
echo html_writer::tag('span', get_string('dashboardrejectedheading', 'local_hlai_grading'), ['class' => 'iksha-kpi__label']);
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::start_div('iksha-kpi iksha-kpi--success');
echo html_writer::div(
    html_writer::tag('i', '', ['class' => 'fa fa-check-circle', 'aria-hidden' => 'true']),
    'iksha-kpi__icon'
);
echo html_writer::start_div('iksha-kpi__content');
echo html_writer::tag('span', number_format($releasedcount), ['class' => 'iksha-kpi__value']);
echo html_writer::tag('span', get_string('dashboardreleasedheading', 'local_hlai_grading'), ['class' => 'iksha-kpi__label']);
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::start_div('iksha-widget mb-4');
echo html_writer::start_tag('header', ['class' => 'iksha-widget__header']);
echo html_writer::start_div('iksha-widget__title-group');
echo html_writer::div(
    html_writer::tag('i', '', ['class' => 'fa fa-inbox', 'aria-hidden' => 'true']),
    'iksha-widget__icon iksha-widget__icon--warning'
);
echo html_writer::tag('h3', get_string('dashboardpendingheading', 'local_hlai_grading'), ['class' => 'iksha-widget__title']);
echo html_writer::end_div();
echo html_writer::tag('span', number_format($pendingcount), ['class' => 'iksha-badge iksha-badge--warning iksha-badge--sm']);
echo html_writer::end_tag('header');
echo html_writer::start_div('iksha-widget__body');

if (!$pending) {
    echo html_writer::tag(
        'p',
        get_string('dashboardnopending', 'local_hlai_grading'),
        ['class' => 'hlai-muted mb-0']
    );
} else {
    echo html_writer::tag(
        'p',
        get_string('dashboardpendingcount', 'local_hlai_grading', count($pending)),
        ['class' => 'hlai-muted']
    );
    if ($cangrade) {
        $releaseallurl = new moodle_url('/local/hlai_grading/view.php', $urlparams);
        echo html_writer::start_tag('form', [
            'method' => 'post', 'action' => $releaseallurl->out(false), 'class' => 'mb-3',
        ]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'releaseall']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        echo html_writer::tag('button', get_string('reviewreleasebutton', 'local_hlai_grading', count($pending)), [
            'type' => 'submit', 'class' => 'button is-primary is-small',
        ]);
        echo html_writer::end_tag('form');
    }

    echo html_writer::start_div('iksha-table-wrapper');
    echo html_writer::start_tag('table', ['class' => 'iksha-table generaltable', 'id' => 'aigrading-drafts']);
    echo html_writer::start_tag('thead');
    echo html_writer::start_tag('tr');
    echo html_writer::tag('th', get_string('columnstudent', 'local_hlai_grading'));
    echo html_writer::tag('th', get_string('columngrade', 'local_hlai_grading'));
    echo html_writer::tag('th', get_string('columnsource', 'local_hlai_grading'));
    echo html_writer::tag('th', get_string('gradedon', 'local_hlai_grading'));
    echo html_writer::tag('th', get_string('status', 'core'));
    if ($cangrade) {
        echo html_writer::tag('th', get_string('columnactions', 'local_hlai_grading'));
    }
    echo html_writer::end_tag('tr');
    echo html_writer::end_tag('thead');
    echo html_writer::start_tag('tbody');

    foreach ($pending as $result) {
        $student = (object)['id' => $result->studentid];
        foreach ($studentfieldslist as $fieldname) {
            $prop = 'student' . $fieldname;
            if (property_exists($result, $prop)) {
                $student->$fieldname = $result->$prop;
            }
        }
        $username = fullname($student);
        $gradetext = format_float($result->grade, 2) . ' / ' . format_float($result->maxgrade, 2);
        $source = $resolvesource($result->model ?? '');
        $sourcelabel = get_string('source_' . $source, 'local_hlai_grading');
        $graded = userdate($result->timecreated);

        echo html_writer::start_tag('tr');
        echo html_writer::tag('td', s($username));
        echo html_writer::tag('td', $gradetext);
        echo html_writer::tag('td', s($sourcelabel));
        echo html_writer::tag('td', html_writer::tag('span', $graded, ['class' => 'iksha-table__meta']));
        echo html_writer::tag('td', html_writer::tag('span', get_string('badgedraft', 'local_hlai_grading'), [
            'class' => 'iksha-badge iksha-badge--warning iksha-badge--sm',
        ]));

        if ($cangrade) {
            $reviewurl = new moodle_url('/local/hlai_grading/release.php', ['id' => $result->id]);
            $reviewbtn = html_writer::link($reviewurl, get_string('dashboardreviewbtn', 'local_hlai_grading'), [
                'class' => 'button is-primary is-small',
            ]);
            echo html_writer::tag('td', $reviewbtn);
        }

        echo html_writer::end_tag('tr');
    }

    echo html_writer::end_tag('tbody');
    echo html_writer::end_tag('table');
    echo html_writer::end_div();
}

echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::start_div('iksha-widget mb-4');
echo html_writer::start_tag('header', ['class' => 'iksha-widget__header']);
echo html_writer::start_div('iksha-widget__title-group');
echo html_writer::div(
    html_writer::tag('i', '', ['class' => 'fa fa-user-slash', 'aria-hidden' => 'true']),
    'iksha-widget__icon iksha-widget__icon--warning'
);
echo html_writer::tag('h3', get_string('dashboardrejectedheading', 'local_hlai_grading'), ['class' => 'iksha-widget__title']);
echo html_writer::end_div();
echo html_writer::tag(
    'span',
    number_format($rejectedcount),
    ['class' => 'iksha-badge iksha-badge--warning iksha-badge--sm']
);
echo html_writer::end_tag('header');
echo html_writer::start_div('iksha-widget__body');

if ($rejected) {
    echo html_writer::tag(
        'p',
        get_string('dashboardrejectedcount', 'local_hlai_grading', count($rejected)),
        ['class' => 'hlai-muted']
    );

    $returnurl = new moodle_url('/local/hlai_grading/view.php', $urlparams);
    $returnurl->set_anchor('aigrading-rejected');
    $returnpath = $returnurl->out_as_local_url(false);

    echo html_writer::start_div('iksha-table-wrapper');
    echo html_writer::start_tag('table', ['class' => 'iksha-table generaltable', 'id' => 'aigrading-rejected']);
    echo html_writer::start_tag('thead');
    echo html_writer::start_tag('tr');
    echo html_writer::tag('th', get_string('columnstudent', 'local_hlai_grading'));
    echo html_writer::tag('th', get_string('columngrade', 'local_hlai_grading'));
    echo html_writer::tag('th', get_string('columnsource', 'local_hlai_grading'));
    echo html_writer::tag('th', get_string('rejectedon', 'local_hlai_grading'));
    echo html_writer::tag('th', get_string('status', 'core'));
    if ($cangrade) {
        echo html_writer::tag('th', get_string('columnactions', 'local_hlai_grading'));
    }
    echo html_writer::end_tag('tr');
    echo html_writer::end_tag('thead');
    echo html_writer::start_tag('tbody');

    foreach ($rejected as $result) {
        $student = (object)['id' => $result->studentid];
        foreach ($studentfieldslist as $fieldname) {
            $prop = 'student' . $fieldname;
            if (property_exists($result, $prop)) {
                $student->$fieldname = $result->$prop;
            }
        }
        $username = fullname($student);
        $gradetext = format_float($result->grade, 2) . ' / ' . format_float($result->maxgrade, 2);
        $source = $resolvesource($result->model ?? '');
        $sourcelabel = get_string('source_' . $source, 'local_hlai_grading');
        $rejectedon = $result->timereviewed ? userdate($result->timereviewed) : userdate($result->timecreated);

        $manualurl = null;
        if ($cangrade) {
            if ($result->modulename === 'assign') {
                $manualurl = new moodle_url('/mod/assign/view.php', [
                    'id' => $result->cmid, 'action' => 'grade', 'userid' => $result->userid, 'hlai_return' => $returnpath,
                ]);
            } else if ($result->modulename === 'quiz') {
                $manualparams = [
                    'id' => $result->cmid, 'mode' => 'grading', 'hlai_return' => $returnpath,
                ];
                if (!empty($result->slot)) {
                    $manualparams['slot'] = (int)$result->slot;
                }
                if (!empty($result->attemptid)) {
                    $manualparams['attempt'] = (int)$result->attemptid;
                }
                $manualurl = new moodle_url('/mod/quiz/report.php', $manualparams);
            }
        }

        echo html_writer::start_tag('tr');
        echo html_writer::tag('td', s($username));
        echo html_writer::tag('td', $gradetext);
        echo html_writer::tag('td', s($sourcelabel));
        echo html_writer::tag('td', html_writer::tag('span', $rejectedon, ['class' => 'iksha-table__meta']));
        echo html_writer::tag('td', html_writer::tag('span', get_string('badgerejected', 'local_hlai_grading'), [
            'class' => 'iksha-badge iksha-badge--neutral iksha-badge--sm',
        ]));

        if ($cangrade) {
            if ($manualurl) {
                $manualbtn = html_writer::link($manualurl, get_string('dashboardmanualgradebtn', 'local_hlai_grading'), [
                    'class' => 'button is-light is-small',
                ]);
            } else {
                $manualbtn = html_writer::tag(
                    'span',
                    get_string('manualgradingneeded', 'local_hlai_grading'),
                    ['class' => 'hlai-muted']
                );
            }
            echo html_writer::tag('td', $manualbtn);
        }

        echo html_writer::end_tag('tr');
    }

    echo html_writer::end_tag('tbody');
    echo html_writer::end_tag('table');
    echo html_writer::end_div();
} else {
    echo html_writer::tag('p', get_string('dashboardnorejected', 'local_hlai_grading'), ['class' => 'hlai-muted mb-0']);
}

echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::start_div('iksha-widget mb-4');
echo html_writer::start_tag('header', ['class' => 'iksha-widget__header']);
echo html_writer::start_div('iksha-widget__title-group');
echo html_writer::div(
    html_writer::tag('i', '', ['class' => 'fa fa-check-circle', 'aria-hidden' => 'true']),
    'iksha-widget__icon iksha-widget__icon--success'
);
echo html_writer::tag('h3', get_string('dashboardreleasedheading', 'local_hlai_grading'), ['class' => 'iksha-widget__title']);
echo html_writer::end_div();
echo html_writer::tag('span', number_format($releasedcount), ['class' => 'iksha-badge iksha-badge--success iksha-badge--sm']);
echo html_writer::end_tag('header');
echo html_writer::start_div('iksha-widget__body');

if ($released) {
    echo html_writer::start_div('iksha-table-wrapper');
    echo html_writer::start_tag('table', ['class' => 'iksha-table generaltable']);
    echo html_writer::start_tag('thead');
    echo html_writer::start_tag('tr');
    echo html_writer::tag('th', get_string('columnstudent', 'local_hlai_grading'));
    echo html_writer::tag('th', get_string('columngrade', 'local_hlai_grading'));
    echo html_writer::tag('th', get_string('columnsource', 'local_hlai_grading'));
    echo html_writer::tag('th', get_string('releasedby', 'local_hlai_grading'));
    echo html_writer::tag('th', get_string('releasedon', 'local_hlai_grading'));
    echo html_writer::end_tag('tr');
    echo html_writer::end_tag('thead');
    echo html_writer::start_tag('tbody');

    foreach ($released as $record) {
        $student = (object)['id' => $record->studentid];
        foreach ($studentfieldslist as $fieldname) {
            $prop = 'student' . $fieldname;
            if (property_exists($record, $prop)) {
                $student->$fieldname = $record->$prop;
            }
        }

        $reviewername = get_string('systemuser', 'local_hlai_grading');
        if (!empty($record->reviewerid)) {
            $reviewer = (object)['id' => $record->reviewerid];
            foreach ($reviewerfieldslist as $fieldname) {
                $prop = 'reviewer' . $fieldname;
                if (property_exists($record, $prop)) {
                    $reviewer->$fieldname = $record->$prop;
                }
            }
            $reviewername = fullname($reviewer);
        }

        $source = $resolvesource($record->model ?? '');
        $sourcelabel = get_string('source_' . $source, 'local_hlai_grading');

        echo html_writer::start_tag('tr');
        echo html_writer::tag('td', s(fullname($student)));
        echo html_writer::tag('td', format_float($record->grade, 2) . ' / ' . format_float($record->maxgrade, 2));
        echo html_writer::tag('td', s($sourcelabel));
        echo html_writer::tag('td', s($reviewername));
        echo html_writer::tag('td', html_writer::tag('span', userdate($record->timereviewed), ['class' => 'iksha-table__meta']));
        echo html_writer::end_tag('tr');
    }

    echo html_writer::end_tag('tbody');
    echo html_writer::end_tag('table');
    echo html_writer::end_div();
} else {
    echo html_writer::tag('p', get_string('dashboardnoreleased', 'local_hlai_grading'), ['class' => 'hlai-muted mb-0']);
}

echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::end_div();
echo $OUTPUT->footer();
