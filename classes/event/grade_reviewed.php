<?php
namespace local_hlai_grading\event;

defined('MOODLE_INTERNAL') || die();

class grade_reviewed extends \core\event\base {

    protected function init(): void {
        $this->data['crud'] = 'r';
        $this->data['edulevel'] = self::LEVEL_TEACHING;
        $this->data['objecttable'] = 'hlai_grading_results';
    }

    public static function get_name(): string {
        return get_string('event_grade_reviewed', 'local_hlai_grading');
    }

    public function get_description(): string {
        $action = $this->other['action'] ?? 'reviewed';
        return "AI grade {$this->objectid} was {$action} by a teacher.";
    }

    public function get_url(): \moodle_url {
        return new \moodle_url('/local/hlai_grading/release.php', ['id' => $this->objectid]);
    }
}
