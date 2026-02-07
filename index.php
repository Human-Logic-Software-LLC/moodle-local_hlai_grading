<?php
require('../../config.php');
require_login();

$context = context_system::instance();
require_capability('local/hlai_grading:viewresults', $context);

$PAGE->set_url('/local/hlai_grading/index.php');
$PAGE->set_context($context);
$PAGE->set_title(get_string('pluginname', 'local_hlai_grading'));
$PAGE->set_heading(get_string('pluginname', 'local_hlai_grading'));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('pluginname', 'local_hlai_grading'));
echo $OUTPUT->notification('AI Grading plugin installed. This page is for testing.', 'notifysuccess');
echo $OUTPUT->footer();
