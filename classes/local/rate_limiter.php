<?php
namespace local_hlai_grading\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Lightweight rate limiter that reuses the plugin's audit log.
 */
class rate_limiter {

    /**
     * Ensure an action has not exceeded the configured threshold.
     *
     * @param int $userid
     * @param string $action
     * @param int $limit
     * @param int $windowseconds
     */
    public static function enforce(int $userid, string $action, int $limit = 10, int $windowseconds = 3600): void {
        global $DB;

        if ($userid <= 0) {
            return;
        }

        $since = time() - $windowseconds;
        $params = [
            'userid' => $userid,
            'action' => $action,
            'since' => $since,
        ];
        $count = $DB->count_records_select(
            'hlai_grading_log',
            'userid = :userid AND action = :action AND timecreated >= :since',
            $params
        );

        if ($count >= $limit) {
            throw new \moodle_exception('ratelimitexceeded', 'local_hlai_grading');
        }
    }
}
