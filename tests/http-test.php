<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

$directory = $argv[1] ?? null;
if ($directory === null) {
    throw new RuntimeException('Usage: php tests/http-test.php <zip-directory>');
}

$url = getenv('JEKYLL_BUILDER_URL') ?: 'http://127.0.0.1:8080/build';

$send = static function (string $archive) use ($url): \Phore\HttpClient\PhoreHttpResponse {
    $body = file_get_contents($archive);
    if ($body === false) {
        throw new RuntimeException('Cannot read archive: ' . $archive);
    }

    return phore_http_request($url)
        ->withBody($body)
        ->withHeader('Content-Type', 'application/zip')
        ->withTimeout(5, 180)
        ->send(false);
};

$success = $send($directory . '/site.zip');
if ($success->getHttpStatus() !== 200 || $success->getContentType() !== 'application/zip') {
    throw new RuntimeException('Expected HTTP 200 ZIP: ' . $success->getBody());
}

$zipPath = $directory . '/output.zip';
file_put_contents($zipPath, $success->getBody());
$zip = new ZipArchive();
if ($zip->open($zipPath) !== true) {
    throw new RuntimeException('Expected a valid output ZIP');
}
try {
    $html = $zip->getFromName('index.html');
    if ($html === false || !str_contains($html, 'ZIP Builder smoke')) {
        throw new RuntimeException('Generated index.html is missing or incorrect');
    }
} finally {
    $zip->close();
}

$failure = $send($directory . '/broken.zip');
if ($failure->getHttpStatus() !== 422 || $failure->getContentType() !== 'application/json') {
    throw new RuntimeException('Expected HTTP 422 JSON: ' . $failure->getBody());
}

$error = json_decode($failure->getBody(), true, 512, JSON_THROW_ON_ERROR);
if ($error['file'] !== 'index.html' || $error['line'] !== 3
    || !str_contains((string) $error['excerpt'], 'definitely_not_a_liquid_tag')
    || !str_contains((string) $error['cause'], 'Unknown tag')) {
    throw new RuntimeException('Missing structured Jekyll diagnostic: ' . $failure->getBody());
}

$invalid = $send($directory . '/invalid.zip');
if ($invalid->getHttpStatus() !== 400 || $invalid->getContentType() !== 'application/json') {
    throw new RuntimeException('Expected HTTP 400 JSON: ' . $invalid->getBody());
}

$invalidError = json_decode($invalid->getBody(), true, 512, JSON_THROW_ON_ERROR);
if (!str_contains((string) $invalidError['error'], 'Invalid ZIP') || $invalidError['file'] !== null) {
    throw new RuntimeException('Unexpected invalid ZIP response: ' . $invalid->getBody());
}

echo "HTTP ZIP upload, build response and structured error cases passed\n";
