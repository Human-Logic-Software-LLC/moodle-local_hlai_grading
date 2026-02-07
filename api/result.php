<?php
define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../../config.php');

$id = required_param('id', PARAM_INT);

require_login();

global $DB;

$result = $DB->get_record('hlai_grading_results', ['id' => $id], '*', MUST_EXIST);

switch ($result->modulename) {
    case 'assign':
        $activity = $DB->get_record('assign', ['id' => $result->instanceid], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('assign', $activity->id, $activity->course, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        $courseid = $activity->course;
        break;
    case 'quiz':
        $activity = $DB->get_record('quiz', ['id' => $result->instanceid], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('quiz', $activity->id, $activity->course, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        $courseid = $activity->course;
        break;
    default:
        throw new moodle_exception('invalidmodule', 'local_hlai_grading');
}

require_capability('local/hlai_grading:viewresults', $context);

$response = [
    'id' => (int)$result->id,
    'queueid' => (int)$result->queueid,
    'grade' => isset($result->grade) ? (float)$result->grade : null,
    'maxgrade' => isset($result->maxgrade) ? (float)$result->maxgrade : null,
    'reasoning' => $result->reasoning ?? '',
    'confidence' => isset($result->confidence) ? (float)$result->confidence : null,
    'model' => $result->model ?? null,
    'status' => $result->status,
    'reviewed' => (bool)$result->reviewed,
    'timecreated' => (int)$result->timecreated,
    'modulename' => $result->modulename,
    'instanceid' => (int)$result->instanceid,
    'rubric_analysis' => $result->rubric_analysis ? json_decode($result->rubric_analysis, true) : null,
];

core\session\manager::write_close();
header('Content-Type: application/json');
echo json_encode($response);
exit;
