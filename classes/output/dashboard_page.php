<?php
namespace local_hlai_grading\output;

defined('MOODLE_INTERNAL') || die();

use renderable;
use templatable;
use renderer_base;
use stdClass;
use local_hlai_grading\local\dashboard_stats;

/**
 * Dashboard page renderable.
 */
class dashboard_page implements renderable, templatable {

    /** @var string 'admin' or 'teacher' */
    protected $viewtype;

    /** @var int|null Course ID for teacher view */
    protected $courseid;

    /** @var bool Whether to force demo data */
    protected $demomode;

    /**
     * Constructor.
     *
     * @param string $viewtype 'admin' or 'teacher'
     * @param int|null $courseid Required if viewtype is teacher
     * @param bool $demomode Whether to force demo data
     */
    public function __construct(string $viewtype, ?int $courseid = null, bool $demomode = false) {
        $this->viewtype = $viewtype;
        $this->courseid = $courseid;
        $this->demomode = $demomode;
    }

    /**
     * Export this data so it can be used as the context for a mustache template.
     *
     * @param renderer_base $output
     * @return stdClass
     */
    public function export_for_template(renderer_base $output): stdClass {
        $data = new stdClass();
        $stats_service = new dashboard_stats();

        $data->is_admin = ($this->viewtype === 'admin');
        $data->is_teacher = ($this->viewtype === 'teacher');
        $data->is_demo = false;
        
        // Scope Iksha styles to this dashboard container.
        $data->container_class = 'local-hlai-iksha';

        if ($data->is_admin) {
            if ($this->demomode) {
                $stats = $stats_service->get_admin_demo_stats();
                $data->is_demo = true;
            } else {
                $stats = $stats_service->get_admin_stats();
                if ($this->is_admin_stats_empty($stats)) {
                    $stats = $stats_service->get_admin_demo_stats();
                    $data->is_demo = true;
                }
            }
            $data->stats = [
                'queue_depth' => $stats['queue_depth'],
                'total_graded' => $stats['total_graded'],
                'failures' => $stats['failures'],
                'total_tokens' => $stats['total_tokens'],
                'hours_saved' => $stats['hours_saved'],
                'trust_score' => $stats['trust_score'],
            ];
            $data->error_logs = $stats['error_logs'] ?? [];
            // Pass chart data as JSON string for JS to pick up
            $data->chart_data_json = json_encode([
                'labels' => $stats['activity_chart_labels'],
                'data' => $stats['activity_chart_data'],
                'top_courses_labels' => $stats['top_courses_labels'],
                'top_courses_data' => $stats['top_courses_data']
            ]);
        } elseif ($data->is_teacher && $this->courseid) {
            if ($this->demomode) {
                $stats = $stats_service->get_teacher_demo_stats();
                $data->is_demo = true;
            } else {
                $stats = $stats_service->get_teacher_stats($this->courseid);
                if ($this->is_teacher_stats_empty($stats)) {
                    $stats = $stats_service->get_teacher_demo_stats();
                    $data->is_demo = true;
                }
            }
            $data->stats = [
                'queue_count' => $stats['queue_count'],
                'graded_count' => $stats['graded_count'],
                'average_score' => $stats['average_score'],
            ];
            $data->recent_items = array_values($stats['recent_items']);
            $data->at_risk_students = array_values($stats['at_risk_students']);
            
            // Pass chart data for teacher
            $data->chart_data_json = json_encode([
                'grade_labels' => $stats['grade_labels'],
                'grade_data' => $stats['grade_data'],
                'rubric_labels' => $stats['rubric_labels'],
                'rubric_data' => $stats['rubric_data']
            ]);
        }

        return $data;
    }

    /**
     * Decide whether the admin stats look empty and should use demo data.
     *
     * @param array $stats
     * @return bool
     */
    private function is_admin_stats_empty(array $stats): bool {
        if (empty($stats)) {
            return true;
        }

        $activitysum = array_sum($stats['activity_chart_data'] ?? []);
        $hasactivity = $activitysum > 0;
        $hascourses = !empty($stats['top_courses_labels']);
        $hasvolume = ((int)($stats['total_graded'] ?? 0) > 0)
            || ((int)($stats['queue_depth'] ?? 0) > 0)
            || ((int)($stats['failures'] ?? 0) > 0);

        return !$hasactivity && !$hascourses && !$hasvolume;
    }

    /**
     * Decide whether the teacher stats look empty and should use demo data.
     *
     * @param array $stats
     * @return bool
     */
    private function is_teacher_stats_empty(array $stats): bool {
        if (empty($stats)) {
            return true;
        }

        $gradesum = array_sum($stats['grade_data'] ?? []);
        $hasgrades = $gradesum > 0;
        $hasrubric = !empty($stats['rubric_labels']);
        $haslists = !empty($stats['recent_items']) || !empty($stats['at_risk_students']);
        $hasvolume = ((int)($stats['graded_count'] ?? 0) > 0)
            || ((int)($stats['queue_count'] ?? 0) > 0);

        return !$hasgrades && !$hasrubric && !$haslists && !$hasvolume;
    }
}
