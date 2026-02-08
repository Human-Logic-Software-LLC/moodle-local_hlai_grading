<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Grading started event class.
 *
 * @package    local_hlai_grading
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_hlai_grading\event;

/**
 * Event triggered when AI grading starts processing.
 */
class grading_started extends \core\event\base {
    /**
     * Initialize event data.
     *
     * @return void
     */
    protected function init(): void {
        $this->data['crud'] = 'r';
        $this->data['edulevel'] = self::LEVEL_TEACHING;
        $this->data['objecttable'] = 'local_hlai_grading_queue';
    }

    /**
     * Get the event name.
     *
     * @return string The event name.
     */
    public static function get_name(): string {
        return get_string('event_grading_started', 'local_hlai_grading');
    }

    /**
     * Get the event description.
     *
     * @return string The event description.
     */
    public function get_description(): string {
        return "AI grading started for queue item {$this->objectid}.";
    }

    /**
     * Get the event URL.
     *
     * @return \moodle_url The event URL.
     */
    public function get_url(): \moodle_url {
        return new \moodle_url('/local/hlai_grading/error_details.php', ['id' => $this->objectid]);
    }
}
