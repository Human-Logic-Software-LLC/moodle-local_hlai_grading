<?php
define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../../../config.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    throw new moodle_exception('invalidaccess', 'error');
}

require_login();
require_sesskey();

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    throw new invalid_parameter_exception('Invalid JSON payload.');
}

$modulename = isset($input['modulename']) ? clean_param($input['modulename'], PARAM_ALPHA) : 'assign';
$instanceid = isset($input['instanceid']) ? clean_param($input['instanceid'], PARAM_INT) : 0;
$userids = isset($input['userids']) && is_array($input['userids']) ? array_map('intval', $input['userids']) : [];

if ($modulename !== 'assign' || $instanceid <= 0) {
    throw new moodle_exception('invalidmodule', 'local_hlai_grading');
}

global $DB, $USER;

$assignment = $DB->get_record('assign', ['id' => $instanceid], '*', MUST_EXIST);
$course = $DB->get_record('course', ['id' => $assignment->course], '*', MUST_EXIST);
$cm = get_coursemodule_from_instance('assign', $assignment->id, $course->id, false, MUST_EXIST);
$context = context_module::instance($cm->id);

require_capability('local/hlai_grading:batchgrade', $context);
require_capability('mod/assign:grade', $context);

\local_hlai_grading\local\rate_limiter::enforce($USER->id, 'batchgrade');

require_once($CFG->dirroot . '/mod/assign/locallib.php');
$assigninstance = new \assign($context, $cm, $course);

if (empty($userids)) {
    $userids = $DB->get_fieldset_select(
        'assign_submission',
        'userid',
        'assignment = :assignment AND status = :status',
        ['assignment' => $assignment->id, 'status' => 'submitted']
    );
}

$userids = array_unique(array_filter($userids));

$queued = 0;
$already = 0;
$errors = [];

$rubricsnapshot = \local_hlai_grading\rubric_analyzer::get_rubric('assign', $assignment->id, $cm->id);
$rubricjson = $rubricsnapshot ? \local_hlai_grading\rubric_analyzer::rubric_to_json($rubricsnapshot) : null;

$queuer = new \local_hlai_grading\local\queuer();

foreach ($userids as $targetuserid) {
    try {
        $submission = $assigninstance->get_user_submission($targetuserid, false);
        if (!$submission || $submission->status !== 'submitted') {
            continue;
        }

        $exists = $DB->record_exists_select(
            'hlai_grading_results',
            'modulename = :mod AND instanceid = :instance AND userid = :userid AND status <> :rejected',
            [
                'mod' => 'assign',
                'instance' => $assignment->id,
                'userid' => $targetuserid,
                'rejected' => 'rejected',
            ]
        );
        if ($exists) {
            $already++;
            continue;
        }

        $extracted = \local_hlai_grading\local\content_extractor::extract_from_assignment($assigninstance, $submission);

        $payload = [
            'userid' => $targetuserid,
            'courseid' => $course->id,
            'cmid' => $cm->id,
            'assignid' => $assignment->id,
            'modulename' => 'assign',
            'instanceid' => $assignment->id,
            'submissionid' => $submission->id,
            'submissiontext' => $extracted['text'] ?? '',
            'assignment' => $assignment->name,
            'rubric_json' => $rubricjson,
            'submissionfiles' => $extracted['files'] ?? [],
        ];

        if ($rubricsnapshot) {
            $payload['rubric_snapshot'] = $rubricsnapshot;
        }

        $queueid = $queuer->queue_submission(
            $targetuserid,
            $course->id,
            $cm->id,
            'manual_batch',
            $payload
        );

        \local_hlai_grading\local\workflow_manager::log_action(
            $queueid,
            null,
            'batchqueued',
            $USER->id,
            'Batch API request'
        );

        $queued++;
    } catch (\Throwable $e) {
        $errors[] = [
            'userid' => $targetuserid,
            'message' => $e->getMessage(),
        ];
    }
}

core\session\manager::write_close();

header('Content-Type: application/json');
echo json_encode([
    'queued' => $queued,
    'already_graded' => $already,
    'errors' => $errors,
]);
exit;
