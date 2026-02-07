<?php
defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        'eventname'   => '\mod_assign\event\assessable_submitted',
        'callback'    => '\local_hlai_grading\observer::assign_submitted',
        'includefile' => '/local/hlai_grading/classes/observer.php',
        'priority'    => 9999,
    ],
    [
        'eventname'   => '\mod_quiz\event\attempt_submitted',
        'callback'    => '\local_hlai_grading\observer::quiz_attempt_submitted',
        'includefile' => '/local/hlai_grading/classes/observer.php',
        'priority'    => 9999,
    ],
];
