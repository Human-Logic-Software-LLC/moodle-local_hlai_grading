<?php
namespace local_hlai_grading\external;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/externallib.php');

use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;
use local_hlai_grading\local\result_service;


/**
 * Web service endpoints for releasing/rejecting AI grades.
 */
class manage_grade extends external_api {

    /**
     * Parameters for release.
     */
    public static function release_parameters(): external_function_parameters {
        return new external_function_parameters([
            'resultid' => new external_value(PARAM_INT, 'AI result ID'),
        ]);
    }

    /**
     * Release a draft AI grade.
     */
    public static function release(int $resultid): array {
        global $USER;

        $params = self::validate_parameters(self::release_parameters(), ['resultid' => $resultid]);
        $data = result_service::get_result_context($params['resultid']);
        self::validate_context($data['context']);
        require_capability('local/hlai_grading:releasegrades', $data['context']);
        $requiredcap = $data['modulename'] === 'quiz' ? 'mod/quiz:grade' : 'mod/assign:grade';
        require_capability($requiredcap, $data['context']);

        $response = result_service::release($data, $USER->id);
        $response['courseid'] = $data['course']->id;
        $response['userid'] = $data['result']->userid;
        return $response;
    }

    /**
     * Return structure for release.
     */
    public static function release_returns(): external_single_structure {
        return new external_single_structure([
            'resultid' => new external_value(PARAM_INT, 'Result ID'),
            'status' => new external_value(PARAM_TEXT, 'New status'),
            'timereviewed' => new external_value(PARAM_INT, 'Timestamp when reviewed'),
            'courseid' => new external_value(PARAM_INT, 'Course ID', \VALUE_OPTIONAL),
            'userid' => new external_value(PARAM_INT, 'Student ID', \VALUE_OPTIONAL),
        ]);
    }

    /**
     * Parameters for reject.
     */
    public static function reject_parameters(): external_function_parameters {
        return new external_function_parameters([
            'resultid' => new external_value(PARAM_INT, 'AI result ID'),
            'reason' => new external_value(PARAM_TEXT, 'Optional rejection note', \VALUE_DEFAULT, null),
        ]);
    }

    /**
     * Reject an AI grade.
     */
    public static function reject(int $resultid, string $reason = null): array {
        global $USER;

        $params = self::validate_parameters(self::reject_parameters(), ['resultid' => $resultid, 'reason' => $reason]);
        $data = result_service::get_result_context($params['resultid']);
        self::validate_context($data['context']);
        require_capability('local/hlai_grading:releasegrades', $data['context']);
        $requiredcap = $data['modulename'] === 'quiz' ? 'mod/quiz:grade' : 'mod/assign:grade';
        require_capability($requiredcap, $data['context']);

        $response = result_service::reject($data, $USER->id, $params['reason'] ?? null);
        $response['courseid'] = $data['course']->id;
        $response['userid'] = $data['result']->userid;
        return $response;
    }

    /**
     * Return structure for reject.
     */
    public static function reject_returns(): external_single_structure {
        return new external_single_structure([
            'resultid' => new external_value(PARAM_INT, 'Result ID'),
            'status' => new external_value(PARAM_TEXT, 'New status'),
            'timereviewed' => new external_value(PARAM_INT, 'Timestamp when reviewed'),
            'courseid' => new external_value(PARAM_INT, 'Course ID', \VALUE_OPTIONAL),
            'userid' => new external_value(PARAM_INT, 'Student ID', \VALUE_OPTIONAL),
        ]);
    }
}
