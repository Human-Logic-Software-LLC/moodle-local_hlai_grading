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
 * Library functions for the AI grading plugin.
 *
 * @package    local_hlai_grading
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Extend the global navigation with AI grading links.
 *
 * @param global_navigation $navigation The navigation object.
 */
function local_hlai_grading_extend_navigation(global_navigation $navigation) {
    global $PAGE;

    $context = $PAGE->context ?? context_system::instance();
    if (!has_capability('local/hlai_grading:viewresults', $context)) {
        return;
    }

    $courseid = (!empty($PAGE->course) && !empty($PAGE->course->id)) ? (int)$PAGE->course->id : 0;
    if (!$courseid) {
        return;
    }

    // Link to the new metrics dashboard.
    $urlparams = ['courseid' => $courseid];

    $node = $navigation->add(
        get_string('navdashboard', 'local_hlai_grading'),
        new moodle_url('/local/hlai_grading/dashboard.php', $urlparams),
        navigation_node::TYPE_CUSTOM,
        null,
        'aigrading',
        new pix_icon('i/grades', '')
    );
    $node->showinflatnavigation = true;
}

/**
 * Add AI Grading to settings navigation (for teachers/admins).
 *
 * @param settings_navigation $settingsnav Settingsnav.
 * @param context $context Context.
 */
function local_hlai_grading_extend_settings_navigation(settings_navigation $settingsnav, context $context) {
    global $PAGE;

    // Add to site administration.
    if ($context->contextlevel == CONTEXT_SYSTEM) {
        // Try common node names (theme-dependent).
        $systemnode = $settingsnav->find('siteadmin', navigation_node::TYPE_SITE_ADMIN);
        if (!$systemnode) {
            $systemnode = $settingsnav->find('root', navigation_node::TYPE_SITE_ADMIN);
        }

        if ($systemnode && has_capability('local/hlai_grading:configure', $context)) {
            $node = navigation_node::create(
                get_string('navdashboard', 'local_hlai_grading'),
                new moodle_url('/local/hlai_grading/dashboard.php'),
                navigation_node::TYPE_CUSTOM,
                null,
                'aigrading',
                new pix_icon('i/grades', '')
            );
            $systemnode->add_node($node);
        }
    }

    // Add to course administration when in assignment.
    if ($PAGE->cm && in_array($PAGE->cm->modname, ['assign', 'quiz'], true)) {
        $coursenode = $settingsnav->find('courseadmin', navigation_node::TYPE_COURSE);
        if ($coursenode && has_capability('local/hlai_grading:releasegrades', $context)) {
            $node = navigation_node::create(
                get_string('navassignment', 'local_hlai_grading'),
                new moodle_url('/local/hlai_grading/view.php', [
                    'courseid' => $PAGE->course->id,
                    'assignid' => $PAGE->cm->instance,
                    'module' => $PAGE->cm->modname,
                ]),
                navigation_node::TYPE_CUSTOM,
                null,
                'aigrading'
            );
            $coursenode->add_node($node);
            if ($PAGE->cm->modname === 'quiz' && has_capability('mod/quiz:manage', $context)) {
                $rubricnode = navigation_node::create(
                    get_string('quizrubric_select', 'local_hlai_grading'),
                    new moodle_url('/local/hlai_grading/quiz_rubric_select.php', [
                        'cmid' => $PAGE->cm->id,
                        'courseid' => $PAGE->course->id,
                    ]),
                    navigation_node::TYPE_CUSTOM,
                    null,
                    'aigrading_quiz_rubric'
                );
                $coursenode->add_node($rubricnode);
            }
        }
    }
}

/**
 * Add AI Grading to course navigation.
 *
 * @param navigation_node $navigation Navigation.
 * @param stdClass $course Course.
 * @param context_course $context Context.
 */
function local_hlai_grading_extend_navigation_course(navigation_node $navigation, stdClass $course, context_course $context) {
    if (has_capability('local/hlai_grading:releasegrades', $context)) {
        // Review & release page.
        $navigation->add(
            get_string('navcourse', 'local_hlai_grading'),
            new moodle_url('/local/hlai_grading/view.php', ['courseid' => $course->id, 'module' => 'assign']),
            navigation_node::TYPE_CUSTOM,
            null,
            'aigrading_view'
        );

        // Dashboard page.
        $navigation->add(
            get_string('gradingdashboard', 'local_hlai_grading'),
            new moodle_url('/local/hlai_grading/dashboard.php', ['courseid' => $course->id]),
            navigation_node::TYPE_CUSTOM,
            null,
            'aigrading_dashboard'
        );
    }
}

/**
 * Load common assets before headers are sent.
 */
function local_hlai_grading_before_http_headers() {
    global $PAGE, $USER, $CFG, $DB;

    if (!empty($PAGE->cm) && $PAGE->cm->modname === 'assign') {
        $PAGE->requires->css('/local/hlai_grading/styles.css');

        if (!isloggedin() || isguestuser()) {
            return;
        }

        // Only inject the explainability UI on the student view page.
        if ($PAGE->pagetype !== 'mod-assign-view') {
            return;
        }

        $submissions = $DB->get_records(
            'assign_submission',
            [
                'assignment' => $PAGE->cm->instance,
                'userid' => $USER->id,
            ],
            'attemptnumber DESC, timemodified DESC, id DESC',
            '*',
            0,
            1
        );

        if (empty($submissions)) {
            return;
        }

        $submission = reset($submissions);

        $hasreleased = $DB->record_exists('local_hlai_grading_results', [
            'submissionid' => $submission->id,
            'status' => 'released',
        ]);

        if (!$hasreleased) {
            $hasreleased = $DB->record_exists('local_hlai_grading_results', [
                'userid' => $USER->id,
                'modulename' => 'assign',
                'instanceid' => $PAGE->cm->instance,
                'status' => 'released',
            ]);
        }

        if ($hasreleased) {
            $PAGE->requires->js_call_amd('local_hlai_grading/grade_explanation', 'init', [
                'submissionid' => (int)$submission->id,
            ]);
        }
    }

    if (
        !empty($PAGE->cm) && $PAGE->cm->modname === 'quiz' && $PAGE->url &&
        strpos($PAGE->url->get_path(), '/mod/quiz/review.php') !== false
    ) {
        $PAGE->requires->css('/local/hlai_grading/styles.css');
    }
}

/**
 * Add AI grading elements to assignment settings forms.
 *
 * @param moodleform_mod $formwrapper Formwrapper.
 * @param MoodleQuickForm $mform Mform.
 */
function local_hlai_grading_coursemodule_standard_elements($formwrapper, $mform) {
    $current = method_exists($formwrapper, 'get_current') ? $formwrapper->get_current() : null;
    $modulename = '';
    if ($current) {
        $modulename = $current->modulename ?? ($current->modname ?? '');
    }
    if (!$modulename && method_exists($formwrapper, 'get_coursemodule')) {
        if ($cm = $formwrapper->get_coursemodule()) {
            $modulename = $cm->modname ?? '';
        }
    }

    $modulename = $modulename ?: ($formwrapper->get_modulename() ?? '');
    if (!in_array($modulename, ['assign', 'quiz'], true)) {
        return;
    }

    $instanceid = isset($current->instance) ? (int)$current->instance : 0;
    $defaults = local_hlai_grading_get_activity_settings($modulename, $instanceid);

    $mform->addElement('header', 'hlai_grading_assign_header', get_string('assignsettingsheader', 'local_hlai_grading'));

    $mform->addElement('advcheckbox', 'hlai_enable', get_string('assignsettingsenable', 'local_hlai_grading'));
    $mform->addHelpButton('hlai_enable', 'assignsettingsenable', 'local_hlai_grading');

    $textareaoptions = [
        'rows' => 5,
        'cols' => 60,
        'placeholder' => get_string('assignsettingsinstructionsplaceholder', 'local_hlai_grading'),
    ];
    $mform->addElement(
        'textarea',
        'hlai_custominstructions',
        get_string('assignsettingsinstructions', 'local_hlai_grading'),
        $textareaoptions
    );
    $mform->addHelpButton('hlai_custominstructions', 'assignsettingsinstructions', 'local_hlai_grading');
    $mform->setType('hlai_custominstructions', PARAM_RAW);

    $mform->addElement('advcheckbox', 'hlai_autorelease', get_string('assignsettingsautorelease', 'local_hlai_grading'));
    $mform->addHelpButton('hlai_autorelease', 'assignsettingsautorelease', 'local_hlai_grading');

    if ($modulename === 'quiz') {
        $courseid = 0;
        if (!empty($current->course)) {
            $courseid = (int)$current->course;
        } else if (method_exists($formwrapper, 'get_course')) {
            $course = $formwrapper->get_course();
            $courseid = $course ? (int)$course->id : 0;
        }

        $cm = method_exists($formwrapper, 'get_coursemodule') ? $formwrapper->get_coursemodule() : null;
        $context = $cm ? context_module::instance($cm->id)
            : ($courseid ? context_course::instance($courseid) : context_system::instance());
        $rubrics = local_hlai_grading_get_quiz_rubrics($courseid, $context);

        $options = [0 => get_string('quizrubric_select_none', 'local_hlai_grading')];
        foreach ($rubrics as $rubric) {
            $label = format_string($rubric->name);
            if ($rubric->visibility === 'global') {
                $label .= ' (' . get_string('quizrubric_visibility_global', 'local_hlai_grading') . ')';
            }
            $options[$rubric->id] = $label;
        }

        $mform->addElement('select', 'hlai_rubricid', get_string('quizrubric_select_rubric', 'local_hlai_grading'), $options);
        $mform->setType('hlai_rubricid', PARAM_INT);

        $canmanagerubrics = has_capability('local/hlai_grading:configure', $context);
        if ($canmanagerubrics) {
            $manageurl = new moodle_url('/local/hlai_grading/quiz_rubrics.php', ['courseid' => $courseid]);
            $mform->addElement('static', 'hlai_rubric_manage', '', html_writer::link(
                $manageurl,
                get_string('quizrubric_manage_link', 'local_hlai_grading'),
                ['class' => 'btn btn-secondary']
            ));
        }
        $mform->addElement('static', 'hlai_rubric_help', '', html_writer::tag(
            'span',
            get_string('quizrubric_select_help', 'local_hlai_grading'),
            ['class' => 'form-text text-muted']
        ));
    }

    $mform->hideIf('hlai_custominstructions', 'hlai_enable', 'notchecked');
    $mform->hideIf('hlai_autorelease', 'hlai_enable', 'notchecked');
    if ($modulename === 'quiz') {
        $mform->hideIf('hlai_rubricid', 'hlai_enable', 'notchecked');
        if (!empty($canmanagerubrics)) {
            $mform->hideIf('hlai_rubric_manage', 'hlai_enable', 'notchecked');
        }
        $mform->hideIf('hlai_rubric_help', 'hlai_enable', 'notchecked');
    }

    $mform->setDefault('hlai_enable', $defaults->enabled ?? 0);
    $mform->setDefault('hlai_custominstructions', $defaults->custominstructions ?? '');
    $mform->setDefault('hlai_autorelease', $defaults->autorelease ?? 0);
    if ($modulename === 'quiz') {
        $mform->setDefault('hlai_rubricid', $defaults->rubricid ?? 0);
    }
}

/**
 * Persist AI grading settings after the assignment form is submitted.
 *
 * @param stdClass $data Data.
 * @param stdClass $course Course.
 * @return stdClass The object.
 */
function local_hlai_grading_coursemodule_edit_post_actions($data, $course) {
    global $DB;

    $modulename = $data->modulename ?? ($data->modname ?? '');
    if (!in_array($modulename, ['assign', 'quiz'], true)) {
        return $data;
    }

    $instanceid = isset($data->instance) ? (int)$data->instance : 0;

    // When creating a brand-new module the instance id may not yet be populated.
    // Fall back to resolving it from the course module id so we store settings against
    // the real module instance (not the cmid).
    $cmid = !empty($data->coursemodule) ? (int)$data->coursemodule : (!empty($data->id) ? (int)$data->id : 0);
    if (!$instanceid && $cmid) {
        $instanceid = (int)$DB->get_field('course_modules', 'instance', ['id' => $cmid]);
    }

    if (!$instanceid) {
        return $data;
    }

    $enabled = empty($data->hlai_enable) ? 0 : 1;
    $quality = get_config('local_hlai_grading', 'defaultquality') ?: 'balanced';

    $instructions = $enabled ? trim($data->hlai_custominstructions ?? '') : '';
    $autorelease = ($enabled && !empty($data->hlai_autorelease)) ? 1 : 0;
    $rubricid = null;
    if ($modulename === 'quiz') {
        $candidate = !empty($data->hlai_rubricid) ? (int)$data->hlai_rubricid : 0;
        if ($candidate > 0) {
            $context = null;
            if ($cmid) {
                $context = context_module::instance($cmid);
            } else {
                $context = context_course::instance($course->id);
            }
            $available = local_hlai_grading_get_quiz_rubrics((int)$course->id, $context);
            if (isset($available[$candidate])) {
                $rubricid = $candidate;
            }
        }
    }

    local_hlai_grading_save_activity_settings($modulename, $instanceid, $enabled, $quality, $instructions, $autorelease, $rubricid);

    return $data;
}

/**
 * Inject AI grading status into assignment grading table.
 * This hook is called when rendering the assignment grading table
 *
 * @param object $data Data object with submission info
 * @return string HTML to inject
 */
function local_hlai_grading_assign_grading_table($data) {
    global $PAGE;

    if (!isset($data->userid) || !isset($data->assignment)) {
        return '';
    }

    $renderer = $PAGE->get_renderer('local_hlai_grading');
    return $renderer->render_ai_status_badge($data->userid, $data->assignment->id);
}

/**
 * Add AI grading summary banner to assignment grading page.
 *
 * @param int $assignid Assignment ID
 * @return string HTML banner
 */
function local_hlai_grading_assign_grading_summary($assignid) {
    global $PAGE;

    $renderer = $PAGE->get_renderer('local_hlai_grading');
    return $renderer->render_ai_summary_banner($assignid);
}

/**
 * Inject JavaScript into assignment pages to add AI status column.
 *
 * @return string The HTML output.
 */
function local_hlai_grading_before_footer() {
    global $PAGE, $USER, $DB;

    $output = '';

    // Only inject on assignment grading pages when the user can manage grades.
    if (
        ($PAGE->pagetype === 'mod-assign-view' ||
        $PAGE->pagetype === 'mod-assign-grading' ||
        strpos($PAGE->url->get_path(), '/mod/assign/') !== false) &&
        $PAGE->cm &&
        has_capability('mod/assign:grade', $PAGE->context)
    ) {
        $PAGE->requires->js_call_amd('local_hlai_grading/ai_grading_integration', 'init');
    }

    // Append rubric previews only on the assignment view page so other contexts remain untouched.
    if ($PAGE->pagetype === 'mod-assign-view' && $PAGE->cm && $PAGE->cm->modname === 'assign') {
        $courseid = !empty($PAGE->course->id) ? (int)$PAGE->course->id : 0;
        if ($courseid) {
            $context = $PAGE->context ?? context_module::instance($PAGE->cm->id);
            $isgrader = has_capability('mod/assign:grade', $context);
            $actingas = \core\session\manager::is_loggedinas();
            $assignid = (int)$PAGE->cm->instance;
            $hasrelease = $DB->record_exists('local_hlai_grading_results', [
                'modulename' => 'assign',
                'instanceid' => $assignid,
                'userid' => (int)$USER->id,
                'status' => 'released',
            ]);
            if (!$isgrader || $actingas || $hasrelease) {
                $renderer = $PAGE->get_renderer('local_hlai_grading');
                $output .= $renderer->render_assignment_rubric_preview($PAGE->cm, $courseid, (int)$USER->id);
            }
        }
    }

    if (
        $PAGE->cm && $PAGE->cm->modname === 'quiz' && $PAGE->url &&
        strpos($PAGE->url->get_path(), '/mod/quiz/review.php') !== false
    ) {
        $attemptid = optional_param('attempt', 0, PARAM_INT);
        if (!$attemptid) {
            $attemptid = optional_param('attemptid', 0, PARAM_INT);
        }
        if ($attemptid) {
            $summary = \local_hlai_grading\local\quiz_summary::render_summary_card($attemptid, $PAGE->context);
            if ($summary !== '') {
                $output .= $summary;
            }
        }
    }

    return $output;
}

/**
 * Inject AI grading banner at top of assignment grading pages.
 * This hook adds a prominent link to view AI grading results
 *
 * @return string HTML to inject
 */
function local_hlai_grading_before_standard_top_of_body_html() {
    global $PAGE, $DB, $SESSION;

    $output = '';
    $currenturl = $PAGE->url ? $PAGE->url->out_as_local_url(false) : '';
    $ispluginpage = (strpos($currenturl, '/local/hlai_grading/') === 0);

    $clearreturn = optional_param('hlai_return_clear', 0, PARAM_BOOL);
    if ($clearreturn) {
        unset($SESSION->hlai_grading_return_url);
    }

    $returnurl = optional_param('hlai_return', '', PARAM_LOCALURL);
    if (!empty($returnurl)) {
        $SESSION->hlai_grading_return_url = $returnurl;
    }

    if (!$ispluginpage && !empty($SESSION->hlai_grading_return_url)) {
        $backurl = new moodle_url($SESSION->hlai_grading_return_url, ['hlai_return_clear' => 1]);
        $output .= \html_writer::start_div('alert alert-warning mb-3 ai-grading-return-banner');
        $output .= \html_writer::tag('strong', get_string('returntoreview', 'local_hlai_grading'));
        $output .= \html_writer::link($backurl, get_string('returntoreviewbutton', 'local_hlai_grading'), [
            'class' => 'btn btn-secondary btn-sm ml-2',
        ]);
        $output .= \html_writer::end_div();
    }

    if ($ispluginpage) {
        return $output;
    }

    try {
        $cm = $PAGE->cm;
    } catch (\Throwable $e) {
        $cm = null;
    }

    // Show the banner on any assignment page (summary, submission list, etc.) as long as the user is not editing.
    $editingflag = $PAGE->user_is_editing();
    $hascm = !empty($cm) && !empty($cm->id);
    $ismodassign = $hascm && (($cm->modname ?? '') === 'assign');
    if ($editingflag || !$hascm || !$ismodassign) {
        return $output;
    }

    static $injected = false;
    if ($injected) {
        return $output;
    }
    $injected = true;

    $hasgradecap = has_capability('mod/assign:grade', $PAGE->context);
    if (!$hasgradecap) {
        return $output;
    }

    $instanceid = $cm->instance;
    $modulename = $cm->modname;
    $courseid = $PAGE->course->id;

    // Count pending reviews for this module instance.
    $pending = $DB->count_records('local_hlai_grading_results', [
        'instanceid' => $instanceid,
        'modulename' => $modulename,
        'status' => 'draft',
    ]);
    if ($pending == 0) {
        return $output;
    }

    // Build the banner.
    $html = \html_writer::start_div('ai-grading-banner-wrapper', ['id' => 'ai-grading-banner']);
    $html .= \html_writer::start_div('ai-grading-banner');
    $html .= \html_writer::start_div('ai-grading-banner__icon', ['aria-hidden' => 'true']);
    $html .= \html_writer::tag('span', 'AI', ['class' => 'ai-grading-banner__icon-text']);
    $html .= \html_writer::end_div();

    $html .= \html_writer::start_div('ai-grading-banner__content');
    $html .= \html_writer::tag('h5', get_string('bannerheading', 'local_hlai_grading'), [
        'class' => 'ai-grading-banner__title',
    ]);
    $pendingtext = get_string(
        $pending == 1 ? 'bannerpendingone' : 'bannerpending',
        'local_hlai_grading',
        $pending
    );
    $html .= \html_writer::tag('p', $pendingtext, ['class' => 'ai-grading-banner__text']);
    $html .= \html_writer::end_div();

    $url = new moodle_url('/local/hlai_grading/view.php', [
        'courseid' => $courseid,
        'assignid' => $instanceid,
        'module' => $modulename,
    ]);
    $action = \html_writer::link(
        $url,
        get_string('bannerreviewbutton', 'local_hlai_grading'),
        ['class' => 'btn btn-primary']
    );
    $html .= \html_writer::div($action, 'ai-grading-banner__actions');

    $html .= \html_writer::end_div();
    $html .= \html_writer::end_div();

    $html .= \html_writer::tag(
        'script',
        "document.addEventListener('DOMContentLoaded',function (){" .
            "var banner=document.getElementById('ai-grading-banner');" .
            "var page=document.getElementById('page');" .
            "if(banner&&page&&!page.contains(banner)){" .
            "page.insertBefore(banner,page.firstChild);}});",
        ['type' => 'text/javascript']
    );

    $output .= $html;
    return $output;
}

/**
 * Fetch stored settings for an assignment, falling back to defaults.
 *
 * @param string $modulename The module name (assign or quiz).
 * @param int $instanceid The module instance ID.
 * @return stdClass The settings object.
 */
function local_hlai_grading_get_activity_settings(string $modulename, int $instanceid): stdClass {
    global $DB;

    $defaultquality = get_config('local_hlai_grading', 'defaultquality') ?: 'balanced';
    $defaults = (object)[
        'modulename' => $modulename,
        'instanceid' => $instanceid,
        'enabled' => 0,
        'quality' => $defaultquality,
        'custominstructions' => '',
        'autorelease' => 0,
        'rubricid' => null,
    ];

    if ($instanceid <= 0 || empty($modulename)) {
        return $defaults;
    }

    $criteria = ['modulename' => $modulename, 'instanceid' => $instanceid];
    $record = $DB->get_record('local_hlai_grading_act_settings', $criteria);
    if ($record) {
        return $record;
    }

    return $defaults;
}

/**
 * Persist activity-level AI settings.
 *
 * @param string $modulename The module name (assign or quiz).
 * @param int $instanceid The module instance ID.
 * @param int $enabled Whether AI grading is enabled.
 * @param string $quality The AI quality level.
 * @param string $instructions Custom grading instructions.
 * @param int $autorelease Whether to auto-release grades.
 * @param int|null $rubricid Optional rubric ID for quiz grading.
 * @return void
 */
function local_hlai_grading_save_activity_settings(
    string $modulename,
    int $instanceid,
    int $enabled,
    string $quality,
    string $instructions,
    int $autorelease,
    ?int $rubricid = null
): void {
    global $DB;

    if ($instanceid <= 0 || empty($modulename)) {
        return;
    }

    $now = time();
    $criteria = ['modulename' => $modulename, 'instanceid' => $instanceid];
    $record = $DB->get_record('local_hlai_grading_act_settings', $criteria);

    if ($record) {
        $record->enabled = $enabled;
        $record->quality = $quality;
        $record->custominstructions = $instructions;
        $record->autorelease = $autorelease;
        $record->rubricid = $rubricid;
        $record->timemodified = $now;
        $DB->update_record('local_hlai_grading_act_settings', $record);
    } else {
        $insert = (object)[
            'modulename' => $modulename,
            'instanceid' => $instanceid,
            'enabled' => $enabled,
            'quality' => $quality,
            'custominstructions' => $instructions,
            'autorelease' => $autorelease,
            'rubricid' => $rubricid,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $DB->insert_record('local_hlai_grading_act_settings', $insert);
    }
}

/**
 * Determine whether AI grading is enabled for a given activity.
 *
 * @param string $modulename The module name (assign or quiz).
 * @param int $instanceid The module instance ID.
 * @return bool True if AI grading is enabled.
 */
function local_hlai_grading_is_activity_enabled(string $modulename, int $instanceid): bool {
    if (function_exists('hlai_gradingfields_is_enabled')) {
        return hlai_gradingfields_is_enabled($modulename, $instanceid);
    }
    $settings = local_hlai_grading_get_activity_settings($modulename, $instanceid);
    return !empty($settings->enabled);
}

/**
 * Fetch quiz rubrics available for a course (plus global) filtered by capability.
 *
 * @param int $courseid Courseid.
 * @param context $context Context.
 * @return array rubric records
 */
function local_hlai_grading_get_quiz_rubrics(int $courseid, context $context): array {
    global $DB;

    $canview = has_capability('local/hlai_grading:configure', $context) ||
        has_capability('mod/quiz:manage', $context);
    if (!$canview) {
        return [];
    }

    if ($courseid > 0) {
        $sql = 'courseid = :courseid OR courseid IS NULL';
        $params = ['courseid' => $courseid];
    } else {
        $sql = 'courseid IS NULL';
        $params = [];
    }

    return $DB->get_records_select('local_hlai_grading_quiz_rubric', $sql, $params, 'timemodified DESC');
}

/**
 * Fetch quiz rubric items for a rubric id.
 *
 * @param int $rubricid Rubricid.
 * @return array item records
 */
function local_hlai_grading_get_quiz_rubric_items(int $rubricid): array {
    global $DB;

    if ($rubricid <= 0) {
        return [];
    }

    return $DB->get_records('local_hlai_grading_quiz_rubric_item', ['rubricid' => $rubricid], 'sortorder ASC, id ASC');
}

/**
 * Parse rubric items from the textarea input format.
 *
 * @param string $raw Raw.
 * @return array{items: array, errors: array}
 */
function local_hlai_grading_parse_quiz_rubric_items(string $raw): array {
    $items = [];
    $errors = [];
    $lines = preg_split('/\r\n|\r|\n/', trim($raw));

    if (!$lines) {
        $lines = [];
    }

    foreach ($lines as $index => $line) {
        $line = trim((string)$line);
        if ($line === '') {
            continue;
        }

        $parts = array_map('trim', explode('|', $line, 3));
        $title = $parts[0] ?? '';
        $maxscore = $parts[1] ?? '';
        $description = $parts[2] ?? '';

        if ($title === '' || $maxscore === '' || !is_numeric($maxscore)) {
            $errors[] = get_string('quizrubric_items_invalid', 'local_hlai_grading', $index + 1);
            continue;
        }

        $items[] = [
            'name' => clean_param($title, PARAM_TEXT),
            'maxscore' => (float)$maxscore,
            'description' => clean_param($description, PARAM_TEXT),
        ];
    }

    if (empty($items)) {
        $errors[] = get_string('quizrubric_items_empty', 'local_hlai_grading');
    }

    return [
        'items' => $items,
        'errors' => $errors,
    ];
}

/**
 * Format rubric items into the textarea input format.
 *
 * @param array $items Items.
 * @return string The string value.
 */
function local_hlai_grading_format_quiz_rubric_items(array $items): string {
    $lines = [];
    foreach ($items as $item) {
        $name = trim((string)($item->name ?? ''));
        if ($name === '') {
            continue;
        }

        $maxscore = (float)($item->maxscore ?? 0);
        $scoretext = rtrim(rtrim(sprintf('%.2f', $maxscore), '0'), '.');
        $description = trim((string)($item->description ?? ''));

        $line = $name . '|' . $scoretext;
        if ($description !== '') {
            $line .= '|' . $description;
        }
        $lines[] = $line;
    }

    return implode("\n", $lines);
}

/**
 * Build a rubric JSON payload for quiz grading.
 *
 * @param int $rubricid Rubricid.
 * @return string|null The result.
 */
function local_hlai_grading_get_quiz_rubric_json(int $rubricid): ?string {
    global $DB;

    if ($rubricid <= 0) {
        return null;
    }

    $rubric = $DB->get_record('local_hlai_grading_quiz_rubric', ['id' => $rubricid], '*', IGNORE_MISSING);
    if (!$rubric) {
        return null;
    }

    $items = local_hlai_grading_get_quiz_rubric_items($rubricid);
    if (empty($items)) {
        return null;
    }

    $total = 0.0;
    $criteria = [];
    foreach ($items as $item) {
        $maxscore = (float)$item->maxscore;
        $total += $maxscore;
        $criteria[] = [
            'name' => (string)$item->name,
            'max_score' => $maxscore,
            'description' => (string)($item->description ?? ''),
        ];
    }

    $payload = [
        'name' => (string)$rubric->name,
        'max_score' => $total,
        'criteria' => $criteria,
    ];

    return json_encode(
        $payload,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
}

/**
 * Save a quiz rubric with its items.
 *
 * @param int|null $rubricid Rubricid.
 * @param string $name Name.
 * @param int|null $courseid Courseid.
 * @param int $ownerid Ownerid.
 * @param string $visibility Visibility.
 * @param array $items Items.
 * @return int rubric id
 */
function local_hlai_grading_save_quiz_rubric(
    ?int $rubricid,
    string $name,
    ?int $courseid,
    int $ownerid,
    string $visibility,
    array $items
): int {
    global $DB;

    $now = time();
    $visibility = $visibility === 'global' ? 'global' : 'course';
    $record = (object)[
        'name' => $name,
        'courseid' => $visibility === 'global' ? null : $courseid,
        'ownerid' => $ownerid,
        'visibility' => $visibility,
        'timemodified' => $now,
    ];

    $transaction = $DB->start_delegated_transaction();

    if ($rubricid) {
        $existing = $DB->get_record('local_hlai_grading_quiz_rubric', ['id' => $rubricid], 'id, ownerid', MUST_EXIST);
        $record->ownerid = $existing->ownerid ?? $ownerid;
        $record->id = $rubricid;
        $DB->update_record('local_hlai_grading_quiz_rubric', $record);
    } else {
        $record->ownerid = $ownerid;
        $record->timecreated = $now;
        $rubricid = (int)$DB->insert_record('local_hlai_grading_quiz_rubric', $record);
    }

    $DB->delete_records('local_hlai_grading_quiz_rubric_item', ['rubricid' => $rubricid]);

    $order = 1;
    foreach ($items as $item) {
        $itemrecord = (object)[
            'rubricid' => $rubricid,
            'sortorder' => $order,
            'name' => $item['name'],
            'maxscore' => $item['maxscore'],
            'description' => $item['description'] ?? '',
        ];
        $DB->insert_record('local_hlai_grading_quiz_rubric_item', $itemrecord);
        $order++;
    }

    $transaction->allow_commit();

    return $rubricid;
}

/**
 * Delete a quiz rubric and its items.
 *
 * @param int $rubricid Rubricid.
 * @return void
 */
function local_hlai_grading_delete_quiz_rubric(int $rubricid): void {
    global $DB;

    if ($rubricid <= 0) {
        return;
    }

    $transaction = $DB->start_delegated_transaction();
    $DB->delete_records('local_hlai_grading_quiz_rubric_item', ['rubricid' => $rubricid]);
    $DB->delete_records('local_hlai_grading_quiz_rubric', ['id' => $rubricid]);
    $transaction->allow_commit();
}
