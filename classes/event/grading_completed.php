<?php
namespace local_hlai_grading\event;

defined('MOODLE_INTERNAL') || die();

class grading_completed extends \core\event\base {

    protected function init(): void {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_TEACHING;
        $this->data['objecttable'] = 'hlai_grading_results';
    }

    public static function get_name(): string {
        return get_string('event_grading_completed', 'local_hlai_grading');
    }

    public function get_description(): string {
        return "AI grading result {$this->objectid} was stored for user {$this->relateduserid}.";
    }

    public function get_url(): \moodle_url {
        return new \moodle_url('/local/hlai_grading/release.php', ['id' => $this->objectid]);
    }
}
