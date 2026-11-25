<?php

declare(strict_types=1);

namespace Leuffen\JekyllBuilder\Client;

use FilesystemIterator;
use InvalidArgumentException;
use Leuffen\JekyllBuilder\Client\Exception\JekyllBuilderBuildException;
use Leuffen\JekyllBuilder\Client\Exception\JekyllBuilderConnectionException;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;
use ZipArchive;

require_once __DIR__ . '/Exception/JekyllBuilderBuildException.php';
require_once __DIR__ . '/Exception/JekyllBuilderConnectionException.php';

final class LeuffenJekyllBuilderClient
{
    private int $compressionLevel = 2;
    private array $debugInfo = ['timingInfo' => [], 'warnings' => [], 'truncated' => false];

    public function __construct(private readonly string $baseUrl = 'http://jekyll-builder')
    {
    }

    /**
     * Returns process durations from the most recent build attempt.
     *
     * Values are milliseconds, indexed by process name (e.g. jekyll).
     * Timing remains available after a remote build exception and is reset
     * before each new attempt. An empty array means no timing was received.
     * @return array<string, int> Process name to duration in milliseconds.
     * @example $milliseconds = $client->getTimingInfo()['jekyll'] ?? null;
     * @see self::getDebugInfo()
     */
    public function getTimingInfo(): array
    {
        return $this->debugInfo['timingInfo'];
    }

    /**
     * Returns debug metadata from the latest build response, including failures.
     *
     * The data contains process durations, warning lines and a truncation flag.
     * Missing or invalid metadata does not change the build result. Each new
     * attempt resets the data; do not interpret absent timing as zero duration.
     * @return array{timingInfo: array<string, int>, warnings: list<string>, truncated: bool}
     * @example $warnings = $client->getDebugInfo()['warnings'];
     * @see self::buildZip()
     */
    public function getDebugInfo(): array
    {
        return $this->debugInfo;
    }

    /**
     * Sets the ZIP compression level for subsequent builds, in both directions.
     *
     * Level 0 stores files without compression; levels 1–9 use DEFLATE.
     * The default is 2. Existing input archives passed to buildZip() are
     * not recompressed; the setting still controls the returned ZIP.
     *
     * @param int $level Compression level from 0 to 9.
     * @return void
     * @throws InvalidArgumentException If the level is outside 0–9.
     * @see self::build()
     * @see self::buildZip()
     * @example $client->setCompressionLevel(1);
     */
    public function setCompressionLevel(int $level): void
    {
        if ($level < 0 || $level > 9) {
            throw new InvalidArgumentException('ZIP compression level must be between 0 and 9');
        }

        $this->compressionLevel = $level;
    }

    /**
     * Packs a complete Jekyll project directory and builds it remotely.
     *
     * Entries named .git are excluded at every depth, including worktree files.
     * Other hidden files and nested directories are included. Symlinks are rejected.
     * Extracts the returned website ZIP into a new or empty target directory.
     * Existing files in the target are never overwritten.
     *
     * @param string $directory Local Jekyll project root.
     * @param string $targetDirectory New or empty directory for generated files.
     * @return void The generated website is extracted to the target directory.
     * @throws InvalidArgumentException If the directory is unreadable or contains symlinks.
     * @throws JekyllBuilderConnectionException If the service is unreachable or returns an invalid response.
     * @throws JekyllBuilderBuildException If the service rejects the project or Jekyll fails.
     * @see self::buildZip()
     *
     * Example:
     * (new LeuffenJekyllBuilderClient())->build('/projects/example', '/sites/example');
     */
    public function build(string $directory, string $targetDirectory): void
    {
        $this->debugInfo = ['timingInfo' => [], 'warnings' => [], 'truncated' => false];
        $root = realpath($directory);
        if ($root === false || !is_dir($root) || !is_readable($root)) {
            throw new InvalidArgumentException('Cannot read project directory: ' . $directory);
        }

        $temporary = tempnam(sys_get_temp_dir(), 'jekyll-upload-');
        if ($temporary === false) {
            throw new RuntimeException('Cannot create a temporary ZIP archive');
        }

        try {
            $zip = new ZipArchive();
            if ($zip->open($temporary, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Cannot open temporary ZIP archive: ' . $temporary);
            }

            try {
                // Git-Metadaten vor der Rekursion ausschließen; übrige Inhalte packen.
                $filtered = new RecursiveCallbackFilterIterator(
                    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                    static fn (\SplFileInfo $entry): bool => $entry->getFilename() !== '.git',
                );
                $iterator = new RecursiveIteratorIterator(
                    $filtered,
                    RecursiveIteratorIterator::SELF_FIRST,
                );
                $method = $this->compressionLevel === 0 ? ZipArchive::CM_STORE : ZipArchive::CM_DEFLATE;
                foreach ($iterator as $entry) {
                    if ($entry->isLink()) {
                        throw new InvalidArgumentException('Project contains a symbolic link: ' . $entry->getPathname());
                    }

                    $path = $entry->getPathname();
                    $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen($root) + 1));
                    if ($entry->isDir()) {
                        $zip->addEmptyDir($relative);
                    } elseif ($entry->isFile()) {
                        if (!$zip->addFile($path, $relative)) {
                            throw new RuntimeException('Cannot add project file to ZIP: ' . $path);
                        }
                        if (!$zip->setCompressionName($relative, $method, $this->compressionLevel)) {
                            throw new RuntimeException('Cannot set ZIP compression for project file: ' . $path);
                        }
                    }
                }
            } finally {
                $zip->close();
            }

            $this->extractBuiltZip($this->buildZip($temporary), $targetDirectory);
        } finally {
            @unlink($temporary);
        }
    }

    /**
     * Uploads an existing project ZIP and returns the built website ZIP bytes.
     *
     * The ZIP must contain the Jekyll project at its root; not an enclosing
     * directory. A JSON error response is mapped to a typed build exception.
     *
     * @param string $zipFile Path to the existing project ZIP archive.
     * @return string Raw ZIP bytes on success.
     * @throws InvalidArgumentException If the local ZIP file cannot be read.
     * @throws JekyllBuilderConnectionException On network or unexpected HTTP/protocol responses.
     * @throws JekyllBuilderBuildException On an HTTP error with JSON build details.
     * @see self::build()
     *
     * Example:
     * $zip = (new LeuffenJekyllBuilderClient('http://jekyll-builder'))->buildZip('project.zip');
     */
    public function buildZip(string $zipFile): string
    {
        $this->debugInfo = ['timingInfo' => [], 'warnings' => [], 'truncated' => false];
        $body = @file_get_contents($zipFile);
        if ($body === false) {
            throw new InvalidArgumentException('Cannot read ZIP archive: ' . $zipFile);
        }

        try {
            $response = \phore_http_request(rtrim($this->baseUrl, '/') . '/build?compressionLevel=' . $this->compressionLevel)
                ->withBody($body)
                ->withHeader('Content-Type', 'application/zip')
                ->withTimeout(5, 180)
                ->send(false);
        } catch (Throwable $error) {
            throw new JekyllBuilderConnectionException(
                'Jekyll Builder is not available at ' . $this->baseUrl,
                0,
                $error,
            );
        }

        // Debug-Daten auch bei HTTP-Fehlern vor dem Auslösen einer Exception lesen.
        $this->readDebugInfo($response->getHeader('X-Debug-Info', '{}'));
        $status = $response->getHttpStatus();
        if ($status === 200) {
            if ($response->getContentType() !== 'application/zip') {
                throw new JekyllBuilderConnectionException('Unexpected response type from Jekyll Builder');
            }
            return $response->getBody();
        }

        $error = json_decode($response->getBody(), true);
        if (!is_array($error) || !is_string($error['error'] ?? null)) {
            throw new JekyllBuilderConnectionException(
                'Jekyll Builder returned HTTP ' . $status . ' without a valid error response',
            );
        }

        throw new JekyllBuilderBuildException(
            $error['error'],
            $status,
            (int) ($error['exitCode'] ?? 0),
            is_string($error['file'] ?? null) ? $error['file'] : null,
            is_int($error['line'] ?? null) ? $error['line'] : null,
            is_string($error['cause'] ?? null) ? $error['cause'] : null,
            is_string($error['excerpt'] ?? null) ? $error['excerpt'] : null,
            (string) ($error['stdout'] ?? ''),
            (string) ($error['stderr'] ?? ''),
        );
    }
    private function readDebugInfo(string $header): void
    {
        $info = json_decode($header, true);
        if (!is_array($info)) {
            return;
        }
        if (is_array($info['timingInfo'] ?? null)) {
            foreach ($info['timingInfo'] as $process => $milliseconds) {
                if (is_string($process) && is_int($milliseconds) && $milliseconds >= 0) {
                    $this->debugInfo['timingInfo'][$process] = $milliseconds;
                }
            }
        }
        if (is_array($info['warnings'] ?? null)) {
            foreach ($info['warnings'] as $warning) {
                if (is_string($warning)) {
                    $this->debugInfo['warnings'][] = $warning;
                }
            }
        }
        $this->debugInfo['truncated'] = ($info['truncated'] ?? false) === true;
    }

    /**
     * Extracts a build result without following ZIP symlinks or parent paths.
     *
     * To avoid partially published websites the archive is extracted to a
     * separate staging directory and then moved into its final destination.
     * The target must be absent or empty.
     *
     * @throws InvalidArgumentException If the target is invalid or not empty.
     * @throws RuntimeException If the output ZIP cannot be safely extracted.
     * @see self::build()
     */
    private function extractBuiltZip(string $bytes, string $targetDirectory): void
    {
        $targetDirectory = rtrim($targetDirectory, DIRECTORY_SEPARATOR);
        if ($targetDirectory === '') {
            throw new InvalidArgumentException('Target directory must not be empty');
        }

        if (file_exists($targetDirectory) || is_link($targetDirectory)) {
            if (!is_dir($targetDirectory) || is_link($targetDirectory)) {
                throw new InvalidArgumentException('Target must be a directory: ' . $targetDirectory);
            }
            $iterator = new FilesystemIterator($targetDirectory, FilesystemIterator::SKIP_DOTS);
            if ($iterator->valid()) {
                throw new InvalidArgumentException('Target directory must be empty: ' . $targetDirectory);
            }
        }

        $parent = realpath(dirname($targetDirectory));
        if ($parent === false || !is_dir($parent) || !is_writable($parent)) {
            throw new InvalidArgumentException('Target parent directory is not writable: ' . dirname($targetDirectory));
        }

        $target = $parent . '/' . basename($targetDirectory);
        $stage = $parent . '/.jekyll-build-' . bin2hex(random_bytes(12));
        $archivePath = tempnam(sys_get_temp_dir(), 'jekyll-result-');
        if ($archivePath === false) {
            throw new RuntimeException('Cannot create temporary result ZIP');
        }

        try {
            if (file_put_contents($archivePath, $bytes) !== strlen($bytes)) {
                throw new RuntimeException('Cannot write temporary result ZIP: ' . $archivePath);
            }

            $zip = new ZipArchive();
            if ($zip->open($archivePath) !== true) {
                throw new RuntimeException('Builder returned an invalid result ZIP');
            }

            try {
                if (!mkdir($stage, 0700)) {
                    throw new RuntimeException('Cannot create staging directory: ' . $stage);
                }
                $names = [];
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $stat = $zip->statIndex($i);
                    if ($stat === false) {
                        throw new RuntimeException('Cannot inspect ZIP entry');
                    }
                    $name = $stat['name'];
                    $parts = explode('/', rtrim($name, '/'));
                    if ($name === '' || str_starts_with($name, '/')
                        || str_contains($name, '\\') || preg_match('/[\x00-\x1f\x7f]/', $name)
                        || in_array('', $parts, true) || in_array('.', $parts, true)
                        || in_array('..', $parts, true) || isset($names[$name])) {
                        throw new RuntimeException('Unsafe build result ZIP entry: ' . $name);
                    }
                    $names[$name] = true;

                    if (!$zip->getExternalAttributesIndex($i, $opsys, $attributes)) {
                        throw new RuntimeException('Cannot inspect result ZIP permissions');
                    }
                    $type = ($attributes >> 16) & 0170000;
                    if (!in_array($type, [0, 0040000, 0100000], true)) {
                        throw new RuntimeException('Result ZIP contains a symlink or unsupported file: ' . $name);
                    }
                }

                if (!$zip->extractTo($stage)) {
                    throw new RuntimeException('Cannot extract builder result ZIP');
                }
            } finally {
                $zip->close();
            }

            // An existing empty target is removed only after extraction has
            // succeeded, then staging is renamed on the same filesystem.
            $wasEmpty = is_dir($target);
            if ($wasEmpty && !rmdir($target)) {
                throw new RuntimeException('Cannot replace empty target directory: ' . $target);
            }
            if (!rename($stage, $target)) {
                if ($wasEmpty) {
                    @mkdir($target, 0700);
                }
                throw new RuntimeException('Cannot publish generated website to: ' . $target);
            }
        } finally {
            @unlink($archivePath);
            if (is_dir($stage)) {
                $this->removeStagingDirectory($stage);
            }
        }
    }

    private function removeStagingDirectory(string $directory): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            if ($entry->isDir() && !$entry->isLink()) {
                @rmdir($entry->getPathname());
            } else {
                @unlink($entry->getPathname());
            }
        }
        @rmdir($directory);
    }

}
