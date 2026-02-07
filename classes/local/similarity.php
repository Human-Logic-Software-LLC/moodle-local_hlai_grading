<?php
namespace local_hlai_grading\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Similarity scoring for key-based grading.
 */
class similarity {
    private const COVERAGE_WEIGHT = 0.7;
    private const JACCARD_WEIGHT = 0.3;
    private const MIN_TOKEN_LENGTH = 3;

    /**
     * Compute similarity between key text and student response.
     *
     * Uses AI semantic scoring when available; falls back to term overlap.
     *
     * @param string $key
     * @param string $student
     * @return array
     */
    public static function analyze(string $key, string $student): array {
        $semantic = self::analyze_semantic($key, $student);
        if (!empty($semantic)) {
            return $semantic;
        }

        return self::analyze_overlap($key, $student);
    }

    /**
     * Semantic similarity using gateway.
     */
    private static function analyze_semantic(string $key, string $student): array {
        if (!gateway_client::is_ready()) {
            return [];
        }

        try {
            $response = gateway_client::grade('semantic_similarity', [
                'answer_key' => $key,
                'student_answer' => $student,
            ], 'balanced');
            $content = $response['content'] ?? '';
            if (is_array($content)) {
                $content = json_encode($content);
            }
        } catch (\Throwable $e) {
            return [];
        }

        $decoded = self::decode_json_response($content);
        if (!$decoded) {
            return [];
        }

        $matched = self::normalize_list($decoded['matched_concepts'] ?? []);
        $partial = self::normalize_list($decoded['partially_matched_concepts'] ?? $decoded['partial_concepts'] ?? []);
        $missing = self::normalize_list($decoded['missing_concepts'] ?? []);
        $reasoning = trim((string)($decoded['reasoning'] ?? ''));
        if ($reasoning === '') {
            $reasoning = 'Similarity based on alignment of meaning and reasoning between the key answer and the student response.';
        }

        $totalconcepts = count($matched) + count($partial) + count($missing);
        $keycount = max($totalconcepts, 1);
        if ($totalconcepts > 0) {
            $points = count($matched) + (0.5 * count($partial));
            $similarity = ($points / $totalconcepts) * 100;
            $reasoning .= "\nSimilarity: (" . count($matched) . " full + " . count($partial) . " partial x 0.5) / " .
                $totalconcepts . " = " . number_format($similarity, 2) . "%.";
        } else {
            $similarity = isset($decoded['similarity_percent']) ? (float)$decoded['similarity_percent'] : 0.0;
        }
        $similarity = max(0.0, min(100.0, $similarity));

        $analysis = [
            'method' => 'semantic',
            'key_terms_count' => $keycount,
            'student_terms_count' => null,
            'matched_terms_count' => count($matched),
            'partial_terms_count' => count($partial),
            'union_terms_count' => null,
            'matched_terms' => $matched,
            'partial_terms' => $partial,
            'missing_terms' => $missing,
            'coverage_percent' => null,
            'jaccard_percent' => null,
            'final_percent' => round($similarity, 2),
            'similarity_breakdown' => [
                'full' => count($matched),
                'partial' => count($partial),
                'missing' => count($missing),
                'total' => $keycount,
            ],
            'weights' => null,
            'reasoning' => $reasoning,
        ];

        return $analysis;
    }

    /**
     * Term overlap similarity (fallback).
     */
    private static function analyze_overlap(string $key, string $student): array {
        $keyclean = self::normalize($key);
        $studentclean = self::normalize($student);

        $keytokens = self::tokenize($keyclean);
        $studenttokens = self::tokenize($studentclean);

        $keyset = array_values(array_unique($keytokens));
        $studentset = array_values(array_unique($studenttokens));
        sort($keyset);
        sort($studentset);

        $matched = array_values(array_intersect($keyset, $studentset));
        $missing = array_values(array_diff($keyset, $studentset));

        $keycount = count($keyset);
        $studentcount = count($studentset);
        $matchcount = count($matched);
        $unioncount = $keycount + $studentcount - $matchcount;

        $coverage = $keycount > 0 ? ($matchcount / $keycount) * 100 : 0.0;
        $jaccard = $unioncount > 0 ? ($matchcount / $unioncount) * 100 : 0.0;
        $final = round(($coverage * self::COVERAGE_WEIGHT) + ($jaccard * self::JACCARD_WEIGHT), 2);

        $analysis = [
            'method' => 'overlap',
            'key_terms_count' => $keycount,
            'student_terms_count' => $studentcount,
            'matched_terms_count' => $matchcount,
            'union_terms_count' => $unioncount,
            'matched_terms' => $matched,
            'missing_terms' => $missing,
            'coverage_percent' => round($coverage, 2),
            'jaccard_percent' => round($jaccard, 2),
            'final_percent' => $final,
            'weights' => [
                'coverage' => self::COVERAGE_WEIGHT,
                'jaccard' => self::JACCARD_WEIGHT,
            ],
        ];

        $analysis['reasoning'] = self::build_reasoning($analysis);

        return $analysis;
    }

    /**
     * Format term lists for UI display.
     *
     * @param array $terms
     * @param string $prefix
     * @param int $limit
     * @return array
     */
    public static function format_term_list(array $terms, string $prefix, int $limit = 12): array {
        if (empty($terms)) {
            return [];
        }

        $terms = array_slice($terms, 0, $limit);
        $out = [];
        foreach ($terms as $term) {
            $out[] = trim($prefix . ' ' . $term);
        }

        return $out;
    }

    /**
     * Build a human-readable similarity explanation.
     *
     * @param array $analysis
     * @return string
     */
    private static function build_reasoning(array $analysis): string {
        if (($analysis['method'] ?? '') === 'semantic') {
            return $analysis['reasoning'] ?? '';
        }

        $coverage = number_format((float)$analysis['coverage_percent'], 2);
        $jaccard = number_format((float)$analysis['jaccard_percent'], 2);
        $final = number_format((float)$analysis['final_percent'], 2);
        $weightcoverage = (int)round(self::COVERAGE_WEIGHT * 100);
        $weightjaccard = (int)round(self::JACCARD_WEIGHT * 100);

        $lines = [];
        $lines[] = "Key terms matched: {$analysis['matched_terms_count']} of {$analysis['key_terms_count']} ({$coverage}%).";
        $lines[] = "Overall term overlap (Jaccard): {$jaccard}% (matched {$analysis['matched_terms_count']} of {$analysis['union_terms_count']} unique terms).";
        $lines[] = "Final similarity = ({$weightcoverage}% x {$coverage}%) + ({$weightjaccard}% x {$jaccard}%) = {$final}%.";
        $lines[] = "Short words under " . self::MIN_TOKEN_LENGTH . " characters are ignored.";

        return implode("\n", $lines);
    }

    /**
     * Normalize text to plain lower-case tokens.
     */
    private static function normalize(string $text): string {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = strip_tags($text);
        $text = strtolower($text);
        $text = preg_replace('/[^a-z0-9]+/i', ' ', $text);
        $text = preg_replace('/\s+/', ' ', $text);
        return trim($text);
    }

    /**
     * Tokenize normalized text.
     */
    private static function tokenize(string $text): array {
        if ($text === '') {
            return [];
        }
        $tokens = preg_split('/\s+/', $text);
        $out = [];
        foreach ($tokens as $token) {
            if (strlen($token) < self::MIN_TOKEN_LENGTH) {
                continue;
            }
            $out[] = $token;
        }
        return $out;
    }

    /**
     * Decode JSON response content.
     */
    private static function decode_json_response(string $content): ?array {
        $content = trim($content);
        if ($content === '') {
            return null;
        }
        $content = preg_replace('/^```json\\s*/i', '', $content);
        $content = preg_replace('/^```\\s*/', '', $content);
        $content = preg_replace('/\\s*```$/', '', $content);
        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            $start = strpos($content, '{');
            $end = strrpos($content, '}');
            if ($start !== false && $end !== false && $end > $start) {
                $snippet = substr($content, $start, $end - $start + 1);
                $decoded = json_decode($snippet, true);
            }
        }
        if (!is_array($decoded)) {
            return null;
        }
        return $decoded;
    }

    /**
     * Normalize a list of strings.
     */
    private static function normalize_list($items): array {
        if (!is_array($items)) {
            return [];
        }
        $output = [];
        foreach ($items as $item) {
            $value = trim((string)$item);
            if ($value !== '') {
                $output[] = $value;
            }
        }
        return $output;
    }

}
