<?php
namespace local_hlai_grading\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Data provider for the AI Grading Dashboard.
 *
 * @package    local_hlai_grading
 * @copyright  2024 Your Organization
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class dashboard_stats {

    /**
     * Get global statistics for the admin dashboard.
     *
     * @return array
     */
    public function get_admin_stats(): array {
        global $DB;

        // 1. Queue Depth (Pending or Processing items)
        $queue_depth = $DB->count_records_select('hlai_grading_queue', "status = 'pending' OR status = 'processing'");

        // 2. Total Graded (Completed items in results)
        $total_graded = $DB->count_records('hlai_grading_results');

        // 3. Failures (Status failed or high retries)
        $failures = $DB->count_records_select('hlai_grading_queue', "status = 'failed' OR retries >= 3");

        // 4. Token Usage (Sum of tokens_used)
        $token_sql = "SELECT SUM(tokens_used) FROM {hlai_grading_results}";
        $total_tokens = $DB->get_field_sql($token_sql) ?: 0;

        // 5. Recent Activity Graph Data (Last 7 days)
        $week_ago = time() - (7 * 24 * 3600);
        $activity_sql = "SELECT FROM_UNIXTIME(timecreated, '%Y-%m-%d') as date, COUNT(*) as count 
                         FROM {hlai_grading_results} 
                         WHERE timecreated > :time 
                         GROUP BY FROM_UNIXTIME(timecreated, '%Y-%m-%d')
                         ORDER BY date ASC";
        
        // Note: FROM_UNIXTIME usage might depend on DB type. 
        // For cross-db compatibility in Moodle, we often used to do this in PHP or use specific DB functions.
        // However, for simplicity let's stick to a basic count for now or handle date grouping in PHP if strictly needed.
        // Let's grab the raw timestamps and process in PHP for max compatibility.
        
        $activity_raw_sql = "SELECT timecreated FROM {hlai_grading_results} WHERE timecreated > :time ORDER BY timecreated ASC";
        $raw_activity = $DB->get_fieldset_sql($activity_raw_sql, ['time' => $week_ago]);
        
        $activity_data = [];
        foreach ($raw_activity as $timestamp) {
            $date = date('Y-m-d', $timestamp);
            if (!isset($activity_data[$date])) {
                $activity_data[$date] = 0;
            }
            $activity_data[$date]++;
        }

        // Fill in missing days
        $final_activity = [];
        for ($i = 6; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-$i days"));
            $final_activity[$d] = $activity_data[$d] ?? 0;
        }

        // 6. Top Courses by Usage (Adoption)
        $top_courses_sql = "SELECT c.shortname, COUNT(r.id) as count
                            FROM {hlai_grading_results} r
                            JOIN {hlai_grading_queue} q ON r.queueid = q.id
                            JOIN {course} c ON q.courseid = c.id
                            GROUP BY c.id, c.shortname
                            ORDER BY count DESC";
        $top_courses = $DB->get_records_sql($top_courses_sql, [], 0, 5);
        
        $course_labels = [];
        $course_data = [];
        foreach ($top_courses as $course) {
            $course_labels[] = $course->shortname;
            $course_data[] = $course->count;
        }
        
        // 7. Estimated Efficiency (Time Saved)
        // Assumption: Manual grading takes ~5 mins (300s) per item.
        // AI processing time is in DB, but "saved" is (Manual - AI).
        // Let's just say 5 mins per item for simplicity.
        $hours_saved = round(($total_graded * 5) / 60, 1);

        // 8. Teacher Trust Score (Override Rate)
        // Compare AI grade (hlai_grading_results) vs Final Moodle Grade (assign/quiz tables)
        // This is expensive to calc for ALL records, so let's sample or just check for drastic diffs where we can.
        // For simplicity and performance, let's look at the 'reviewed' flag if we used it to track acceptance,
        // OR better yet, query a join where final grade != ai grade.
        
        // Let's assume an override is if the grade changed by > 5%.
        // Warning: This query might be slow on huge datasets. 
        // We will only look at the last 100 graded items to keep dashboard fast.
        
        $trust_sql = "SELECT r.grade as aigrade, r.maxgrade, gg.finalgrade, gi.grademax 
                      FROM {hlai_grading_results} r
                      JOIN {hlai_grading_queue} q ON r.queueid = q.id
                      JOIN {grade_items} gi ON (gi.iteminstance = q.instanceid AND gi.itemmodule = q.modulename)
                      JOIN {grade_grades} gg ON (gg.itemid = gi.id AND gg.userid = r.userid)
                      WHERE r.status = 'released' 
                      ORDER BY r.timecreated DESC";
                      
        $comparisons = $DB->get_records_sql($trust_sql, [], 0, 100);
        
        $total_checked = count($comparisons);
        $overrides = 0;
        
        foreach ($comparisons as $row) {
            // Normalize
            $ai_norm = ($row->maxgrade > 0) ? ($row->aigrade / $row->maxgrade) : 0;
            $final_norm = ($row->grademax > 0) ? ($row->finalgrade / $row->grademax) :
                          (($row->maxgrade > 0) ? ($row->finalgrade / $row->maxgrade) : 0);
            
            // If diff > 5%
            if (abs($ai_norm - $final_norm) > 0.05) {
                $overrides++;
            }
        }
        
        $trust_score = ($total_checked > 0) ? round((($total_checked - $overrides) / $total_checked) * 100) : 100;

        $recent_errors = $this->get_recent_error_logs();

        return [
            'queue_depth' => $queue_depth,
            'total_graded' => $total_graded,
            'failures' => $failures,
            'total_tokens' => number_format($total_tokens),
            'hours_saved' => $hours_saved,
            'trust_score' => $trust_score,
            'activity_chart_labels' => array_keys($final_activity),
            'activity_chart_data' => array_values($final_activity),
            'top_courses_labels' => $course_labels,
            'top_courses_data' => $course_data,
            'error_logs' => $recent_errors,
        ];
    }

    /**
     * Get course-specific statistics for the teacher dashboard.
     *
     * @param int $courseid
     * @return array
     */
    public function get_teacher_stats(int $courseid): array {
        global $DB;

        // Verify course exists
        if (!$DB->record_exists('course', ['id' => $courseid])) {
           return [];
        }

        // 1. Items in Queue for this course
        $queue_count = $DB->count_records('hlai_grading_queue', ['courseid' => $courseid, 'status' => 'pending']);

        // 2. Graded Items for this course
        // Need to join via queueid to get courseid context
        $graded_sql = "SELECT COUNT(r.id) 
                       FROM {hlai_grading_results} r
                       JOIN {hlai_grading_queue} q ON r.queueid = q.id
                       WHERE q.courseid = :courseid";
        $graded_count = $DB->count_records_sql($graded_sql, ['courseid' => $courseid]);

        // 3. Upcoming/Recent items
        $recent_sql = "SELECT r.id, r.grade, r.timecreated, q.modulename, q.cmid 
                       FROM {hlai_grading_results} r
                       JOIN {hlai_grading_queue} q ON r.queueid = q.id
                       WHERE q.courseid = :courseid
                       ORDER BY r.timecreated DESC";
        $recent_items = $DB->get_records_sql($recent_sql, ['courseid' => $courseid], 0, 5);
        
        $recent_list = [];
        foreach ($recent_items as $item) {
            $recent_list[] = [
                'module' => $item->modulename,
                'grade' => format_float($item->grade, 2),
                'date' => userdate($item->timecreated),
            ];
        }

        // 4. Grade Distribution (Histogram)
        $grades_sql = "SELECT r.grade, r.maxgrade 
                       FROM {hlai_grading_results} r
                       JOIN {hlai_grading_queue} q ON r.queueid = q.id
                       WHERE q.courseid = :courseid AND r.grade IS NOT NULL";
        $grades = $DB->get_records_sql($grades_sql, ['courseid' => $courseid]);

        $buckets = ['0-20' => 0, '21-40' => 0, '41-60' => 0, '61-80' => 0, '81-100' => 0];
        $total_score_sum = 0;
        $score_count = 0;

        foreach ($grades as $g) {
            if ($g->maxgrade > 0) {
                $percentage = ($g->grade / $g->maxgrade) * 100;
                $total_score_sum += $percentage;
                $score_count++;

                if ($percentage <= 20) $buckets['0-20']++;
                elseif ($percentage <= 40) $buckets['21-40']++;
                elseif ($percentage <= 60) $buckets['41-60']++;
                elseif ($percentage <= 80) $buckets['61-80']++;
                else $buckets['81-100']++;
            }
        }
        $avg_score = $score_count ? round($total_score_sum / $score_count, 1) : 0;

        // 5. Rubric Analysis (Weakest/Strongest Criteria)
        // Join rubric scores -> results -> queue (course)
        $rubric_sql = "SELECT rs.criterionname, AVG(rs.score / rs.maxscore) as avgnormscore
                       FROM {hlai_grading_rubric_scores} rs
                       JOIN {hlai_grading_results} r ON rs.resultid = r.id
                       JOIN {hlai_grading_queue} q ON r.queueid = q.id
                       WHERE q.courseid = :courseid AND rs.maxscore > 0
                       GROUP BY rs.criterionname
                       ORDER BY avgnormscore ASC"; // Ascending: Weakest first
        
        $rubric_stats = $DB->get_records_sql($rubric_sql, ['courseid' => $courseid], 0, 5); // Bottom 5 criteria
        
        $weakest_criteria = [];
        $weakest_scores = [];
        foreach ($rubric_stats as $stat) {
             // Clean up potentially long criterion names
             $name = \shorten_text($stat->criterionname, 30);
             $weakest_criteria[] = $name;
             $weakest_scores[] = round($stat->avgnormscore * 100, 1);
        }

        // 6. At-Risk Students (Recent low grades)
        // Find students who scored < 50% on their most recent ai-graded submission
        $risk_threshold = 50; // Configurable ideally
        
        $risk_sql = "SELECT r.userid, r.grade, r.maxgrade, q.modulename, r.timecreated 
                     FROM {hlai_grading_results} r
                     JOIN {hlai_grading_queue} q ON r.queueid = q.id
                     WHERE q.courseid = :courseid 
                       AND r.grade IS NOT NULL 
                       AND r.maxgrade > 0
                       AND (r.grade / r.maxgrade) * 100 < :threshold
                     ORDER BY r.timecreated DESC";
        
        $risky_items = $DB->get_records_sql($risk_sql, ['courseid' => $courseid, 'threshold' => $risk_threshold], 0, 5);
        
        $at_risk_list = [];
        foreach ($risky_items as $item) {
            $user = $DB->get_record('user', ['id' => $item->userid], 'id, firstname, lastname');
            if ($user) {
                 $percentage = ($item->grade / $item->maxgrade) * 100;
                 $at_risk_list[] = [
                     'student' => fullname($user),
                     'task' => $item->modulename,
                     'score' => round($percentage, 1) . '%'
                 ];
            }
        }

        return [
            'queue_count' => $queue_count,
            'graded_count' => $graded_count,
            'recent_items' => $recent_list,
            'average_score' => $avg_score,
            'grade_labels' => array_keys($buckets),
            'grade_data' => array_values($buckets),
            'rubric_labels' => $weakest_criteria,
            'rubric_data' => $weakest_scores,
            'at_risk_students' => $at_risk_list
        ];
    }

    /**
     * Demo statistics for the admin dashboard.
     *
     * @return array
     */
    public function get_admin_demo_stats(): array {
        $labels = $this->build_recent_date_labels(7);
        $activity = [8, 12, 9, 15, 11, 14, 10];

        $course_labels = ['BIO-101', 'ENG-201', 'MATH-110', 'HIST-210', 'CHEM-105'];
        $course_data = [42, 35, 28, 21, 18];

        $total_graded = 144;
        $hours_saved = round(($total_graded * 5) / 60, 1);

        $recent_errors = [
            [
                'time' => userdate(time() - 1800),
                'queueid' => 182,
                'queueid_display' => '#182',
                'status' => get_string('dashboarderrorfailed', 'local_hlai_grading'),
                'status_class' => 'iksha-badge--danger',
                'message' => 'AI hub timeout while fetching grading response.',
                'error_url' => (new \moodle_url('/local/hlai_grading/error_details.php', ['id' => 182]))->out(false),
            ],
            [
                'time' => userdate(time() - 7200),
                'queueid' => 176,
                'queueid_display' => '#176',
                'status' => get_string('dashboarderrorretry', 'local_hlai_grading'),
                'status_class' => 'iksha-badge--warning',
                'message' => 'Error (attempt 2/3): Missing answer key for assign 24.',
                'error_url' => (new \moodle_url('/local/hlai_grading/error_details.php', ['id' => 176]))->out(false),
            ],
            [
                'time' => userdate(time() - 10800),
                'queueid' => 171,
                'queueid_display' => '#171',
                'status' => get_string('dashboarderrorfailed', 'local_hlai_grading'),
                'status_class' => 'iksha-badge--danger',
                'message' => 'Payload parsing error: empty student submission.',
                'error_url' => (new \moodle_url('/local/hlai_grading/error_details.php', ['id' => 171]))->out(false),
            ],
        ];

        return [
            'queue_depth' => 9,
            'total_graded' => $total_graded,
            'failures' => 2,
            'total_tokens' => number_format(128450),
            'hours_saved' => $hours_saved,
            'trust_score' => 92,
            'activity_chart_labels' => $labels,
            'activity_chart_data' => $activity,
            'top_courses_labels' => $course_labels,
            'top_courses_data' => $course_data,
            'error_logs' => $recent_errors,
        ];
    }

    /**
     * Demo statistics for the teacher dashboard.
     *
     * @return array
     */
    public function get_teacher_demo_stats(): array {
        $now = time();

        $recent_list = [
            [
                'module' => 'assign',
                'grade' => format_float(88.0, 2),
                'date' => userdate($now - 3600),
            ],
            [
                'module' => 'quiz',
                'grade' => format_float(74.5, 2),
                'date' => userdate($now - 7200),
            ],
            [
                'module' => 'assign',
                'grade' => format_float(92.3, 2),
                'date' => userdate($now - 14400),
            ],
            [
                'module' => 'quiz',
                'grade' => format_float(68.9, 2),
                'date' => userdate($now - 21600),
            ],
            [
                'module' => 'assign',
                'grade' => format_float(81.2, 2),
                'date' => userdate($now - 28800),
            ],
        ];

        return [
            'queue_count' => 6,
            'graded_count' => 42,
            'recent_items' => $recent_list,
            'average_score' => 76.4,
            'grade_labels' => ['0-20', '21-40', '41-60', '61-80', '81-100'],
            'grade_data' => [3, 7, 11, 13, 8],
            'rubric_labels' => ['Evidence use', 'Structure', 'Clarity', 'Analysis depth', 'Citations'],
            'rubric_data' => [52.4, 58.9, 61.2, 66.1, 71.8],
            'at_risk_students' => [
                ['student' => 'Jordan Lee', 'task' => 'quiz', 'score' => '42.5%'],
                ['student' => 'Alex Kim', 'task' => 'assign', 'score' => '46.0%'],
                ['student' => 'Sam Patel', 'task' => 'quiz', 'score' => '48.8%'],
            ],
        ];
    }

    /**
     * Build recent date labels for charts.
     *
     * @param int $days
     * @return array
     */
    private function build_recent_date_labels(int $days): array {
        $labels = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $labels[] = date('Y-m-d', strtotime("-{$i} days"));
        }
        return $labels;
    }

    /**
     * Return recent system error logs for the admin dashboard.
     *
     * @param int $limit
     * @return array
     */
    private function get_recent_error_logs(int $limit = 6): array {
        global $DB;

        $errorsql = "SELECT l.id, l.queueid, l.action, l.details, l.timecreated
                       FROM {hlai_grading_log} l
                      WHERE l.action IN ('failed', 'retry')
                   ORDER BY l.timecreated DESC";
        $records = $DB->get_records_sql($errorsql, [], 0, $limit);

        $errors = [];
        foreach ($records as $record) {
            $message = trim((string)($record->details ?? ''));
            if ($message === '') {
                $message = get_string('dashboarderrorunknown', 'local_hlai_grading');
            }
            $message = shorten_text($message, 140);

            $statuslabel = $record->action === 'failed'
                ? get_string('dashboarderrorfailed', 'local_hlai_grading')
                : get_string('dashboarderrorretry', 'local_hlai_grading');
            $statusclass = $record->action === 'failed' ? 'iksha-badge--danger' : 'iksha-badge--warning';

            $queueid = (int)($record->queueid ?? 0);
            $errors[] = [
                'time' => userdate($record->timecreated),
                'queueid' => $queueid,
                'queueid_display' => $queueid ? ('#' . $queueid) : '—',
                'status' => $statuslabel,
                'status_class' => $statusclass,
                'message' => $message,
                'error_url' => $queueid ? (new \moodle_url('/local/hlai_grading/error_details.php', ['id' => $queueid]))->out(false) : null,
            ];
        }

        return $errors;
    }
}
