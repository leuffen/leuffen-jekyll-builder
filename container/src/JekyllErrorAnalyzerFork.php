<?php

declare(strict_types=1);

namespace Leuffen\JekyllBuilder;

require_once __DIR__ . '/JekyllErrorAnalyzer.php';

final class JekyllErrorAnalyzerFork extends JekyllErrorAnalyzer
{
    /**
     * Prefers native Ruby exception metadata, using the original log analyzer
     * when no structured diagnostic was produced (e.g. timeout or abort).
     *
     * @param string $stderr Captured Jekyll stderr.
     * @param string $stdout Captured Jekyll stdout.
     * @param string $sourceRoot Absolute extracted project directory.
     * @return array{file:?string,line:?int,excerpt:?string,cause:?string,errorType:?string,column:?int}
     * @see JekyllErrorAnalyzer::analyze()
     * @example $details = (new JekyllErrorAnalyzerFork())->analyze($stderr, $stdout, $sourceRoot);
     */
    public function analyze(string $stderr, string $stdout, string $sourceRoot): array
    {
        $native = json_decode((string) @file_get_contents(dirname($sourceRoot) . '/diagnostic.json', false, null, 0, 65536), true);
        if (!is_array($native) || !is_string($native['cause'] ?? null)) {
            return parent::analyze($stderr, $stdout, $sourceRoot) + ['errorType' => null, 'column' => null];
        }

        $diagnostic = [
            'file' => null,
            'line' => null,
            'excerpt' => null,
            'cause' => $native['cause'],
            'errorType' => is_string($native['errorType'] ?? null) ? $native['errorType'] : null,
            'column' => null,
        ];

        // Native paths are still validated against the extracted ZIP root.
        $root = realpath($sourceRoot);
        $file = $native['file'] ?? null;
        if ($root === false || !is_string($file) || $file === '' || str_starts_with($file, '/')
            || in_array('..', explode('/', $file), true) || str_contains($file, "\0")) {
            return $diagnostic;
        }
        $path = realpath($root . '/' . $file);
        if ($path === false || !str_starts_with($path, $root . '/') || !is_file($path)) {
            return $diagnostic;
        }

        $diagnostic['file'] = substr($path, strlen($root) + 1);
        $line = $native['line'] ?? null;
        if (is_int($line) && $line > 0) {
            $diagnostic['line'] = $line;
            $column = $native['column'] ?? null;
            $diagnostic['column'] = is_int($column) && $column > 0 ? $column : null;
        } elseif (preg_match('/Unknown tag [\x27"]([A-Za-z0-9_-]+)[\x27"]/i', $native['cause'])) {
            // Liquid versions without a line number still allow a unique tag lookup.
            $diagnostic['line'] = $this->findLine($native['cause'], $path, $diagnostic['file']);
        }
        if ($diagnostic['line'] !== null) {
            $diagnostic['excerpt'] = $this->sourceExcerpt($path, $diagnostic['line']);
        }

        return $diagnostic;
    }
}
