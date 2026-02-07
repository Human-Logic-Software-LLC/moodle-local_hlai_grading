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
 * Plugin renderer class.
 *
 * @package    local_hlai_grading
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_hlai_grading\output;

/**
 * Renderer class.
 */
class renderer extends \plugin_renderer_base {
    /**
     * Render the dashboard page.
     *
     * @param dashboard_page $page Page.
     * @return string The string value.
     */
    public function render_dashboard_page(dashboard_page $page) {
        $data = $page->export_for_template($this);
        return $this->render_from_template('local_hlai_grading/dashboard_page', $data);
    }

    /**
     * Render AI grading status badge for a submission.
     *
     * @param int $userid User ID.
     * @param int $assignid Assignment ID.
     * @return string The string value.
     */
    public function render_ai_status_badge($userid, $assignid) {
        global $DB;

        $sql = "
            SELECT r.*
              FROM {local_hlai_grading_results} r
             WHERE r.userid = :userid
               AND r.instanceid = :assignid
          ORDER BY r.timecreated DESC";
        $records = $DB->get_records_sql($sql, ['userid' => $userid, 'assignid' => $assignid], 0, 1);
        $result = $records ? reset($records) : null;

        if (!$result) {
            $queue = null;
            $queues = $DB->get_records_sql("
                SELECT *
                  FROM {local_hlai_grading_queue}
                 WHERE userid = :userid
              ORDER BY timecreated DESC
            ", ['userid' => $userid], 0, 20);

            foreach ($queues as $candidate) {
                $payload = json_decode($candidate->payload ?? '[]', true) ?? [];
                if ((int)($payload['assignid'] ?? 0) === (int)$assignid) {
                    $queue = $candidate;
                    break;
                }
            }

            if ($queue) {
                if ($queue->status === 'pending') {
                    return \html_writer::tag('span', get_string('badgegrading', 'local_hlai_grading'), [
                        'class' => 'badge badge-info', 'title' => get_string('badgegradingtitle', 'local_hlai_grading'),
                    ]);
                }
                if ($queue->status === 'failed') {
                    return \html_writer::tag('span', get_string('badgesfailed', 'local_hlai_grading'), [
                        'class' => 'badge badge-danger', 'title' => get_string('badgesfailedtitle', 'local_hlai_grading'),
                    ]);
                }
            }
            return '';
        }

        $info = (object) [
            'grade' => format_float((float)$result->grade, 2),
            'maxgrade' => format_float((float)$result->maxgrade, 2),
            'confidence' => (int)($result->confidence ?? 0),
        ];

        switch ($result->status) {
            case 'draft':
                $badge = \html_writer::tag('span', get_string('badgedraft', 'local_hlai_grading'), [
                    'class' => 'badge badge-warning mr-1', 'title' => get_string('badgegradetitle', 'local_hlai_grading', $info),
                ]);
                $reviewurl = new \moodle_url('/local/hlai_grading/release.php', ['id' => $result->id]);
                $reviewbtn = \html_writer::link($reviewurl, get_string('badgebuttonreview', 'local_hlai_grading'), [
                    'class' => 'btn btn-sm btn-primary ml-1', 'title' => get_string('badgebuttonreviewtitle', 'local_hlai_grading'),
                ]);
                return $badge . $reviewbtn;

            case 'released':
                return \html_writer::tag('span', get_string('badgereleased', 'local_hlai_grading'), [
                    'class' => 'badge badge-success', 'title' => get_string('badgereleasedtitle', 'local_hlai_grading'),
                ]);

            case 'rejected':
                return \html_writer::tag('span', get_string('badgerejected', 'local_hlai_grading'), [
                    'class' => 'badge badge-secondary', 'title' => get_string('badgerejectedtitle', 'local_hlai_grading'),
                ]);
        }

        return '';
    }

    /**
     * Render AI grading summary banner for an assignment.
     *
     * @param int $assignid Assignment ID.
     * @return string The string value.
     */
    public function render_ai_summary_banner($assignid) {
        global $DB;

        $counts = $DB->get_record_sql("
            SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) AS pending_review,
                SUM(CASE WHEN status = 'released' THEN 1 ELSE 0 END) AS released
              FROM {local_hlai_grading_results}
             WHERE instanceid = :assignid
        ", ['assignid' => $assignid]);

        if (!$counts || (int)$counts->total === 0) {
            return '';
        }

        $summarydata = (object)[
            'total' => (int)$counts->total,
            'pending' => (int)($counts->pending_review ?? 0),
            'released' => (int)($counts->released ?? 0),
        ];

        if ($summarydata->pending === 0) {
            return '';
        }

        $html = \html_writer::start_div('ai-banner');
        $html .= \html_writer::tag('h5', get_string('summaryheading', 'local_hlai_grading'));
        $html .= \html_writer::tag('p', get_string('summarycounts', 'local_hlai_grading', $summarydata));

        if ($summarydata->pending > 0) {
            $courseid = !empty($this->page->course->id) ? $this->page->course->id : 0;
            if (!$courseid) {
                $courseid = (int)$DB->get_field('assign', 'course', ['id' => $assignid], IGNORE_MISSING);
            }
            $params = ['assignid' => $assignid];
            if ($courseid) {
                $params['courseid'] = $courseid;
            }
            $viewurl = new \moodle_url('/local/hlai_grading/view.php', $params);
            $html .= \html_writer::link(
                $viewurl,
                get_string('summaryreviewbutton', 'local_hlai_grading', $summarydata->pending),
                ['class' => 'btn btn-primary btn-sm']
            );
        }

        $html .= \html_writer::end_div();

        return $html;
    }

    /**
     * Render AI rubric previews on the assignment view page.
     *
     * @param \cm_info $cm Cm.
     * @param int $courseid Courseid.
     * @param int|null $userid Optional user filter (used for student-facing view).
     * @return string The string value.
     */
    public function render_assignment_rubric_preview(\cm_info $cm, int $courseid, ?int $userid = null): string {
        global $DB, $CFG;

        if ($cm->modname !== 'assign') {
            return '';
        }

        $params = [
            'instanceid' => $cm->instance, 'modname' => 'assign', 'status' => 'released',
        ];
        $sql = "
            SELECT *
              FROM {local_hlai_grading_results}
             WHERE instanceid = :instanceid
               AND modulename = :modname
               AND status = :status
               AND rubric_analysis IS NOT NULL
               AND rubric_analysis <> ''
        ";
        if ($userid) {
            $sql .= " AND userid = :userid";
            $params['userid'] = $userid;
        }
        $sql .= " ORDER BY timereviewed DESC, timecreated DESC";
        $limit = $userid ? 1 : 10;
        $results = $DB->get_records_sql($sql, $params, 0, $limit);

        if (!$results) {
            return '';
        }

        require_once($CFG->dirroot . '/mod/assign/locallib.php');
        require_once($CFG->dirroot . '/grade/grading/lib.php');
        require_once($CFG->dirroot . '/user/lib.php');

        $context = \context_module::instance($cm->id);
        $course = get_course($courseid);
        $assign = new \assign($context, $cm, $course);
        $gradingmanager = get_grading_manager($context, 'mod_assign', 'submissions');
        $method = $gradingmanager->get_active_method();
        if (!in_array($method, ['rubric', 'rubric_ranges'], true)) {
            return '';
        }
        $controller = $gradingmanager->get_controller($method);

        $userids = array_unique(array_map(static function ($result) {
            return (int)$result->userid;
        }, $results));
        $users = $userids ? user_get_users_by_id($userids) : [];

        $itemshtml = '';
        foreach ($results as $result) {
            $criteria = json_decode($result->rubric_analysis, true);
            if (empty($criteria)) {
                continue;
            }

            $grade = $assign->get_user_grade($result->userid, true);
            if (!$grade) {
                continue;
            }

            $instances = $controller->get_active_instances($grade->id);
            if (empty($instances)) {
                require_once($CFG->dirroot . '/local/hlai_grading/classes/local/rubric_sync.php');
                try {
                    $sync = new \local_hlai_grading\local\rubric_sync();
                    if ($sync->write_rubric($courseid, $cm->id, $result->userid, $criteria, $result->reasoning ?? '')) {
                        $instances = $controller->get_active_instances($grade->id);
                    }
                } catch (\Throwable $e) {
                    debugging('Rubric preview backfill failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
                }
            }

            if (empty($instances)) {
                continue;
            }

            $student = $users[$result->userid] ?? null;
            $studentname = $student ? fullname($student) : get_string('unknownuser', 'moodle');

            $rubricrenderer = $this->page->get_renderer('gradingform_' . $method);
            $rubrichtml = $rubricrenderer->display_instances($instances, '', false);

            $gradelabel = (object)[
                'grade' => format_float((float)$result->grade, 2), 'maxgrade' => format_float((float)$result->maxgrade, 2),
            ];

            $statuskey = $result->status === 'draft' ? 'badgedraft' : ($result->status === 'released' ? 'badgereleased' : 'status');
            $statusclass = $result->status === 'draft' ? 'badge-warning' : 'badge-success';
            $statusbadge = '';
            if ($statuskey !== 'status') {
                $statusbadge = \html_writer::tag('span', get_string($statuskey, 'local_hlai_grading'), [
                    'class' => 'badge ' . $statusclass . ' mr-2',
                ]);
            }

            $itemshtml .= \html_writer::start_div('ai-rubric-preview-item mb-5');
            $itemshtml .= \html_writer::tag(
                'h6',
                get_string('assignmentrubricstudent', 'local_hlai_grading', format_string($studentname)),
                ['class' => 'mb-1']
            );
            if ($statusbadge) {
                $itemshtml .= $statusbadge;
            }
            $itemshtml .= \html_writer::tag(
                'span',
                get_string('assignmentrubricgrade', 'local_hlai_grading', $gradelabel),
                ['class' => 'text-muted d-block mb-3']
            );
            $itemshtml .= \html_writer::div($rubrichtml, 'ai-rubric-widget mt-2');

            $feedbacklist = '';
            foreach ($criteria as $criterion) {
                $cname = trim($criterion['name'] ?? '');
                $cfeedback = trim($criterion['feedback'] ?? '');
                if ($cname === '' || $cfeedback === '') {
                    continue;
                }
                $feedbacklist .= \html_writer::div(
                    \html_writer::tag('strong', s($cname) . ': ') .
                    \html_writer::span(s($cfeedback)),
                    'ai-criterion-feedback'
                );
            }

            if ($feedbacklist !== '') {
                $itemshtml .= \html_writer::tag('h6', get_string('assignmentrubricfeedbackheading', 'local_hlai_grading'), [
                    'class' => 'mt-4 mb-2',
                ]);
                $itemshtml .= \html_writer::div($feedbacklist, 'ai-criterion-feedback-list');
            }

            $itemshtml .= \html_writer::end_div();
        }

        if ($itemshtml === '') {
            return '';
        }

        $assignmentname = !empty($cm->name) ? $cm->name : ($course->fullname ?? '');
        $introtext = get_string('assignmentrubricintro', 'local_hlai_grading', format_string($assignmentname));

        $container = \html_writer::start_div('card ai-rubric-preview-card mb-4 w-100');
        $container .= \html_writer::div(
            get_string('assignmentrubricheading', 'local_hlai_grading'),
            'card-header font-weight-bold d-flex align-items-center'
        );
        $intro = \html_writer::tag('p', $introtext, ['class' => 'text-muted mb-3']);
        $container .= \html_writer::div($intro . $itemshtml, 'card-body');
        $container .= \html_writer::tag('script', $this->get_rubric_hiding_script(), ['type' => 'text/javascript']);
        $container .= \html_writer::end_div();

        return $container;
    }

    /**
     * JavaScript helper to hide Moodle's default rubric block when the AI preview renders.
     *
     * @return string The string value.
     */
    private function get_rubric_hiding_script(): string {
        $js = <<<JS
document.addEventListener('DOMContentLoaded', function () {
    var blocks = document.querySelectorAll('.advancedgrade');
    blocks.forEach(function (block) {
        if (block.closest('.ai-rubric-preview-card')) {
            return;
        }
        block.style.display = 'none';
        block.setAttribute('data-hlai-hidden', '1');
        var heading = block.previousElementSibling;
        while (heading && heading.nodeType === Node.TEXT_NODE) {
            heading = heading.previousSibling;
        }
        if (heading && /H[1-6]/.test(heading.tagName)) {
            heading.style.display = 'none';
        }
    });
});
JS;
        return $js;
    }
}
