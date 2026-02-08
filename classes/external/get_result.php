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
 * External function to get grading result.
 *
 * @package    local_hlai_grading
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_hlai_grading\external;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/externallib.php');

use external_api;
use external_function_parameters;
use external_multiple_structure;
use external_single_structure;
use external_value;
use local_hlai_grading\local\result_service;


/**
 * REST handler for fetching a full AI result payload (Spec §9.3 GET /result/:id).
 */
class get_result extends external_api {
    /**
     * Define input parameters.
     *
     * @return external_function_parameters The result.
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'resultid' => new external_value(PARAM_INT, 'AI result ID'),
        ]);
    }

    /**
     * Fetch a single AI result (teachers/admins only).
     *
     * @param int $resultid Resultid.
     * @return array The result array.
     */
    public static function execute(int $resultid): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'resultid' => $resultid,
        ]);

        $contextdata = result_service::get_result_context($params['resultid']);
        $context = $contextdata['context'];
        self::validate_context($context);

        require_capability('local/hlai_grading:viewresults', $context);
        $requiredcap = $contextdata['modulename'] === 'quiz' ? 'mod/quiz:grade' : 'mod/assign:grade';
        require_capability($requiredcap, $context);

        $result = $contextdata['result'];

        $criteriarecords = $DB->get_records(
            'local_hlai_grading_rubric_scores',
            ['resultid' => $result->id],
            'id ASC'
        );

        $criteria = [];
        foreach ($criteriarecords as $record) {
            $criteria[] = [
                'criterionid' => (int)$record->criterionid,
                'name' => $record->criterionname ?? '',
                'levelid' => isset($record->levelid) ? (int)$record->levelid : 0,
                'levelname' => $record->levelname ?? '',
                'score' => isset($record->score) ? (float)$record->score : 0.0,
                'maxscore' => isset($record->maxscore) ? (float)$record->maxscore : 0.0,
                'reasoning' => $record->reasoning ?? '',
            ];
        }

        $strengths = json_decode($result->strengths_json ?? '[]', true) ?: [];
        $improvements = json_decode($result->improvements_json ?? '[]', true) ?: [];
        $examples = json_decode($result->examples_json ?? '[]', true) ?: [];
        $rubricanalysis = $result->rubric_analysis ?: null;

        $response = [
            'id' => (int)$result->id,
            'queueid' => (int)$result->queueid,
            'userid' => (int)$result->userid,
            'modulename' => (string)$result->modulename,
            'instanceid' => (int)$result->instanceid,
            'submissionid' => $result->submissionid ? (int)$result->submissionid : 0,
            'attemptid' => $result->attemptid ? (int)$result->attemptid : 0,
            'slot' => $result->slot ? (int)$result->slot : 0,
            'status' => (string)$result->status,
            'reviewed' => (bool)$result->reviewed,
            'reviewer_id' => $result->reviewer_id ? (int)$result->reviewer_id : 0,
            'timecreated' => (int)$result->timecreated,
            'timereviewed' => $result->timereviewed ? (int)$result->timereviewed : 0,
            'reasoning' => (string)($result->reasoning ?? ''),
            'strengths' => $strengths,
            'improvements' => $improvements,
            'criteria' => $criteria,
            'highlighted_examples' => $examples,
        ];

        if (!is_null($result->grade)) {
            $response['grade'] = (float)$result->grade;
        }
        if (!is_null($result->maxgrade)) {
            $response['maxgrade'] = (float)$result->maxgrade;
        }
        if (!is_null($result->confidence)) {
            $response['confidence'] = (float)$result->confidence;
        }
        if (!empty($result->model)) {
            $response['model'] = (string)$result->model;
        }
        if (!empty($result->quality)) {
            $response['quality'] = (string)$result->quality;
        }
        if (!empty($result->grademethod)) {
            $response['grademethod'] = (string)$result->grademethod;
        }
        if (!empty($result->tokens_used)) {
            $response['tokens_used'] = (int)$result->tokens_used;
        }
        if (!empty($result->prompttokens)) {
            $response['prompttokens'] = (int)$result->prompttokens;
        }
        if (!empty($result->completiontokens)) {
            $response['completiontokens'] = (int)$result->completiontokens;
        }
        if (!empty($result->processing_time)) {
            $response['processing_time'] = (int)$result->processing_time;
        }
        if (!empty($rubricanalysis)) {
            $response['rubric_analysis'] = (string)$rubricanalysis;
        }

        return $response;
    }

    /**
     * Define return structure.
     *
     * @return external_single_structure The result.
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Result ID'),
            'queueid' => new external_value(PARAM_INT, 'Queue ID'),
            'userid' => new external_value(PARAM_INT, 'Student ID'),
            'modulename' => new external_value(PARAM_ALPHANUMEXT, 'Module name'),
            'instanceid' => new external_value(PARAM_INT, 'Module instance ID'),
            'submissionid' => new external_value(PARAM_INT, 'Assignment submission ID', \VALUE_OPTIONAL),
            'attemptid' => new external_value(PARAM_INT, 'Quiz attempt ID', \VALUE_OPTIONAL),
            'slot' => new external_value(PARAM_INT, 'Quiz slot number', \VALUE_OPTIONAL),
            'grade' => new external_value(PARAM_FLOAT, 'AI grade', \VALUE_OPTIONAL),
            'maxgrade' => new external_value(PARAM_FLOAT, 'Maximum grade', \VALUE_OPTIONAL),
            'status' => new external_value(PARAM_ALPHANUMEXT, 'Result status'),
            'reviewed' => new external_value(PARAM_BOOL, 'Whether a teacher reviewed this result'),
            'reviewer_id' => new external_value(PARAM_INT, 'Reviewer user ID', \VALUE_OPTIONAL),
            'timecreated' => new external_value(PARAM_INT, 'Timestamp when the result was created'),
            'timereviewed' => new external_value(PARAM_INT, 'Timestamp when reviewed', \VALUE_OPTIONAL),
            'reasoning' => new external_value(PARAM_RAW, 'AI reasoning / explanation'),
            'confidence' => new external_value(PARAM_FLOAT, 'AI confidence score', \VALUE_OPTIONAL),
            'model' => new external_value(PARAM_TEXT, 'Model identifier', \VALUE_OPTIONAL),
            'quality' => new external_value(PARAM_TEXT, 'Processing quality/mode', \VALUE_OPTIONAL),
            'grademethod' => new external_value(PARAM_TEXT, 'Grading method in Moodle', \VALUE_OPTIONAL),
            'tokens_used' => new external_value(PARAM_INT, 'Total tokens used', \VALUE_OPTIONAL),
            'prompttokens' => new external_value(PARAM_INT, 'Prompt tokens', \VALUE_OPTIONAL),
            'completiontokens' => new external_value(PARAM_INT, 'Completion tokens', \VALUE_OPTIONAL),
            'processing_time' => new external_value(PARAM_INT, 'Processing time in seconds', \VALUE_OPTIONAL),
            'strengths' => new external_multiple_structure(
                new external_value(PARAM_TEXT, 'Strength text'),
                'List of strengths identified by AI'
            ), 'improvements' => new external_multiple_structure(
                new external_value(PARAM_TEXT, 'Improvement suggestion'),
                'List of improvement suggestions'
            ),
            'criteria' => new external_multiple_structure(
                new external_single_structure([
                    'criterionid' => new external_value(PARAM_INT, 'Criterion ID'),
                    'name' => new external_value(PARAM_TEXT, 'Criterion name'),
                    'levelid' => new external_value(PARAM_INT, 'Rubric level ID', \VALUE_OPTIONAL),
                    'levelname' => new external_value(PARAM_TEXT, 'Rubric level name', \VALUE_OPTIONAL),
                    'score' => new external_value(PARAM_FLOAT, 'Score earned'),
                    'maxscore' => new external_value(PARAM_FLOAT, 'Maximum score'),
                    'reasoning' => new external_value(PARAM_RAW, 'Per-criterion reasoning'),
                ])
            ),
            'highlighted_examples' => new external_multiple_structure(
                new external_single_structure([
                    'label' => new external_value(PARAM_TEXT, 'Label for the example'),
                    'text' => new external_value(PARAM_TEXT, 'Excerpt provided to the teacher'),
                    'comment' => new external_value(PARAM_RAW, 'Explanation for the example'),
                    'type' => new external_value(PARAM_ALPHA, 'Example type (strength/improvement)'),
                ])
            ),
            'rubric_analysis' => new external_value(
                PARAM_RAW,
                'Raw rubric analyzer payload (JSON)',
                \VALUE_OPTIONAL
            ),
        ]);
    }
}
