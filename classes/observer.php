<?php
namespace local_hlai_grading;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../lib.php');

use local_hlai_grading\local\queuer;
use local_hlai_grading\local\content_extractor;

class observer {

    /**
     * Fired when an assignment submission is made.
     *
     * @param \mod_assign\event\assessable_submitted $event
     * @return bool
     */
    public static function assign_submitted($event): bool {
        global $USER, $DB, $CFG;

        require_once($CFG->dirroot . '/mod/assign/locallib.php');

        $data = $event->get_data();

        $context = $event->get_context();
        // For module context, we need the course module ID, not instance ID
        // context->instanceid is the CM ID for CONTEXT_MODULE
        if ($context && $context->contextlevel == CONTEXT_MODULE) {
            $cmid = $context->instanceid;
        } else {
            $cmid = $data['contextinstanceid'] ?? $data['other']['cmid'] ?? null;
        }
        
        $courseid = $data['courseid'] ?? null;
        $userid   = $event->relateduserid ?? $data['userid'] ?? $USER->id;

        // Try to resolve the assign instance so we can push grades later.
        $assignid = null;
        $submissiontext = '';
        $assignmentname = '';
        $keytext = '';
        $filesummary = [];
        $submission = null;
        
        if ($cmid && $courseid) {
            $cm = get_coursemodule_from_id('assign', $cmid, $courseid, false, IGNORE_MISSING);
            if ($cm && !empty($cm->instance)) {
                $assignid = (int)$cm->instance;
                
                // Get the assignment object to fetch submission text and grading key.
                try {
                    $assigncontext = \context_module::instance($cm->id);
                    $assign = new \assign($assigncontext, $cm, null);
                    $assignmentname = $assign->get_instance()->name;
                    
                    // Get the submission
                    $submission = $assign->get_user_submission($userid, false);
                    
                    if ($submission) {
                        $extracted = content_extractor::extract_from_assignment($assign, $submission);
                        $submissiontext = $extracted['text'] ?? '';
                        $filesummary = $extracted['files'] ?? [];
                    }
                    
                    $assigninstance = $assign->get_instance();
                    $graderinfo = '';
                    if ($assigninstance && property_exists($assigninstance, 'gradinginstructions')) {
                        $graderinfo = $assigninstance->gradinginstructions ?? '';
                    }
                    if ($graderinfo !== '') {
                        $formatted = format_text($graderinfo, $assigninstance->gradinginstructionsformat ?? FORMAT_HTML, ['context' => $assigncontext]);
                        $keytext = trim(strip_tags($formatted));
                    }

                } catch (\Exception $e) {
                    // If we can't get submission or grading key, continue anyway
                    $submissiontext = '';
                    $keytext = '';
                }
            }
        }

        // Fall back to AI grading custom instructions when assignment grading key is missing.
        if ($assignid && $keytext === '') {
            try {
                $settings = \local_hlai_grading_get_activity_settings('assign', (int)$assignid);
                $custom = trim((string)($settings->custominstructions ?? ''));
                if ($custom !== '') {
                    $keytext = $custom;
                }
            } catch (\Throwable $e) {
                // Ignore settings lookup errors.
            }
        }

        // SPEC: Check if AI grading is enabled for this assignment/activity.
        if (!$assignid || !\local_hlai_grading_is_activity_enabled('assign', $assignid)) {
            return true;
        }

        // Enrich the payload – we keep everything in JSON, no schema change.
        $payload = $data;
        $payload['userid']   = $userid;
        $payload['courseid'] = $courseid;
        $payload['cmid']     = $cmid;
        $payload['assignid'] = $assignid;
        $payload['modulename'] = 'assign';
        $payload['instanceid'] = $assignid;
        $payload['submissiontext'] = $submissiontext;
        $payload['submissionid'] = $submission->id ?? null;
        $payload['assignment'] = $assignmentname;
        $payload['keytext'] = $keytext;
        if (!empty($filesummary)) {
            $payload['submissionfiles'] = $filesummary;
        }

        $rubricsnapshot = null;
        $rubricjson = null;
        if ($assignid) {
            try {
                $rubricsnapshot = rubric_analyzer::get_rubric('assign', $assignid, $cmid);
                if ($rubricsnapshot) {
                    $rubricjson = rubric_analyzer::rubric_to_json($rubricsnapshot);
                }
            } catch (\Throwable $e) {
                $rubricsnapshot = null;
            }
        }
        if ($rubricsnapshot) {
            $payload['rubric_snapshot'] = $rubricsnapshot;
            if ($rubricjson) {
                $payload['rubric_json'] = $rubricjson;
            }
        }

        $queuer = new queuer();
        $queuer->queue_submission(
            $userid,
            $courseid,
            $cmid,
            $event->eventname,
            $payload
        );

        // SPEC: Set workflow to 'inmarking' when queued for AI grading
        if ($assignid && $userid) {
            \local_hlai_grading\local\workflow_manager::set_assign_state($assignid, $userid, 'inmarking');
        }

        return true;
    }

    /**
     * Fired when a quiz attempt is submitted.
     *
     * @param \mod_quiz\event\attempt_submitted $event
     * @return bool
     */
    public static function quiz_attempt_submitted($event): bool {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        require_once($CFG->dirroot . '/question/engine/lib.php');

        $attemptid = $event->objectid ?? 0;
        if (!$attemptid) {
            return true;
        }

        $attempt = $DB->get_record('quiz_attempts', ['id' => $attemptid], '*', IGNORE_MISSING);
        if (!$attempt) {
            return true;
        }

        $quiz = $DB->get_record('quiz', ['id' => $attempt->quiz], '*', IGNORE_MISSING);
        if (!$quiz) {
            return true;
        }

        if (!\local_hlai_grading_is_activity_enabled('quiz', (int)$quiz->id)) {
            return true;
        }
        $cm = get_coursemodule_from_instance('quiz', $quiz->id, $quiz->course, false, IGNORE_MISSING);
        if (!$cm) {
            return true;
        }

        $activitiesettings = \local_hlai_grading_get_activity_settings('quiz', (int)$quiz->id);
        $rubricjson = null;
        if (!empty($activitiesettings->rubricid)) {
            $rubricjson = \local_hlai_grading_get_quiz_rubric_json((int)$activitiesettings->rubricid);
        }

        $quba = \question_engine::load_questions_usage_by_activity($attempt->uniqueid);
        $slots = $quba->get_slots();

        if (empty($slots)) {
            return true;
        }

        $queuer = new queuer();
        $queued = false;

        foreach ($slots as $slot) {
            if (!\question_engine::is_manual_grade_in_range($attempt->uniqueid, $slot)) {
                continue;
            }

            $qa = $quba->get_question_attempt($slot);
            $question = $qa->get_question();

            if (!$question || !in_array($question->get_type_name(), ['essay'], true)) {
                continue;
            }

            $answer = trim((string)$qa->get_last_qt_var('answer'));
            if ($answer === '') {
                $answer = trim((string)$qa->get_response_summary());
            }

            $submissionfiles = [];
            if ($answer === '' && method_exists($qa, 'get_last_qt_files')) {
                try {
                    $attachments = $qa->get_last_qt_files('attachments');
                } catch (\Throwable $e) {
                    $attachments = [];
                }
                if (!empty($attachments)) {
                    $filetext = [];
                    foreach ($attachments as $file) {
                        if ($file->is_directory()) {
                            continue;
                        }
                        $extracted = content_extractor::extract_file($file);
                        if (!empty($extracted['text'])) {
                            $filetext[] = $extracted['text'];
                            $submissionfiles[] = $file->get_filename();
                            continue;
                        }
                        $submissionfiles[] = $file->get_filename() . (!empty($extracted['error']) ? ' (error: ' . $extracted['error'] . ')' : '');
                    }
                    if (!empty($filetext)) {
                        $answer = trim(implode("\n\n", $filetext));
                    } else if (!empty($submissionfiles)) {
                        $answer = 'Student submitted the following files: ' . implode(', ', $submissionfiles) .
                            '. The system could not automatically extract full text. Please review them manually.';
                    }
                }
            }

            if ($answer === '') {
                continue;
            }

            $questiontext = strip_tags($question->questiontext, '<p><br><strong><em><ul><ol><li>');

            $graderinfo = '';
            if (property_exists($question, 'graderinfo')) {
                $graderinfo = $question->graderinfo ?? '';
            }
            $keytext = '';
            if ($graderinfo !== '') {
                $formatted = format_text($graderinfo, $question->graderinfoformat ?? FORMAT_HTML, ['context' => \context_module::instance($cm->id)]);
                $keytext = trim(strip_tags($formatted));
            }

            $payload = [
                'userid' => $attempt->userid,
                'courseid' => $quiz->course,
                'cmid' => $cm->id,
                'quizid' => $quiz->id,
                'attemptid' => $attempt->id,
                'questionid' => $question->id,
                'slot' => $slot,
                'modulename' => 'quiz',
                'instanceid' => $quiz->id,
                'question' => $questiontext ?: format_string($question->name),
                'questionname' => format_string($question->name),
                'submissiontext' => $answer,
                'submissionfiles' => $submissionfiles,
                'keytext' => $keytext,
                'maxmark' => $qa->get_max_mark(),
            ];
            if ($rubricjson) {
                $payload['rubric_json'] = $rubricjson;
            }

            $queuer->queue_submission(
                $attempt->userid,
                $quiz->course,
                $cm->id,
                $event->eventname,
                $payload
            );

            $queued = true;
        }

        return $queued;
    }
}
