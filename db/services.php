<?php
defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_hlai_grading_get_ai_statuses' => [
        'classname'   => 'local_hlai_grading\external\get_ai_statuses',
        'methodname'  => 'execute',
        'description' => 'Get AI grading statuses for assignment submissions',
        'type'        => 'read',
        'ajax'        => true,
        'capabilities' => 'mod/assign:grade',
    ],
    'local_hlai_grading_get_grade_explanation' => [
        'classname'   => 'local_hlai_grading\external\get_grade_explanation',
        'methodname'  => 'execute',
        'description' => 'Fetch the AI explanation payload for a released submission',
        'type'        => 'read',
        'ajax'        => true,
    ],
    'local_hlai_grading_release_grade' => [
        'classname'   => 'local_hlai_grading\external\manage_grade',
        'methodname'  => 'release',
        'description' => 'Release a draft AI result to the learner',
        'type'        => 'write',
        'ajax'        => true,
        'capabilities' => 'local/hlai_grading:releasegrades',
    ],
    'local_hlai_grading_reject_grade' => [
        'classname'   => 'local_hlai_grading\external\manage_grade',
        'methodname'  => 'reject',
        'description' => 'Reject an AI-generated grade so the teacher can grade manually',
        'type'        => 'write',
        'ajax'        => true,
        'capabilities' => 'local/hlai_grading:releasegrades',
    ],
    'local_hlai_grading_get_audit_log' => [
        'classname'   => 'local_hlai_grading\external\get_audit_log',
        'methodname'  => 'execute',
        'description' => 'Export audit trail entries for AI grading actions',
        'type'        => 'read',
        'capabilities' => 'local/hlai_grading:releasegrades',
    ],
    'local_hlai_grading_get_queue_stats' => [
        'classname'   => 'local_hlai_grading\external\get_queue_stats',
        'methodname'  => 'execute',
        'description' => 'Aggregate queue metrics for AI grading jobs',
        'type'        => 'read',
        'capabilities' => 'local/hlai_grading:viewlogs',
    ],
    'local_hlai_grading_trigger_batch' => [
        'classname'   => 'local_hlai_grading\external\trigger_batch',
        'methodname'  => 'execute',
        'description' => 'Queue assignment submissions for AI grading (batch trigger)',
        'type'        => 'write',
        'capabilities' => 'local/hlai_grading:batchgrade',
    ],
    'local_hlai_grading_get_result' => [
        'classname'   => 'local_hlai_grading\external\get_result',
        'methodname'  => 'execute',
        'description' => 'Fetch the full AI grading result payload',
        'type'        => 'read',
        'capabilities' => 'local/hlai_grading:viewresults',
    ],
];

$services = [
    'HL AI grading API' => [
        'functions' => [
            'local_hlai_grading_get_ai_statuses',
            'local_hlai_grading_get_grade_explanation',
            'local_hlai_grading_release_grade',
            'local_hlai_grading_reject_grade',
            'local_hlai_grading_get_audit_log',
            'local_hlai_grading_get_queue_stats',
            'local_hlai_grading_trigger_batch',
            'local_hlai_grading_get_result',
        ],
        'restrictedusers' => 0,
        'enabled' => 1,
        'shortname' => 'hlai_grading_api',
    ],
];
