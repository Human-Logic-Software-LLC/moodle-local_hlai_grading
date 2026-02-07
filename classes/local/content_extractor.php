<?php
namespace local_hlai_grading\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Content extraction helper for AI grading.
 *
 * Mirrors the requirements in AI_Grading_Technical_Spec §15.
 */
class content_extractor {
    /** @var bool */
    protected static $phpwordready = false;
    /** @var bool */
    protected static $phpwordchecked = false;

    /** @var string|null cached antiword path */
    protected static $antiwordpath = null;

    /**
     * Extract text + metadata for an assignment submission.
     *
     * @param \assign $assign
     * @param \stdClass $submission
     * @return array{text:string, files:array}
     */
    public static function extract_from_assignment(\assign $assign, \stdClass $submission): array {
        $textchunks = [];
        $filelabels = [];

        // 1) Online text plugin content.
        $onlineplugin = $assign->get_submission_plugin_by_type('onlinetext');
        if ($onlineplugin && $onlineplugin->is_enabled()) {
            $onlinetext = $onlineplugin->get_editor_text('onlinetext', $submission->id);
            if (!empty($onlinetext)) {
                $textchunks[] = self::clean_text(strip_tags($onlinetext));
            }
        }

        // 2) File submissions.
        $fileplugin = $assign->get_submission_plugin_by_type('file');
        $submissionuser = null;
        if ($fileplugin && $fileplugin->is_enabled()) {
            if (!empty($submission->userid)) {
                $submissionuser = \core_user::get_user($submission->userid, '*', MUST_EXIST);
            }
            $usercontext = $submissionuser ?? \core_user::get_noreply_user();
            $files = $fileplugin->get_files($submission, $usercontext);
            if (!empty($files)) {
                foreach ($files as $file) {
                    if ($file->is_directory()) {
                        continue;
                    }
                    $extracted = self::extract_file($file);
                    if (!empty($extracted['error'])) {
                        debugging('AI document extraction warning: ' . $file->get_filename() . ' -> ' . $extracted['error'], DEBUG_DEVELOPER);
                        $filelabels[] = $file->get_filename() . ' (error: ' . $extracted['error'] . ')';
                        continue;
                    }
                    if (!empty($extracted['text'])) {
                        $textchunks[] = $extracted['text'];
                        $filelabels[] = $file->get_filename();
                    }
                }
            }
        }

        $combined = self::clean_text(implode("\n\n", $textchunks));
        if ($combined === '' && !empty($filelabels)) {
            $combined = 'Student submitted the following files: ' . implode(', ', $filelabels) .
                '. The system could not automatically extract full text. Please review them manually.';
        }

        return [
            'text' => $combined,
            'files' => $filelabels,
        ];
    }

    /**
     * Extract readable text from a stored file (core method used by assignment/quiz wrappers).
     *
     * @param \stored_file $file
     * @return array{text:string,format:string,error?:string}
     */
    public static function extract_file(\stored_file $file): array {
        $extension = strtolower(pathinfo($file->get_filename(), PATHINFO_EXTENSION));
        $result = [
            'text' => '',
            'format' => $extension ?: 'unknown',
        ];

        try {
            switch ($extension) {
                case 'txt':
                case 'text':
                case 'md':
                case 'csv':
                case 'rtf':
                case 'odt':
                case 'docx':
                case 'docm':
                case 'doc':
                    $result['text'] = self::extract_with_phpword($file, $extension);
                    break;

                case 'pdf':
                    $result['text'] = self::extract_pdf($file);
                    break;

                default:
                    throw new \runtimeexception('Unsupported file type for AI extraction: ' . $extension);
            }
        } catch (\Throwable $e) {
            $result['error'] = $e->getMessage();
        }

        return $result;
    }

    protected static function extract_txt(\stored_file $file): string {
        $content = $file->get_content();
        if ($content === false) {
            throw new \runtimeexception('Unable to read text file.');
        }
        return self::clean_text($content);
    }

    protected static function extract_pdf(\stored_file $file): string {
        $binary = $file->get_content();
        if ($binary === false || $binary === '') {
            throw new \runtimeexception('PDF file is empty.');
        }

        $text = '';
        if (preg_match_all('/stream(.*?)endstream/s', $binary, $matches)) {
            foreach ($matches[1] as $stream) {
                $decoded = self::decode_pdf_stream($stream);
                $text .= self::extract_text_from_pdf_stream($decoded) . "\n";
            }
        }

        if (trim($text) === '') {
            // Fallback: strip objects.
            $text = strip_tags($binary);
        }

        if (trim($text) === '') {
            throw new \runtimeexception('Unable to extract text from PDF (possibly scanned).');
        }

        return self::clean_text($text);
    }

    protected static function extract_with_phpword(\stored_file $file, string $extension): string {
        if (!self::ensure_phpword_loaded()) {
            if ($extension === 'doc') {
                return self::extract_doc_legacy($file, false);
            }
            throw new \runtimeexception('PhpWord library is not available for document parsing.');
        }

        $suffix = '.' . $extension;
        $temppath = self::copy_to_temp($file, $suffix);
        try {
            $phpword = \PhpOffice\PhpWord\IOFactory::load($temppath);
        } catch (\Throwable $e) {
            @unlink($temppath);
            if ($extension === 'doc') {
                return self::extract_doc_legacy($file, false);
            }
            throw new \runtimeexception('PhpWord failed to load document: ' . $e->getMessage());
        }
        @unlink($temppath);

        ob_start();
        try {
            $writer = \PhpOffice\PhpWord\IOFactory::createWriter($phpword, 'HTML');
            $writer->save('php://output');
            $html = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            throw new \runtimeexception('PhpWord failed to render document: ' . $e->getMessage());
        }

        if (trim($html) === '') {
            throw new \runtimeexception('PhpWord returned empty content for document.');
        }

        return self::clean_text(strip_tags($html));
    }

    protected static function extract_doc_legacy(\stored_file $file, bool $throwonfailure = true): string {
        $antiword = self::get_antiword_path();
        if ($antiword) {
            $temppath = self::copy_to_temp($file, '.doc');
            $command = escapeshellcmd($antiword) . ' ' . escapeshellarg($temppath);
            $output = shell_exec($command);
            @unlink($temppath);
            if (!empty($output)) {
                return self::clean_text($output);
            }
        }

        // Heuristic fallback.
        $binary = $file->get_content();
        if ($binary === false) {
            throw new \runtimeexception('Unable to read legacy .doc file.');
        }
        $text = preg_replace("/[^\x09\x0A\x0D\x20-\x7E\xA0-\xFF]/", ' ', $binary);
        $text = self::clean_text($text);
        if ($text === '' && $throwonfailure) {
            throw new \runtimeexception('Legacy .doc extraction requires Antiword. Please upload as .docx or PDF.');
        }
        return $text;
    }

    protected static function decode_pdf_stream(string $stream): string {
        $stream = ltrim($stream);
        $decoded = @gzuncompress($stream);
        if ($decoded === false) {
            $decoded = @gzdecode($stream);
        }
        return ($decoded === false) ? $stream : $decoded;
    }

    protected static function extract_text_from_pdf_stream(string $stream): string {
        $text = '';
        if (preg_match_all('/\((.*?)\)\s*Tj/s', $stream, $matches)) {
            foreach ($matches[1] as $chunk) {
                $text .= self::unescape_pdf_text($chunk) . "\n";
            }
        }
        if (preg_match_all('/\[(.*?)\]\s*TJ/s', $stream, $arraymatches)) {
            foreach ($arraymatches[1] as $array) {
                if (preg_match_all('/\((.*?)\)/s', $array, $chunks)) {
                    foreach ($chunks[1] as $chunk) {
                        $text .= self::unescape_pdf_text($chunk);
                    }
                    $text .= "\n";
                }
            }
        }

        if ($text === '') {
            $text = strip_tags($stream);
        }

        return $text;
    }

    protected static function unescape_pdf_text(string $text): string {
        return preg_replace('/\\\\([nrtbf\\\\()])/', '$1', $text);
    }

    protected static function copy_to_temp(\stored_file $file, string $suffix): string {
        $tempdir = make_temp_directory('local_hlai_grading');
        $tempfile = tempnam($tempdir, 'hlg');
        $destination = $tempfile . $suffix;
        rename($tempfile, $destination);
        $file->copy_content_to($destination);
        return $destination;
    }

    protected static function clean_text(string $text): string {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/\r\n|\r|\n/", "\n", $text);
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace("/\n{3,}/", "\n\n", $text);
        return trim($text);
    }

    /**
     * Ensure PhpWord is available.
     */
    protected static function ensure_phpword_loaded(): bool {
        if (self::$phpwordchecked) {
            return self::$phpwordready;
        }
        global $CFG;
        if (!class_exists('\\PhpOffice\\PhpWord\\IOFactory')) {
            $autoload = $CFG->dirroot . '/vendor/autoload.php';
            if (file_exists($autoload)) {
                require_once($autoload);
            }
        }
        self::$phpwordchecked = true;
        return self::$phpwordready = class_exists('\\PhpOffice\\PhpWord\\IOFactory');
    }

    /**
     * Resolve Antiword path once.
     */
    protected static function get_antiword_path(): ?string {
        global $CFG;
        if (self::$antiwordpath !== null) {
            return self::$antiwordpath;
        }

        $configured = get_config('local_hlai_grading', 'antiwordpath') ?: '';
        if ($configured && is_executable($configured)) {
            return self::$antiwordpath = $configured;
        }

        // Common locations (Linux/Mac). Windows users can set config value.
        $candidates = ['/usr/bin/antiword', '/usr/local/bin/antiword'];
        foreach ($candidates as $candidate) {
            if (is_executable($candidate)) {
                return self::$antiwordpath = $candidate;
            }
        }

        return self::$antiwordpath = null;
    }
}


