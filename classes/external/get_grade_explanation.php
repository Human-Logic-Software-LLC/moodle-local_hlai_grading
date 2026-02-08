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
 * External function to get grade explanation.
 *
 * @package    local_hlai_grading
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_hlai_grading\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

use external_api;
use external_function_parameters;
use external_multiple_structure;
use external_single_structure;
use external_value;

/**
 * External API for fetching the AI explanation that students see after release.
 *
 * Mirrors §21.7 in AI_Grading_Technical_Spec.
 */
class get_grade_explanation extends external_api {
    /**
     * Define parameters.
     *
     * @return external_function_parameters The result.
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'submissionid' => new external_value(PARAM_INT, 'Assignment submission ID'),
        ]);
    }

    /**
     * Return explanation payload for a submission.
     *
     * @param int $submissionid Submissionid.
     * @return array The result array.
     */
    public static function execute($submissionid): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'submissionid' => $submissionid,
        ]);

        $submission = $DB->get_record('assign_submission', ['id' => $params['submissionid']], '*', MUST_EXIST);
        $assign = $DB->get_record('assign', ['id' => $submission->assignment], '*', MUST_EXIST);
        $course = $DB->get_record('course', ['id' => $assign->course], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('assign', $assign->id, $assign->course, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);

        require_course_login($course, false, $cm);

        if ((int)$submission->userid !== (int)$USER->id) {
            require_capability('mod/assign:grade', $context);
        } else {
            require_capability('mod/assign:view', $context);
        }

        $records = $DB->get_records(
            'local_hlai_grading_results',
            ['submissionid' => $submission->id],
            'timereviewed DESC, timecreated DESC, id DESC'
        );

        $result = null;
        foreach ($records as $record) {
            if ($record->status === 'released') {
                $result = $record;
                break;
            }
        }

        if (!$result) {
            throw new \moodle_exception('no_ai_grade_found', 'local_hlai_grading');
        }

        $criteriarecords = $DB->get_records('local_hlai_grading_rubric_scores', ['resultid' => $result->id], 'id ASC');

        $criteria = [];
        foreach ($criteriarecords as $record) {
            $criteria[] = [
                'id' => (int)$record->criterionid,
                'name' => $record->criterionname ?: get_string('criterion', 'gradingform_rubric'),
                'level_id' => isset($record->levelid) ? (int)$record->levelid : 0,
                'level_name' => $record->levelname ?? '',
                'points' => isset($record->score) ? (float)$record->score : 0.0,
                'maxpoints' => isset($record->maxscore) ? (float)$record->maxscore : 0.0,
                'reasoning' => $record->reasoning ?? '',
            ];
        }

        return [
            'grade' => isset($result->grade) ? (float)$result->grade : 0.0,
            'maxgrade' => isset($result->maxgrade) ? (float)$result->maxgrade : 0.0,
            'reasoning' => (string)($result->reasoning ?? ''),
            'confidence' => isset($result->confidence) ? (float)$result->confidence : 0.0,
            'strengths' => json_decode($result->strengths_json ?? '[]', true) ?: [],
            'improvements' => json_decode($result->improvements_json ?? '[]', true) ?: [],
            'criteria' => $criteria,
            'highlighted_examples' => json_decode($result->examples_json ?? '[]', true) ?: [],
        ];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure The result.
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'grade' => new external_value(PARAM_FLOAT, 'Final grade'),
            'maxgrade' => new external_value(PARAM_FLOAT, 'Maximum grade'),
            'reasoning' => new external_value(PARAM_RAW, 'Overall similarity explanation'),
            'confidence' => new external_value(PARAM_FLOAT, 'Similarity percentage'),
            'strengths' => new external_multiple_structure(
                new external_value(PARAM_TEXT, 'Strength description')
            ),
            'improvements' => new external_multiple_structure(
                new external_value(PARAM_TEXT, 'Improvement suggestion')
            ),
            'criteria' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'Criterion ID'),
                    'name' => new external_value(PARAM_TEXT, 'Criterion name'),
                    'level_id' => new external_value(PARAM_INT, 'Selected level ID'),
                    'level_name' => new external_value(PARAM_TEXT, 'Selected level name'),
                    'points' => new external_value(PARAM_FLOAT, 'Points earned'),
                    'maxpoints' => new external_value(PARAM_FLOAT, 'Maximum points'),
                    'reasoning' => new external_value(PARAM_RAW, 'Criterion reasoning'),
                ])
            ),
            'highlighted_examples' => new external_multiple_structure(
                new external_single_structure([
                    'label' => new external_value(PARAM_TEXT, 'Example label'),
                    'text' => new external_value(PARAM_TEXT, 'Quoted text'),
                    'comment' => new external_value(PARAM_RAW, 'Explanation text'),
                    'type' => new external_value(PARAM_ALPHA, 'strength|improvement'),
                ])
            ),
        ]);
    }
}
