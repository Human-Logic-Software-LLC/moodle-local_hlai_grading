<?php
namespace local_hlai_grading\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Sync AI rubric results back into Moodle's rubric grading.
 * Supports both standard 'rubric' and custom 'rubric_ranges' grading methods.
 */
class rubric_sync {

    /**
     * Write AI criterion scores back into Moodle's rubric grading interface.
     *
     * @param int   $courseid
     * @param int   $cmid
     * @param int   $userid          student being graded
     * @param array $aicriteria      from AI: [{name, score, max_score, feedback}, ...]
     * @param string $feedback       overall feedback
     * @return bool                  true if rubric was written, false if not applicable
     */
    public function write_rubric(int $courseid, int $cmid, int $userid, array $aicriteria, string $feedback = ''): bool {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/lib/gradelib.php');
        require_once($CFG->dirroot . '/grade/grading/lib.php');
        require_once($CFG->dirroot . '/mod/assign/locallib.php');

        // Get context and assignment.
        $context = \context_module::instance($cmid);
        $cm = get_coursemodule_from_id('assign', $cmid, 0, false, MUST_EXIST);
        $assign = new \assign($context, $cm, null);
        
        // Get grading manager for this module.
        $gradingmanager = get_grading_manager($context, 'mod_assign', 'submissions');
        $gradingmethod = $gradingmanager->get_active_method();

        // Check if using rubric or rubric_ranges
        if ($gradingmethod === 'rubric_ranges') {
            return $this->save_rubric_ranges_grades($assign, $userid, $aicriteria, $feedback);
        } else if ($gradingmethod === 'rubric') {
            return $this->save_standard_rubric_grades($assign, $userid, $aicriteria, $feedback);
        }

        debugging("Active grading method '{$gradingmethod}' is not supported", DEBUG_DEVELOPER);
        return false;
    }

    /**
     * Save grades for rubric_ranges grading method (custom implementation).
     * Based on coworker's Python integration code.
     *
     * @param \assign $assign
     * @param int $userid
     * @param array $aicriteria
     * @param string $feedback
     * @return bool
     */
    private function save_rubric_ranges_grades(\assign $assign, int $userid, array $aicriteria, string $feedback): bool {
        global $DB, $USER;

        $context = $assign->get_context();
        $manager = get_grading_manager($context, 'mod_assign', 'submissions');

        if ($manager->get_active_method() !== 'rubric_ranges') {
            debugging("Active grading method is not rubric_ranges", DEBUG_DEVELOPER);
            return false;
        }

        $controller = $manager->get_controller('rubric_ranges');
        $definition = $controller->get_definition();

        if (!$definition) {
            debugging("No rubric definition found", DEBUG_DEVELOPER);
            return false;
        }

        debugging("Rubric definition ID: {$definition->id}", DEBUG_DEVELOPER);

        // Get criteria from the definition
        $definition_criteria = $definition->rubric_criteria ?? [];

        debugging("Available rubric criteria IDs: " . implode(', ', array_keys($definition_criteria)), DEBUG_DEVELOPER);

        // Get or create grade record
        $submission = $assign->get_user_submission($userid, false);
        if (!$submission) {
            debugging("No submission found for user {$userid}", DEBUG_DEVELOPER);
            return false;
        }

        $grade = $assign->get_user_grade($userid, true);
        if (!$grade) {
            debugging("Could not get/create grade record", DEBUG_DEVELOPER);
            return false;
        }

        // Set grader if not set
        if (!$grade->grader) {
            $grade->grader = $USER->id;
            $assign->update_grade($grade);
        }

        // Build fillings array for the controller
        $fillings = ['criteria' => []];

        // Match AI criteria to Moodle criteria
        foreach ($aicriteria as $aicriterion) {
            $ainame = $this->normalize_name($aicriterion['name']);
            $aiscore = (float)($aicriterion['score'] ?? 0);
            $aifeedback = $aicriterion['feedback'] ?? '';

            // Find matching criterion in definition
            $matched_criterionid = null;
            foreach ($definition_criteria as $critid => $critdata) {
                if ($this->normalize_name($critdata['description']) === $ainame) {
                    $matched_criterionid = $critid;
                    break;
                }
            }

            if (!$matched_criterionid) {
                debugging("Criterion '{$aicriterion['name']}' not found in rubric definition", DEBUG_DEVELOPER);
                continue;
            }

            $criterion_def = $definition_criteria[$matched_criterionid];
            $levels = $criterion_def['levels'] ?? [];

            debugging("Processing criterion {$matched_criterionid}: {$criterion_def['description']}", DEBUG_DEVELOPER);

            // Find matching level by score
            $matched_levelid = null;

            foreach ($levels as $levelid => $level) {
                $levelscore = (float)$level['score'];
                if ($levelscore == $aiscore) {
                    $matched_levelid = $levelid;
                    debugging("Exact level match found: levelid={$levelid}, score={$levelscore}", DEBUG_DEVELOPER);
                    break;
                }
            }

            // If no exact match, find closest level
            if (!$matched_levelid && !empty($levels)) {
                $closest_diff = PHP_FLOAT_MAX;
                foreach ($levels as $levelid => $level) {
                    $levelscore = (float)$level['score'];
                    $diff = abs($levelscore - $aiscore);
                    if ($diff < $closest_diff) {
                        $closest_diff = $diff;
                        $matched_levelid = $levelid;
                    }
                }
                debugging("Closest level match: levelid={$matched_levelid}", DEBUG_DEVELOPER);
            }

            if (!$matched_levelid) {
                debugging("No suitable level found for criterion {$matched_criterionid}", DEBUG_DEVELOPER);
                continue;
            }

            // Add to fillings array in controller format
            $fillings['criteria'][$matched_criterionid] = [
                'levelid' => $matched_levelid,
                'remark' => $aifeedback,
            ];
        }

        if (empty($fillings['criteria'])) {
            debugging("Rubric_ranges: no criteria matched AI output, aborting write.", DEBUG_DEVELOPER);
            return false;
        }

        // Use the controller to create/update instance properly and mark it active so Moodle can render it.
        try {
            $instance = $controller->get_or_create_instance($grade->id, $grade->grader, $grade->id);
            $instance->update($fillings);
            $instance->submit_and_get_grade($fillings, $grade->id);
            debugging("Rubric_ranges grades saved & activated via controller", DEBUG_DEVELOPER);
        } catch (\Throwable $e) {
            debugging("Error saving rubric_ranges grades via controller: " . $e->getMessage(), DEBUG_DEVELOPER);
            return false;
        }

        debugging("Rubric_ranges grades saved successfully", DEBUG_DEVELOPER);
        return true;
    }

    /**
     * Save grades for standard rubric grading method.
     *
     * @param \assign $assign
     * @param int $userid
     * @param array $aicriteria
     * @param string $feedback
     * @return bool
     */
    private function save_standard_rubric_grades(\assign $assign, int $userid, array $aicriteria, string $feedback): bool {
        global $DB, $USER;

        $context = $assign->get_context();
        $manager = get_grading_manager($context, 'mod_assign', 'submissions');

        if ($manager->get_active_method() !== 'rubric') {
            debugging("Active grading method is not rubric", DEBUG_DEVELOPER);
            return false;
        }

        $controller = $manager->get_controller('rubric');
        $definition = $controller->get_definition();

        if (!$definition || empty($definition->rubric_criteria)) {
            debugging("No rubric definition found", DEBUG_DEVELOPER);
            return false;
        }

        debugging("Standard rubric definition ID: {$definition->id}", DEBUG_DEVELOPER);
        debugging("Available criteria IDs: " . implode(', ', array_keys($definition->rubric_criteria)), DEBUG_DEVELOPER);

        // Get or create grade record
        $submission = $assign->get_user_submission($userid, false);
        if (!$submission) {
            debugging("No submission found for user {$userid}", DEBUG_DEVELOPER);
            return false;
        }

        $grade = $assign->get_user_grade($userid, true);
        if (!$grade) {
            debugging("Could not get/create grade record", DEBUG_DEVELOPER);
            return false;
        }

        // Set grader if not set
        if (!$grade->grader) {
            $grade->grader = $USER->id;
            $assign->update_grade($grade);
        }

        $fillings = $this->map_standard_rubric_criteria($definition->rubric_criteria, $aicriteria);

        if (empty($fillings['criteria'])) {
            debugging("Standard rubric: no criteria matched AI output, aborting write.", DEBUG_DEVELOPER);
            return false;
        }

        // Use the controller to create/update instance properly and mark it active so Moodle can render it.
        try {
            $instance = $controller->get_or_create_instance($grade->id, $grade->grader, $grade->id);
            $instance->update($fillings);
            $instance->submit_and_get_grade($fillings, $grade->id);
            debugging("Standard rubric grades saved & activated via controller", DEBUG_DEVELOPER);
        } catch (\Throwable $e) {
            debugging("Error saving rubric grades via controller: " . $e->getMessage(), DEBUG_DEVELOPER);
            return false;
        }

        debugging("Standard rubric grades saved successfully", DEBUG_DEVELOPER);
        return true;
    }

    /**
     * Build advanced grading data structure for the standard rubric controller.
     *
     * @param array $definitioncriteria Rubric definition criteria array.
     * @param array $aicriteria AI output.
     * @return array
     */
    public function map_standard_rubric_criteria(array $definitioncriteria, array $aicriteria): array {
        $fillings = ['criteria' => []];

        foreach ($aicriteria as $aicriterion) {
            if (empty($aicriterion['name'])) {
                continue;
            }
            $ainame = $this->normalize_name($aicriterion['name']);
            $aiscore = (float)($aicriterion['score'] ?? 0);
            $aifeedback = $aicriterion['feedback'] ?? '';

            $matchedcriterionid = null;
            foreach ($definitioncriteria as $critid => $critdata) {
                if ($this->normalize_name($critdata['description']) === $ainame) {
                    $matchedcriterionid = $critid;
                    break;
                }
            }

            if (!$matchedcriterionid) {
                debugging("Criterion '{$aicriterion['name']}' not found in rubric definition", DEBUG_DEVELOPER);
                continue;
            }

            $criteriondef = $definitioncriteria[$matchedcriterionid];
            $levels = $criteriondef['levels'] ?? [];

            debugging("Processing criterion {$matchedcriterionid}: {$criteriondef['description']}", DEBUG_DEVELOPER);

            $matchedlevelid = null;
            foreach ($levels as $levelid => $level) {
                $levelscore = (float)$level['score'];
                if ($levelscore == $aiscore) {
                    $matchedlevelid = $levelid;
                    debugging("Exact level match found: levelid={$levelid}, score={$levelscore}", DEBUG_DEVELOPER);
                    break;
                }
            }

            if (!$matchedlevelid && !empty($levels)) {
                $closestdiff = PHP_FLOAT_MAX;
                foreach ($levels as $levelid => $level) {
                    $levelscore = (float)$level['score'];
                    $diff = abs($levelscore - $aiscore);
                    if ($diff < $closestdiff) {
                        $closestdiff = $diff;
                        $matchedlevelid = $levelid;
                    }
                }
                debugging("Closest level match: levelid={$matchedlevelid}", DEBUG_DEVELOPER);
            }

            if (!$matchedlevelid) {
                debugging("No suitable level found for criterion {$matchedcriterionid}", DEBUG_DEVELOPER);
                continue;
            }

            $fillings['criteria'][$matchedcriterionid] = [
                'levelid' => $matchedlevelid,
                'remark' => $aifeedback,
            ];
        }

        return $fillings;
    }

    /**
     * Normalize criterion names for fuzzy matching.
     * Handles extra spaces, newlines, and case differences.
     *
     * @param string $name
     * @return string
     */
    protected function normalize_name(string $name): string {
        // Trim whitespace, collapse multiple spaces, convert to lowercase
        $normalized = trim($name);
        $normalized = preg_replace('/\s+/', ' ', $normalized);
        $normalized = strtolower($normalized);
        return $normalized;
    }
}
