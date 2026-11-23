<?php

declare(strict_types=1);

namespace Leuffen\JekyllBuilder\Client;

use FilesystemIterator;
use InvalidArgumentException;
use Leuffen\JekyllBuilder\Client\Exception\JekyllBuilderBuildException;
use Leuffen\JekyllBuilder\Client\Exception\JekyllBuilderConnectionException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;
use ZipArchive;

require_once __DIR__ . '/Exception/JekyllBuilderBuildException.php';
require_once __DIR__ . '/Exception/JekyllBuilderConnectionException.php';

final class LeuffenJekyllBuilderClient
{
    public function __construct(private readonly string $baseUrl = 'http://jekyll-builder')
    {
    }

    /**
     * Packs a complete Jekyll project directory and builds it remotely.
     *
     * Hidden files and nested directories are included. Symlinks are rejected.
     * Returns the complete output ZIP as bytes; the caller decides where to
     * store or extract the generated site.
     *
     * @param string $directory Local Jekyll project root.
     * @return string Binary ZIP archive containing the built website.
     * @throws InvalidArgumentException If the directory is unreadable or contains symlinks.
     * @throws JekyllBuilderConnectionException If the service is unreachable or returns an invalid response.
     * @throws JekyllBuilderBuildException If the service rejects the project or Jekyll fails.
     * @see self::buildZip()
     *
     * Example:
     * $zip = (new LeuffenJekyllBuilderClient())->build('/projects/example');
     * file_put_contents('/tmp/site.zip', $zip);
     */
    public function build(string $directory): string
    {
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
                // Zip the directory contents, not the containing directory.
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::SELF_FIRST,
                );
                foreach ($iterator as $entry) {
                    if ($entry->isLink()) {
                        throw new InvalidArgumentException('Project contains a symbolic link: ' . $entry->getPathname());
                    }

                    $path = $entry->getPathname();
                    $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen($root) + 1));
                    if ($entry->isDir()) {
                        $zip->addEmptyDir($relative);
                    } elseif ($entry->isFile() && !$zip->addFile($path, $relative)) {
                        throw new RuntimeException('Cannot add project file to ZIP: ' . $path);
                    }
                }
            } finally {
                $zip->close();
            }

            return $this->buildZip($temporary);
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
        $body = @file_get_contents($zipFile);
        if ($body === false) {
            throw new InvalidArgumentException('Cannot read ZIP archive: ' . $zipFile);
        }

        try {
            $response = \phore_http_request(rtrim($this->baseUrl, '/') . '/build')
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
}
