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
 * CLI script to generate answer keys.
 *
 * @package    local_hlai_grading
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../config.php');

/**
 * Normalize text by stripping HTML tags, decoding entities, and collapsing whitespace.
 *
 * @param string $text The raw text to normalize.
 * @return string The cleaned plain-text string.
 */
function normalize_text_local(string $text): string {
    $plain = trim(strip_tags($text));
    if ($plain === '') {
        return '';
    }
    $plain = html_entity_decode($plain, ENT_QUOTES | ENT_HTML5);
    $plain = preg_replace('/\s+/u', ' ', $plain);
    return trim($plain);
}

/**
 * Generate an answer key using the AI provider.
 *
 * @param string $prompt The prompt to send to the AI provider.
 * @return string|null The generated key text, or null if no provider is available.
 */
function ai_generate_key(string $prompt): ?string {
    if (class_exists('\\\\local_hlai_hub\\\\api')) {
        if (\local_hlai_hub\api::is_ready()) {
            $response = \local_hlai_hub\api::generate('local_hlai_grading', 'quiz_essay_key', $prompt, [
                'processing_mode' => 'balanced', 'max_tokens' => 500,
            ]);
            return $response->content ?? '';
        }
    }

    if (class_exists('\\\\local_hlai_hubproxy\\\\api')) {
        $response = \local_hlai_hubproxy\api::generate([
            'prompt' => $prompt,
            'context' => ['operation' => 'quiz_essay_key'],
            'processing_mode' => 'balanced',
            'max_tokens' => 500,
            'metadata' => ['plugin' => 'local_hlai_grading', 'operation' => 'quiz_essay_key'],
        ]);
        return $response->content ?? '';
    }

    return null;
}

global $DB;

$provider = 'none';
if (class_exists('\\\\local_hlai_hub\\\\api') && \local_hlai_hub\api::is_ready()) {
    $provider = 'hlai_hub';
} else if (class_exists('\\\\local_hlai_hubproxy\\\\api')) {
    try {
        \local_hlai_hubproxy\api::health_check();
        $provider = 'hlai_hubproxy';
    } catch (Throwable $e) {
        $provider = 'none';
    }
}

if ($provider === 'none') {
    echo "No AI provider available (hlai_hub or hlai_hubproxy).\n";
    exit(1);
}

echo "AI provider: {$provider}\n";

$missing = $DB->get_records_sql(
    "SELECT qo.id, qo.questionid, qo.graderinfo, qo.graderinfoformat,
            q.name, q.questiontext, q.questiontextformat
       FROM {qtype_essay_options} qo
       JOIN {question} q ON q.id = qo.questionid
      WHERE (qo.graderinfo IS NULL OR qo.graderinfo = '')"
);

echo "Essay questions missing keys: " . count($missing) . "\n";

$generated = 0;
$failed = 0;

foreach ($missing as $essay) {
    $context = \context_system::instance();
    $qname = format_string($essay->name ?? 'Essay question');
    $qtext = format_text(
        (string)($essay->questiontext ?? ''),
        (int)($essay->questiontextformat ?? FORMAT_HTML),
        ['context' => $context]
    );
    $qtext = normalize_text_local($qtext);
    if ($qtext === '') {
        $qtext = $qname;
    }

    $prompt = "You are an instructor. Write a concise model answer key for the essay question below.\n";
    $prompt .= "Capture the key points a high-scoring response should include.\n";
    $prompt .= "Return plain text with bullet points if helpful. Keep it under 200 words.\n\n";
    $prompt .= "Question: {$qname}\n";
    $prompt .= "Question text: {$qtext}\n";

    try {
        $content = ai_generate_key($prompt);
    } catch (Throwable $e) {
        echo "Failed to generate key for question {$essay->questionid}: {$e->getMessage()}\n";
        $failed++;
        continue;
    }

    $content = trim((string)$content);
    $tick = chr(96);
    $content = preg_replace('/^' . $tick . '{3}[a-z]*\s*/i', '', $content);
    $content = preg_replace('/\s*' . $tick . '{3}$/', '', $content);
    $content = trim($content);

    if ($content === '' || $content === null) {
        echo "Empty key for question {$essay->questionid}, skipped.\n";
        $failed++;
        continue;
    }

    $update = (object)[
        'id' => $essay->id, 'graderinfo' => $content, 'graderinfoformat' => FORMAT_HTML,
    ];
    $DB->update_record('qtype_essay_options', $update);
    $generated++;
}

echo "Generated keys: {$generated}\n";
echo "Failed keys: {$failed}\n";

$pending = $DB->get_records('local_hlai_grading_queue', ['status' => 'pending', 'component' => 'mod_quiz']);
$updated = 0;

foreach ($pending as $item) {
    $payload = json_decode($item->payload ?? '', true);
    if (!$payload || ($payload['modulename'] ?? '') !== 'quiz') {
        continue;
    }
    if (!empty($payload['graderkey'])) {
        continue;
    }

    $questionid = $payload['questionid'] ?? null;
    if (!$questionid) {
        continue;
    }

    $options = $DB->get_record('qtype_essay_options', ['questionid' => $questionid], 'graderinfo,graderinfoformat', IGNORE_MISSING);
    if (!$options || trim((string)$options->graderinfo) === '') {
        continue;
    }

    $context = \context_system::instance();
    $formatted = format_text(
        (string)$options->graderinfo,
        (int)($options->graderinfoformat ?? FORMAT_HTML),
        ['context' => $context]
    );
    $graderkey = normalize_text_local($formatted);
    if ($graderkey === '') {
        continue;
    }

    $payload['graderkey'] = $graderkey;
    $item->payload = json_encode($payload);
    $DB->update_record('local_hlai_grading_queue', $item);
    $updated++;
}

echo "Updated pending queue items with generated keys: {$updated}\n";
