<?php
namespace local_hlai_grading\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Thin gateway client for commercial Human Logic AI routing.
 */
class gateway_client {
    /** @var string Fixed gateway endpoint (locked by design). */
    private const FIXED_GATEWAY_URL = 'https://ai.human-logic.com';

    /**
     * Return locked gateway base URL.
     *
     * @return string
     */
    public static function get_gateway_url(): string {
        $override = trim((string)getenv('HL_GATEWAY_URL'));
        if ($override !== '') {
            return $override;
        }
        $configured = trim((string)get_config('local_hlai_grading', 'gatewayurl'));
        if ($configured !== '') {
            return $configured;
        }
        return self::FIXED_GATEWAY_URL;
    }

    /**
     * Return configured gateway key.
     *
     * @return string
     */
    public static function get_gateway_key(): string {
        return trim((string)get_config('local_hlai_grading', 'gatewaykey'));
    }

    /**
     * Whether the gateway client is configured for processing.
     *
     * @return bool
     */
    public static function is_ready(): bool {
        return self::get_gateway_key() !== '';
    }

    /**
     * Send a grading operation payload to the gateway.
     *
     * @param string $operation
     * @param array $payload
     * @param string $quality
     * @return array{provider:string,content:mixed}
     * @throws \moodle_exception
     */
    public static function grade(string $operation, array $payload, string $quality = 'balanced'): array {
        global $CFG;

        if (!self::is_ready()) {
            throw new \moodle_exception('aiclientnotready', 'local_hlai_grading');
        }

        $request = [
            'operation' => $operation,
            'quality' => $quality,
            'payload' => $payload,
            'plugin' => 'local_hlai_grading',
        ];

        require_once($CFG->libdir . '/filelib.php');

        $url = rtrim(self::get_gateway_url(), '/') . '/grade';
        $key = self::get_gateway_key();
        $curl = new \curl();
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $key,
            'X-HL-Plugin: local_hlai_grading',
        ];

        try {
            $curl->setHeader($headers);
            $response = $curl->post($url, json_encode($request));
        } catch (\Throwable $e) {
            throw new \moodle_exception('aiclientnotready', 'local_hlai_grading', '', null, 'Gateway request failed');
        }

        $decoded = json_decode((string)$response, true);
        if (!is_array($decoded)) {
            throw new \moodle_exception('aiclientnotready', 'local_hlai_grading', '', null, 'Gateway response was not valid JSON');
        }

        if (!empty($decoded['error'])) {
            throw new \moodle_exception('aiclientnotready', 'local_hlai_grading', '', null, 'Gateway rejected request');
        }

        $content = $decoded['content'] ?? $decoded['result'] ?? $decoded;
        $provider = trim((string)($decoded['provider'] ?? 'gateway'));
        if ($provider === '') {
            $provider = 'gateway';
        }

        return [
            'provider' => $provider,
            'content' => $content,
        ];
    }
}
