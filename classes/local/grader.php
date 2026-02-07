<?php
namespace local_hlai_grading\local;

defined('MOODLE_INTERNAL') || die();

use moodle_exception;

/**
 * Grader class that interfaces with the commercial AI gateway.
 */
class grader {

    /**
     * Grade a piece of text via gateway.
     *
     * @param string $question
     * @param string $studenttext
     * @param string|null $rubricjson
     * @param string $quality fast|balanced|best
     * @return array
     * @throws moodle_exception
     */
    public function grade_text(string $question,
                               string $studenttext,
                               ?string $rubricjson = null,
                               string $quality = 'balanced'): array {

        if (!gateway_client::is_ready()) {
            throw new moodle_exception('aiclientnotready', 'local_hlai_grading');
        }

        $payload = [
            'question' => $question,
            'submission' => $studenttext,
            'rubric_json' => $rubricjson,
        ];
        $response = gateway_client::grade('grade_text', $payload, $quality);

        $raw = $response['content'] ?? null;
        if (is_string($raw)) {
            $data = json_decode($raw, true);
        } else if (is_array($raw)) {
            $data = $raw;
        } else {
            $data = null;
        }
        if (empty($data)) {
            throw new moodle_exception('invalidaigrade', 'local_hlai_grading',
                '', null, 'Gateway returned empty/invalid JSON');
        }

        return $data;
    }
}
