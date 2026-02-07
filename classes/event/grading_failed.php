<?php
namespace local_hlai_grading\event;

defined('MOODLE_INTERNAL') || die();

class grading_failed extends \core\event\base {

    protected function init(): void {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_TEACHING;
        $this->data['objecttable'] = 'hlai_grading_queue';
    }

    public static function get_name(): string {
        return get_string('event_grading_failed', 'local_hlai_grading');
    }

    public function get_description(): string {
        $reason = $this->other['message'] ?? 'Unknown';
        return "AI grading failed for queue item {$this->objectid}. Reason: {$reason}";
    }

    public function get_url(): \moodle_url {
        return new \moodle_url('/local/hlai_grading/error_details.php', ['id' => $this->objectid]);
    }
}
