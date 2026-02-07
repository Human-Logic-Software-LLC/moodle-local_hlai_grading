<?php
define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../../../config.php');

require_login();

$context = context_system::instance();
require_capability('local/hlai_grading:viewlogs', $context);

global $DB;

$stats = $DB->get_record_sql(
    "SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending,
        SUM(CASE WHEN status = 'done' THEN 1 ELSE 0 END) AS completed,
        SUM(CASE WHEN status IN ('failed', 'error') THEN 1 ELSE 0 END) AS failed
     FROM {hlai_grading_queue}"
);

$processingtime = $DB->get_field_sql(
    "SELECT AVG(timecompleted - timecreated)
       FROM {hlai_grading_queue}
      WHERE status = 'done' AND timecompleted IS NOT NULL"
);

$oldestpending = $DB->get_field_sql(
    "SELECT MIN(timecreated)
       FROM {hlai_grading_queue}
      WHERE status = 'pending'"
);

$response = [
    'pending' => (int)($stats->pending ?? 0),
    'processing' => 0,
    'completed' => (int)($stats->completed ?? 0),
    'failed' => (int)($stats->failed ?? 0),
    'total' => (int)($stats->total ?? 0),
    'oldest_pending' => $oldestpending ? (int)$oldestpending : null,
    'average_processing_time' => $processingtime ? (float)$processingtime : null,
];

core\session\manager::write_close();

header('Content-Type: application/json');
echo json_encode($response);
exit;
