<?php
namespace local_hlai_grading\event;

defined('MOODLE_INTERNAL') || die();

class submission_queued extends \core\event\base {

    protected function init(): void {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_TEACHING;
        $this->data['objecttable'] = 'hlai_grading_queue';
    }

    public static function get_name(): string {
        return get_string('event_submission_queued', 'local_hlai_grading');
    }

    public function get_description(): string {
        return "AI grading queue item {$this->objectid} was created for user {$this->relateduserid}.";
    }

    public function get_url(): \moodle_url {
        return new \moodle_url('/local/hlai_grading/error_details.php', ['id' => $this->objectid]);
    }
}
