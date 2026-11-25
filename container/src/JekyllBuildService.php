<?php

declare(strict_types=1);

namespace Leuffen\JekyllBuilder;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;
use ZipArchive;

require_once __DIR__ . '/BuildException.php';
require_once __DIR__ . '/JekyllErrorAnalyzer.php';

final class JekyllBuildService
{
    private const MAX_UPLOAD = 20 * 1024 * 1024;
    private const MAX_UNPACKED = 100 * 1024 * 1024;
    private const MAX_ENTRIES = 10000;
    private const MAX_OUTPUT = 100 * 1024 * 1024;
    private const BUILD_TIMEOUT = 120;
    private const DEBUG_INFO_MAX_BYTES = 3072;

    /** @var array<string, int> */
    private array $timingInfo = [];
    /** @var list<string> */
    private array $warnings = [];
    private bool $warningsTruncated = false;

    /**
     * Returns the bounded HTTP debug metadata for the current build.
     *
     * Process durations are in milliseconds; warnings contain individual
     * Jekyll warning lines. A truncated flag signals omitted diagnostics.
     * @return array{timingInfo: object, warnings: list<string>, truncated: bool}
     * @example $debug = $service->getDebugInfo();
     * @see self::buildFromRequest()
     */
    public function getDebugInfo(): array
    {
        return [
            'timingInfo' => (object) $this->timingInfo,
            'warnings' => $this->warnings,
            'truncated' => $this->warningsTruncated,
        ];
    }

    /**
     * Builds an uploaded Jekyll project and returns a temporary ZIP path.
     *
     * The HTTP request body must be a raw ZIP archive with a Jekyll project
     * at its root. The caller must delete the returned archive after sending.
     *
     * @return string Absolute path of the generated ZIP archive.
     * @throws BuildException On invalid uploads or failed Jekyll builds.
     * @example $archive = (new JekyllBuildService())->buildFromRequest();
     * @see ../README.md
     */
    public function buildFromRequest(): string
    {
        $this->timingInfo = [];
        $this->warnings = [];
        $this->warningsTruncated = false;

        // Den angeforderten Kompressionsgrad vor dem Build validieren.
        $requestedLevel = $_GET['compressionLevel'] ?? '2';
        if (!is_string($requestedLevel) || preg_match('/\A[0-9]\z/', $requestedLevel) !== 1) {
            throw new BuildException('ZIP compression level must be between 0 and 9', 400);
        }
        $compressionLevel = (int) $requestedLevel;
        $method = $compressionLevel === 0 ? ZipArchive::CM_STORE : ZipArchive::CM_DEFLATE;

        $root = sys_get_temp_dir() . '/jekyll-builder-' . bin2hex(random_bytes(16));
        if (!mkdir($root, 0700) && !is_dir($root)) {
            throw new BuildException('Cannot create temporary build directory', 500);
        }

        $source = $root . '/source';
        $target = $root . '/site';
        $input = $root . '/input.zip';
        $output = $root . '/site.zip';

        try {
            mkdir($source, 0700);
            mkdir($target, 0700);

            // Stream the request body with an explicit upper bound.
            if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > self::MAX_UPLOAD) {
                throw new BuildException('ZIP upload too large (20 MiB maximum)', 413);
            }

            $stream = fopen('php://input', 'rb');
            $destination = fopen($input, 'wb');
            if ($stream === false || $destination === false) {
                throw new BuildException('Cannot read uploaded ZIP', 500);
            }

            $size = 0;
            while (!feof($stream)) {
                $part = fread($stream, 65536);
                if ($part === false) {
                    throw new BuildException('Cannot read uploaded ZIP');
                }
                $size += strlen($part);
                if ($size > self::MAX_UPLOAD) {
                    throw new BuildException('ZIP upload too large (20 MiB maximum)', 413);
                }
                if (fwrite($destination, $part) !== strlen($part)) {
                    throw new BuildException('Cannot write uploaded ZIP', 500);
                }
            }
            fclose($stream);
            fclose($destination);

            $zip = new ZipArchive();
            if ($zip->open($input) !== true) {
                throw new BuildException('Invalid ZIP archive');
            }

            try {
                if ($zip->numFiles === 0 || $zip->numFiles > self::MAX_ENTRIES) {
                    throw new BuildException('ZIP has an invalid number of entries');
                }

                $unpacked = 0;
                $names = [];
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $entry = $zip->statIndex($i);
                    $name = $entry['name'];
                    $parts = explode('/', rtrim($name, '/'));
                    if ($name === '' || str_starts_with($name, '/')
                        || str_contains($name, '\\') || preg_match('/[\x00-\x1f\x7f]/', $name)
                        || in_array('', $parts, true) || in_array('.', $parts, true)
                        || in_array('..', $parts, true) || isset($names[$name])) {
                        throw new BuildException('Unsafe ZIP entry path');
                    }
                    $names[$name] = true;

                    $zip->getExternalAttributesIndex($i, $opsys, $attributes);
                    $mode = ($attributes >> 16) & 0170000;
                    if (!in_array($mode, [0, 0040000, 0100000], true)
                        || ($mode === 0040000 && !str_ends_with($name, '/'))) {
                        throw new BuildException('ZIP contains an unsupported file type or symlink');
                    }

                    $unpacked += (int) $entry['size'];
                    if ($unpacked > self::MAX_UNPACKED) {
                        throw new BuildException('Unpacked ZIP too large (100 MiB maximum)', 413);
                    }
                }

                if (!$zip->extractTo($source)) {
                    throw new BuildException('Cannot extract ZIP archive');
                }
            } finally {
                $zip->close();
            }

            // Execute Jekyll with fixed arguments, an isolated workspace and
            // a hard deadline; redirect output to files to avoid pipe blocking.
            $stdoutFile = $root . '/stdout.log';
            $stderrFile = $root . '/stderr.log';
            $command = [
                'timeout', '--signal=TERM', '--kill-after=5s', self::BUILD_TIMEOUT . 's',
                'jekyll', 'build', '--source', $source, '--destination', $target,
            ];
            $pipes = [];
            $processStarted = hrtime(true);
            $process = proc_open($command, [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', $stdoutFile, 'w'],
                2 => ['file', $stderrFile, 'w'],
            ], $pipes, $root, ['PATH' => getenv('PATH') ?: '/usr/bin:/bin',
                'HOME' => $root, 'JEKYLL_NO_BUNDLER_REQUIRE' => 'true',
                'JEKYLL_ENV' => getenv('JEKYLL_ENV') ?: 'production']);
            if (!is_resource($process)) {
                throw new BuildException('Cannot start Jekyll', 500);
            }
            $exitCode = proc_close($process);
            $this->timingInfo['jekyll'] = (int) ((hrtime(true) - $processStarted) / 1000000);
            $stdout = substr((string) @file_get_contents($stdoutFile), 0, 65536);
            $stderr = substr((string) @file_get_contents($stderrFile), 0, 65536);
            $this->collectWarnings($stdout, $source);
            $this->collectWarnings($stderr, $source);
            if ($exitCode !== 0) {
                $diagnostic = (new JekyllErrorAnalyzer())->analyze($stderr, $stdout, $source);
                throw new BuildException(
                    $exitCode === 124 ? 'Jekyll build timed out' : 'Jekyll build failed',
                    422,
                    $exitCode,
                    $stdout,
                    $stderr,
                    $diagnostic['file'],
                    $diagnostic['line'],
                    $diagnostic['excerpt'],
                    $diagnostic['cause'],
                );
            }

            $archive = new ZipArchive();
            if ($archive->open($output, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new BuildException('Cannot create output ZIP', 500);
            }
            try {
                $count = 0;
                $bytes = 0;
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($target, FilesystemIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::SELF_FIRST,
                );
                foreach ($iterator as $item) {
                    if ($item->isLink()) {
                        throw new BuildException('Build output contains a symlink', 422);
                    }

                    $name = substr($item->getPathname(), strlen($target) + 1);
                    $name = str_replace(DIRECTORY_SEPARATOR, '/', $name);
                    if ($item->isDir()) {
                        $archive->addEmptyDir($name);
                        continue;
                    }

                    $bytes += $item->getSize();
                    ++$count;
                    if ($bytes > self::MAX_OUTPUT || $count > self::MAX_ENTRIES) {
                        throw new BuildException('Build output exceeds limits', 422);
                    }
                    if (!$archive->addFile($item->getPathname(), $name)) {
                        throw new BuildException('Cannot archive build output', 500);
                    }
                    if (!$archive->setCompressionName($name, $method, $compressionLevel)) {
                        throw new BuildException('Cannot set ZIP compression for build output: ' . $name, 500);
                    }
                }
            } finally {
                $archive->close();
            }

            if (!is_file($output)) {
                throw new BuildException('Output ZIP was not created', 500);
            }

            // Move the result outside the workspace before cleaning up.
            $result = tempnam(sys_get_temp_dir(), 'jekyll-result-');
            if ($result === false || !rename($output, $result)) {
                throw new BuildException('Cannot preserve build output', 500);
            }

            return $result;
        } finally {
            $this->deleteDirectory($root);
        }
    }

    private function collectWarnings(string $output, string $source): void
    {
        if (strlen($output) >= 65536) {
            $this->warningsTruncated = true;
        }

        // Nur explizite Warnzeilen übernehmen, keine vollständigen Prozesslogs.
        foreach (preg_split('/\R/', $output) as $line) {
            if (!preg_match('/^\s*(?:(?:[a-z -]+ )?warning|warn|deprecation)\s*:/i', $line)) {
                continue;
            }
            $warning = trim(str_replace($source . '/', '', $line));
            $candidate = $this->getDebugInfo();
            $candidate['warnings'][] = $warning;
            $candidate['truncated'] = false;

            // Das vollständige serialisierte JSON begrenzen, nicht einzelne Zeichen.
            if (strlen(json_encode($candidate, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE)) > self::DEBUG_INFO_MAX_BYTES) {
                $this->warningsTruncated = true;
                continue;
            }
            $this->warnings[] = $warning;
        }
    }

    private function deleteDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            if ($item->isDir() && !$item->isLink()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($directory);
    }
}
