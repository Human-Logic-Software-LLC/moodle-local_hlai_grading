<?php
namespace local_hlai_grading\local;

defined('MOODLE_INTERNAL') || die();

class grade_pusher {

    /**
     * Push AI grade to assignment gradebook.
     *
     * @param int $courseid Course ID
     * @param int $assignid Assignment ID
     * @param int $userid Student user ID
     * @param array $ai Normalized AI response with score, max_score, feedback
     * @param bool $pushfeedback Whether to include feedback
     * @return bool Success
     */
    public static function push_to_assignment($courseid, $assignid, $userid, $ai, $pushfeedback = true) {
        global $DB, $CFG;
        
        require_once($CFG->dirroot . '/mod/assign/locallib.php');
        require_once($CFG->dirroot . '/grade/grading/lib.php');
        
        // Get the assignment
        $cm = get_coursemodule_from_instance('assign', $assignid);
        if (!$cm) {
            return false;
        }
        
        $context = \context_module::instance($cm->id);
        $assign = new \assign($context, $cm, null);
        
        // Get or create the submission grade
        $submission = $assign->get_user_submission($userid, false);
        if (!$submission) {
            return false;
        }
        
        // Calculate grade scaled to assignment's grademax
        $grademax = (float)$assign->get_instance()->grade;
        $score = (float)($ai['score'] ?? 0);
        $maxscore = (float)($ai['max_score'] ?? 100);
        
        // Scale AI score to assignment max
        if ($maxscore > 0) {
            $grade = ($score / $maxscore) * $grademax;
        } else {
            $grade = min($score, $grademax);
        }
        
        // Prepare grade data
        $gradedata = new \stdClass();
        $gradedata->userid = $userid;
        $gradedata->grade = $grade;
        $gradedata->attemptnumber = $submission->attemptnumber;

        $criterialist = [];
        if (!empty($ai['criteria']) && is_array($ai['criteria'])) {
            $criterialist = $ai['criteria'];
        } else if (!empty($ai['raw']['criteria']) && is_array($ai['raw']['criteria'])) {
            $criterialist = $ai['raw']['criteria'];
        }

        // Attach advanced grading data when using rubrics so Moodle does not warn.
        if (!empty($criterialist)) {
            $gradingmanager = get_grading_manager($context, 'mod_assign', 'submissions');
            if ($gradingmanager->get_active_method() === 'rubric') {
                $controller = $gradingmanager->get_controller('rubric');
                $definition = $controller->get_definition();
                if ($definition && !empty($definition->rubric_criteria)) {
                    require_once($CFG->dirroot . '/local/hlai_grading/classes/local/rubric_sync.php');
                    $sync = new \local_hlai_grading\local\rubric_sync();
                    $fillings = $sync->map_standard_rubric_criteria($definition->rubric_criteria, $criterialist);
                    if (!empty($fillings['criteria'])) {
                        $gradedata->advancedgrading = $fillings;
                    }
                }
            }
        }
        
        // Add feedback if enabled - must be an array with 'text' and 'format'
        if ($pushfeedback && !empty($ai['feedback'])) {
            $gradedata->assignfeedbackcomments_editor = [
                'text' => $ai['feedback'],
                'format' => FORMAT_PLAIN,  // Use plain text format
            ];
        }
        
        // Save the grade
        return $assign->save_grade($userid, $gradedata);
    }

    /**
     * Create a comment/note on assignment without changing grade.
     * Useful for "suggestion only" mode.
     *
     * @param int $assignid Assignment ID
     * @param int $userid Student user ID
     * @param string $feedback AI feedback
     * @return bool Success
     */
    public static function add_feedback_comment($assignid, $userid, $feedback) {
        global $DB, $CFG;
        
        require_once($CFG->dirroot . '/mod/assign/locallib.php');
        
        $cm = get_coursemodule_from_instance('assign', $assignid);
        if (!$cm) {
            return false;
        }
        
        $context = \context_module::instance($cm->id);
        $assign = new \assign($context, $cm, null);
        
        $submission = $assign->get_user_submission($userid, false);
        if (!$submission) {
            return false;
        }
        
        // Create grade object with just feedback, no grade change
        $gradedata = new \stdClass();
        $gradedata->userid = $userid;
        $gradedata->attemptnumber = $submission->attemptnumber;
        $gradedata->assignfeedbackcomments_editor = [
            'text' => '<strong>[AI Grading Suggestion]</strong><br>' . $feedback,
            'format' => FORMAT_HTML,
        ];
        
        return $assign->save_grade($userid, $gradedata);
    }
}
