<?php
namespace local_hlai_grading\event;

defined('MOODLE_INTERNAL') || die();

class grade_released extends \core\event\base {

    protected function init(): void {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
        $this->data['objecttable'] = 'hlai_grading_results';
    }

    public static function get_name(): string {
        return get_string('event_grade_released', 'local_hlai_grading');
    }

    public function get_description(): string {
        return "AI grade {$this->objectid} was released to user {$this->relateduserid}.";
    }

    public function get_url(): \moodle_url {
        return new \moodle_url('/local/hlai_grading/release.php', ['id' => $this->objectid]);
    }
}
