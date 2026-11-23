<?php

declare(strict_types=1);

namespace Leuffen\JekyllBuilder;

final class JekyllErrorAnalyzer
{
    /**
     * Analyzes Jekyll diagnostic output and identifies a source file in the ZIP.
     *
     * Only existing regular files beneath the extracted project root are
     * considered. If attribution is uncertain, file and location remain null.
     * Original stdout and stderr are passed through separately to the client.
     *
     * @param string $stderr Raw stderr output from Jekyll.
     * @param string $stdout Raw stdout output from Jekyll.
     * @param string $sourceRoot Absolute extracted ZIP root.
     * @return array{file:?string,line:?int,excerpt:?string,cause:?string}
     * @see BuildException
     *
     * Example:
     * $details = (new JekyllErrorAnalyzer())->analyze($stderr, $stdout, $source);
     */
    public function analyze(string $stderr, string $stdout, string $sourceRoot): array
    {
        $diagnostic = ['file' => null, 'line' => null, 'excerpt' => null, 'cause' => null];
        $message = trim($stderr) !== '' ? $stderr : $stdout;
        $messages = preg_split('/\R/', $message) ?: [];

        // Extract the most useful error line without exposing an invented file.
        foreach ($messages as $line) {
            if (preg_match('/(?:Liquid Exception|Liquid Syntax Error|YAML Exception|YAML syntax error|Error:|Exception:|Syntax Error)/i', $line)) {
                $diagnostic['cause'] = trim($line);
                break;
            }
        }

        if ($diagnostic['cause'] === null) {
            foreach ($messages as $line) {
                if (trim($line) !== '') {
                    $diagnostic['cause'] = trim($line);
                    break;
                }
            }
        }

        $root = realpath($sourceRoot);
        if ($root === false) {
            return $diagnostic;
        }

        // Jekyll emits both absolute source paths and "in filename" messages.
        // Only those explicit references are considered; stack traces from
        // Ruby gems must never be reported as an uploaded project file.
        $candidates = [];
        foreach ($messages as $line) {
            if (preg_match_all('~' . preg_quote($root, '~') . '/([^\s:(),]+)~', $line, $matches)) {
                array_push($candidates, ...$matches[1]);
            }
            if (preg_match_all('~\bin\s+["\x27]?([A-Za-z0-9_./-]+\.[A-Za-z0-9_-]+)(?=["\x27\s:,)]|$)~i', $line, $matches)) {
                array_push($candidates, ...$matches[1]);
            }
            if (preg_match_all('~(?<![A-Za-z0-9_./-])([A-Za-z0-9_./-]+\.[A-Za-z0-9_-]+):[0-9]+(?=[:\s]|$)~', $line, $matches)) {
                array_push($candidates, ...$matches[1]);
            }
        }

        foreach (array_unique($candidates) as $candidate) {
            $candidate = preg_replace('~^\./~', '', $candidate);
            if ($candidate === '' || str_starts_with($candidate, '/') || in_array('..', explode('/', $candidate), true)) {
                continue;
            }

            $path = realpath($root . '/' . $candidate);
            if ($path === false || !str_starts_with($path, $root . '/') || !is_file($path) || is_link($path)) {
                continue;
            }

            $diagnostic['file'] = substr($path, strlen($root) + 1);
            $diagnostic['line'] = $this->findLine($message, $path, $diagnostic['file']);
            if ($diagnostic['line'] !== null) {
                $diagnostic['excerpt'] = $this->sourceExcerpt($path, $diagnostic['line']);
            }
            break;
        }

        return $diagnostic;
    }

    private function findLine(string $message, string $sourceFile, string $relativeFile): ?int
    {
        foreach ([$relativeFile, basename($relativeFile)] as $candidate) {
            if (preg_match('~(?:^|[\s(])' . preg_quote($candidate, '~') . ':(\d+)(?=[:\s)]|$)~m', $message, $matches)) {
                return (int) $matches[1];
            }
        }

        if (preg_match('/\b(?:on\s+)?line\s+(\d+)\b/i', $message, $matches)) {
            return (int) $matches[1];
        }

        // If Jekyll names an unknown Liquid tag but not a line, locate its
        // unique occurrence in the source. Multiple matches are ambiguous.
        if (preg_match('/Unknown tag [\x27"]([A-Za-z0-9_-]+)[\x27"]/i', $message, $matches)) {
            $contents = @file($sourceFile);
            if ($contents === false) {
                return null;
            }

            $found = null;
            foreach ($contents as $index => $line) {
                if (preg_match('/\{%-?\s*' . preg_quote($matches[1], '/') . '\b/', $line)) {
                    if ($found !== null) {
                        return null;
                    }
                    $found = $index + 1;
                }
            }
            return $found;
        }

        return null;
    }

    private function sourceExcerpt(string $sourceFile, int $line): ?string
    {
        if ($line < 1 || filesize($sourceFile) > 1024 * 1024) {
            return null;
        }

        $lines = @file($sourceFile);
        if ($lines === false || !isset($lines[$line - 1])) {
            return null;
        }

        $excerpt = [];
        for ($number = max(1, $line - 2); $number <= min(count($lines), $line + 2); $number++) {
            $excerpt[] = sprintf('%s %d | %s', $number === $line ? '>' : ' ', $number, rtrim($lines[$number - 1], "\r\n"));
        }
        return substr(implode("\n", $excerpt), 0, 2048);
    }
}
