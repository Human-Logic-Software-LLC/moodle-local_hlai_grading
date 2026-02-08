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
 * Dashboard statistics helper class.
 *
 * @package    local_hlai_grading
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_hlai_grading\local;

/**
 * Dashboard_stats class.
 */
class dashboard_stats {
    /**
     * Get global statistics for the admin dashboard.
     *
     * @return array The result array.
     */
    public function get_admin_stats(): array {
        global $DB;

        // 1. Queue Depth (Pending or Processing items).
        $queuedepth = $DB->count_records_select('local_hlai_grading_queue', "status = 'pending' OR status = 'processing'");

        // 2. Total Graded (Completed items in results).
        $totalgraded = $DB->count_records('local_hlai_grading_results');

        // 3. Failures (Status failed or high retries).
        $failures = $DB->count_records_select('local_hlai_grading_queue', "status = 'failed' OR retries >= 3");

        // 4. Token Usage (Sum of tokens_used).
        $tokensql = "SELECT SUM(tokens_used) FROM {local_hlai_grading_results}";
        $totaltokens = $DB->get_field_sql($tokensql) ?: 0;

        // 5. Recent Activity Graph Data (Last 7 days).
        $weekago = time() - (7 * 24 * 3600);
        $activitysql = "SELECT FROM_UNIXTIME(timecreated, '%Y-%m-%d') as date, COUNT(*) as count
                         FROM {local_hlai_grading_results}
                         WHERE timecreated > :time
                         GROUP BY FROM_UNIXTIME(timecreated, '%Y-%m-%d')
                         ORDER BY date ASC";

        // Note: FROM_UNIXTIME usage might depend on DB type.
        // For cross-db compatibility in Moodle, we often used to do this in PHP or use specific DB functions.
        // However, for simplicity let's stick to a basic count for now or handle date grouping in PHP if strictly needed.
        // Let's grab the raw timestamps and process in PHP for max compatibility.

        $activityrawsql = "SELECT timecreated FROM {local_hlai_grading_results} WHERE timecreated > :time ORDER BY timecreated ASC";
        $rawactivity = $DB->get_fieldset_sql($activityrawsql, ['time' => $weekago]);

        $activitydata = [];
        foreach ($rawactivity as $timestamp) {
            $date = date('Y-m-d', $timestamp);
            if (!isset($activitydata[$date])) {
                $activitydata[$date] = 0;
            }
            $activitydata[$date]++;
        }

        // Fill in missing days.
        $finalactivity = [];
        for ($i = 6; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-$i days"));
            $finalactivity[$d] = $activitydata[$d] ?? 0;
        }

        // 6. Top Courses by Usage (Adoption).
        $topcoursessql = "SELECT c.shortname, COUNT(r.id) as count
                            FROM {local_hlai_grading_results} r
                            JOIN {local_hlai_grading_queue} q ON r.queueid = q.id
                            JOIN {course} c ON q.courseid = c.id
                            GROUP BY c.id, c.shortname
                            ORDER BY count DESC";
        $topcourses = $DB->get_records_sql($topcoursessql, [], 0, 5);

        $courselabels = [];
        $coursedata = [];
        foreach ($topcourses as $course) {
            $courselabels[] = $course->shortname;
            $coursedata[] = $course->count;
        }

        // 7. Estimated Efficiency (Time Saved).
        // Assumption: Manual grading takes ~5 mins (300s) per item.
        // AI processing time is in DB, but "saved" is (Manual - AI).
        // Let's just say 5 mins per item for simplicity.
        $hourssaved = round(($totalgraded * 5) / 60, 1);

        // 8. Teacher Trust Score (Override Rate).
        // Compare AI grade (hlai_grading_results) vs Final Moodle Grade (assign/quiz tables).
        // This is expensive to calc for ALL records, so let's sample or just check for drastic diffs where we can.
        // For simplicity and performance, let's look at the 'reviewed' flag if we used it to track acceptance,
        // OR better yet, query a join where final grade != ai grade.

        // Let's assume an override is if the grade changed by > 5%.
        // Warning: This query might be slow on huge datasets.
        // We will only look at the last 100 graded items to keep dashboard fast.

        $trustsql = "SELECT r.grade as aigrade, r.maxgrade, gg.finalgrade, gi.grademax
                      FROM {local_hlai_grading_results} r
                      JOIN {local_hlai_grading_queue} q ON r.queueid = q.id
                      JOIN {grade_items} gi ON (gi.iteminstance = q.instanceid AND gi.itemmodule = q.modulename)
                      JOIN {grade_grades} gg ON (gg.itemid = gi.id AND gg.userid = r.userid)
                      WHERE r.status = 'released'
                      ORDER BY r.timecreated DESC";

        $comparisons = $DB->get_records_sql($trustsql, [], 0, 100);

        $totalchecked = count($comparisons);
        $overrides = 0;

        foreach ($comparisons as $row) {
            // Normalize.
            $ainorm = ($row->maxgrade > 0) ? ($row->aigrade / $row->maxgrade) : 0;
            $finalnorm = ($row->grademax > 0) ? ($row->finalgrade / $row->grademax) :
                          (($row->maxgrade > 0) ? ($row->finalgrade / $row->maxgrade) : 0);

            // If diff > 5%.
            if (abs($ainorm - $finalnorm) > 0.05) {
                $overrides++;
            }
        }

        $trustscore = ($totalchecked > 0) ? round((($totalchecked - $overrides) / $totalchecked) * 100) : 100;

        $recenterrors = $this->get_recent_error_logs();

        return [
            'queue_depth' => $queuedepth,
            'total_graded' => $totalgraded,
            'failures' => $failures,
            'total_tokens' => number_format($totaltokens),
            'hours_saved' => $hourssaved,
            'trust_score' => $trustscore,
            'activity_chart_labels' => array_keys($finalactivity),
            'activity_chart_data' => array_values($finalactivity),
            'top_courses_labels' => $courselabels,
            'top_courses_data' => $coursedata,
            'error_logs' => $recenterrors,
        ];
    }

    /**
     * Get course-specific statistics for the teacher dashboard.
     *
     * @param int $courseid Courseid.
     * @return array The result array.
     */
    public function get_teacher_stats(int $courseid): array {
        global $DB;

        // Verify course exists.
        if (!$DB->record_exists('course', ['id' => $courseid])) {
            return [];
        }

        // 1. Items in Queue for this course.
        $queuecount = $DB->count_records('local_hlai_grading_queue', ['courseid' => $courseid, 'status' => 'pending']);

        // 2. Graded Items for this course.
        // Need to join via queueid to get courseid context.
        $gradedsql = "SELECT COUNT(r.id)
                       FROM {local_hlai_grading_results} r
                       JOIN {local_hlai_grading_queue} q ON r.queueid = q.id
                       WHERE q.courseid = :courseid";
        $gradedcount = $DB->count_records_sql($gradedsql, ['courseid' => $courseid]);

        // 3. Upcoming/Recent items.
        $recentsql = "SELECT r.id, r.grade, r.timecreated, q.modulename, q.cmid
                       FROM {local_hlai_grading_results} r
                       JOIN {local_hlai_grading_queue} q ON r.queueid = q.id
                       WHERE q.courseid = :courseid
                       ORDER BY r.timecreated DESC";
        $recentitems = $DB->get_records_sql($recentsql, ['courseid' => $courseid], 0, 5);

        $recentlist = [];
        foreach ($recentitems as $item) {
            $recentlist[] = [
                'module' => $item->modulename, 'grade' => format_float($item->grade, 2), 'date' => userdate($item->timecreated),
            ];
        }

        // 4. Grade Distribution (Histogram).
        $gradessql = "SELECT r.grade, r.maxgrade
                       FROM {local_hlai_grading_results} r
                       JOIN {local_hlai_grading_queue} q ON r.queueid = q.id
                       WHERE q.courseid = :courseid AND r.grade IS NOT NULL";
        $grades = $DB->get_records_sql($gradessql, ['courseid' => $courseid]);

        $buckets = ['0-20' => 0, '21-40' => 0, '41-60' => 0, '61-80' => 0, '81-100' => 0];
        $totalscoresum = 0;
        $scorecount = 0;

        foreach ($grades as $g) {
            if ($g->maxgrade > 0) {
                $percentage = ($g->grade / $g->maxgrade) * 100;
                $totalscoresum += $percentage;
                $scorecount++;

                if ($percentage <= 20) {
                    $buckets['0-20']++;
                } else if ($percentage <= 40) {
                    $buckets['21-40']++;
                } else if ($percentage <= 60) {
                    $buckets['41-60']++;
                } else if ($percentage <= 80) {
                    $buckets['61-80']++;
                } else {
                    $buckets['81-100']++;
                }
            }
        }
        $avgscore = $scorecount ? round($totalscoresum / $scorecount, 1) : 0;

        // 5. Rubric Analysis (Weakest/Strongest Criteria).
        // Join rubric scores -> results -> queue (course).
        $rubricsql = "SELECT rs.criterionname, AVG(rs.score / rs.maxscore) as avgnormscore
                       FROM {local_hlai_grading_rubric_scores} rs
                       JOIN {local_hlai_grading_results} r ON rs.resultid = r.id
                       JOIN {local_hlai_grading_queue} q ON r.queueid = q.id
                       WHERE q.courseid = :courseid AND rs.maxscore > 0
                       GROUP BY rs.criterionname
                       ORDER BY avgnormscore ASC"; // Ascending: Weakest first.

        $rubricstats = $DB->get_records_sql($rubricsql, ['courseid' => $courseid], 0, 5); // Bottom 5 criteria.

        $weakestcriteria = [];
        $weakestscores = [];
        foreach ($rubricstats as $stat) {
             // Clean up potentially long criterion names.
             $name = \shorten_text($stat->criterionname, 30);
             $weakestcriteria[] = $name;
             $weakestscores[] = round($stat->avgnormscore * 100, 1);
        }

        // 6. At-Risk Students (Recent low grades).
        // Find students who scored < 50% on their most recent ai-graded submission.
        $riskthreshold = 50; // Configurable ideally.

        $risksql = "SELECT r.userid, r.grade, r.maxgrade, q.modulename, r.timecreated
                     FROM {local_hlai_grading_results} r
                     JOIN {local_hlai_grading_queue} q ON r.queueid = q.id
                     WHERE q.courseid = :courseid
                       AND r.grade IS NOT NULL
                       AND r.maxgrade > 0
                       AND (r.grade / r.maxgrade) * 100 < :threshold
                     ORDER BY r.timecreated DESC";

        $riskyitems = $DB->get_records_sql($risksql, ['courseid' => $courseid, 'threshold' => $riskthreshold], 0, 5);

        $atrisklist = [];
        foreach ($riskyitems as $item) {
            $user = $DB->get_record('user', ['id' => $item->userid], 'id, firstname, lastname');
            if ($user) {
                 $percentage = ($item->grade / $item->maxgrade) * 100;
                 $atrisklist[] = [
                     'student' => fullname($user), 'task' => $item->modulename, 'score' => round($percentage, 1) . '%',
                 ];
            }
        }

        return [
            'queue_count' => $queuecount,
            'graded_count' => $gradedcount,
            'recent_items' => $recentlist,
            'average_score' => $avgscore,
            'grade_labels' => array_keys($buckets),
            'grade_data' => array_values($buckets),
            'rubric_labels' => $weakestcriteria,
            'rubric_data' => $weakestscores,
            'at_risk_students' => $atrisklist,
        ];
    }

    /**
     * Demo statistics for the admin dashboard.
     *
     * @return array The result array.
     */
    public function get_admin_demo_stats(): array {
        $labels = $this->build_recent_date_labels(7);
        $activity = [8, 12, 9, 15, 11, 14, 10];

        $courselabels = ['BIO-101', 'ENG-201', 'MATH-110', 'HIST-210', 'CHEM-105'];
        $coursedata = [42, 35, 28, 21, 18];

        $totalgraded = 144;
        $hourssaved = round(($totalgraded * 5) / 60, 1);

        $recenterrors = [
            [
                'time' => userdate(time() - 1800),
                'queueid' => 182,
                'queueid_display' => '#182',
                'status' => get_string('dashboarderrorfailed', 'local_hlai_grading'),
                'status_class' => 'iksha-badge--danger',
                'message' => 'AI hub timeout while fetching grading response.',
                'error_url' => (new \moodle_url('/local/hlai_grading/error_details.php', ['id' => 182]))->out(false),
            ], [
                'time' => userdate(time() - 7200),
                'queueid' => 176,
                'queueid_display' => '#176',
                'status' => get_string('dashboarderrorretry', 'local_hlai_grading'),
                'status_class' => 'iksha-badge--warning',
                'message' => 'Error (attempt 2/3): Missing answer key for assign 24.',
                'error_url' => (new \moodle_url('/local/hlai_grading/error_details.php', ['id' => 176]))->out(false),
            ], [
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
            'total_graded' => $totalgraded,
            'failures' => 2,
            'total_tokens' => number_format(128450),
            'hours_saved' => $hourssaved,
            'trust_score' => 92,
            'activity_chart_labels' => $labels,
            'activity_chart_data' => $activity,
            'top_courses_labels' => $courselabels,
            'top_courses_data' => $coursedata,
            'error_logs' => $recenterrors,
        ];
    }

    /**
     * Demo statistics for the teacher dashboard.
     *
     * @return array The result array.
     */
    public function get_teacher_demo_stats(): array {
        $now = time();

        $recentlist = [
            [
                'module' => 'assign', 'grade' => format_float(88.0, 2), 'date' => userdate($now - 3600),
            ], [
                'module' => 'quiz', 'grade' => format_float(74.5, 2), 'date' => userdate($now - 7200),
            ], [
                'module' => 'assign', 'grade' => format_float(92.3, 2), 'date' => userdate($now - 14400),
            ], [
                'module' => 'quiz', 'grade' => format_float(68.9, 2), 'date' => userdate($now - 21600),
            ], [
                'module' => 'assign', 'grade' => format_float(81.2, 2), 'date' => userdate($now - 28800),
            ],
        ];

        return [
            'queue_count' => 6,
            'graded_count' => 42,
            'recent_items' => $recentlist,
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
     * @param int $days Days.
     * @return array The result array.
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
     * @param int $limit Limit.
     * @return array The result array.
     */
    private function get_recent_error_logs(int $limit = 6): array {
        global $DB;

        $errorsql = "SELECT l.id, l.queueid, l.action, l.details, l.timecreated
                       FROM {local_hlai_grading_log} l
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
                'error_url' => $queueid
                    ? (new \moodle_url('/local/hlai_grading/error_details.php', ['id' => $queueid]))->out(false)
                    : null,
            ];
        }

        return $errors;
    }
}
