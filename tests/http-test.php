<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Leuffen\JekyllBuilder\Client\LeuffenJekyllBuilderClient;
use Leuffen\JekyllBuilder\Client\Exception\JekyllBuilderBuildException;
use Leuffen\JekyllBuilder\Client\Exception\JekyllBuilderConnectionException;

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

$debugHeader = $success->getHeader('X-Debug-Info');
$debug = json_decode($debugHeader, true, 512, JSON_THROW_ON_ERROR);
if (strlen($debugHeader) > 3072 || !is_int($debug['timingInfo']['jekyll'] ?? null)
    || $debug['timingInfo']['jekyll'] < 0 || !is_array($debug['warnings'] ?? null)
    || !is_bool($debug['truncated'] ?? null)) {
    throw new RuntimeException('Invalid HTTP debug metadata');
}
$phases = ['zipExtract', 'zipCreate'];
$forkPhases = ['jekyllWorkerStartup', 'jekyllConfig', 'jekyllSetup', 'jekyllRead',
    'jekyllGenerate', 'jekyllRender', 'jekyllCleanup', 'jekyllWrite'];
$mode = getenv('JEKYLL_BUILD_MODE') ?: 'fork';
if ($mode === 'fork') {
    $phases = array_merge($phases, $forkPhases);
} else {
    foreach ($forkPhases as $phase) {
        if (isset($debug['timingInfo'][$phase])) {
            throw new RuntimeException('Command backend unexpectedly reported fork timing: ' . $phase);
        }
    }
}
foreach ($phases as $phase) {
    if (!is_int($debug['timingInfo'][$phase] ?? null) || $debug['timingInfo'][$phase] < 0) {
        throw new RuntimeException('Missing build timing: ' . $phase);
    }
}
echo 'First build timings (ms): ' . json_encode($debug['timingInfo'], JSON_THROW_ON_ERROR) . "\n";

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

$debug = json_decode($failure->getHeader('X-Debug-Info'), true, 512, JSON_THROW_ON_ERROR);
if (!is_int($debug['timingInfo']['jekyll'] ?? null) || $debug['timingInfo']['jekyll'] < 0) {
    throw new RuntimeException('Missing Jekyll timing in HTTP error response');
}

$error = json_decode($failure->getBody(), true, 512, JSON_THROW_ON_ERROR);
if ($error['file'] !== 'index.html' || $error['line'] !== 3
    || !str_contains((string) $error['excerpt'], 'definitely_not_a_liquid_tag')
    || !str_contains((string) $error['cause'], 'Unknown tag')) {
    throw new RuntimeException('Missing structured Jekyll diagnostic: ' . $failure->getBody());
}

if ($mode === 'fork' && ($error['errorType'] ?? null) !== 'Liquid::SyntaxError') {
    throw new RuntimeException('Missing native Liquid exception type');
}
if ($mode === 'command' && ($error['errorType'] ?? null) !== null) {
    throw new RuntimeException('Unexpected native error type in command mode');
}

$invalid = $send($directory . '/invalid.zip');
if ($invalid->getHttpStatus() !== 400 || $invalid->getContentType() !== 'application/json') {
    throw new RuntimeException('Expected HTTP 400 JSON: ' . $invalid->getBody());
}

$debug = json_decode($invalid->getHeader('X-Debug-Info'), true, 512, JSON_THROW_ON_ERROR);
if ($debug['timingInfo'] !== [] || $debug['warnings'] !== [] || $debug['truncated'] !== false) {
    throw new RuntimeException('Invalid ZIP must not report a process that did not run');
}

$invalidError = json_decode($invalid->getBody(), true, 512, JSON_THROW_ON_ERROR);
if (!str_contains((string) $invalidError['error'], 'Invalid ZIP') || $invalidError['file'] !== null) {
    throw new RuntimeException('Unexpected invalid ZIP response: ' . $invalid->getBody());
}

echo "HTTP ZIP upload, build response and structured error cases passed\n";


// The public client sends the project directory and extracts into target.
$client = new LeuffenJekyllBuilderClient('http://127.0.0.1:8080');
$target = $directory . '/published';
$client->build(__DIR__ . '/fixtures/site', $target);
$timing = $client->getTimingInfo();
if (!is_int($timing['jekyll'] ?? null) || $timing['jekyll'] < 0) {
    throw new RuntimeException('Client lost Jekyll process timing');
}
$html = file_get_contents($target . '/index.html');
if ($html === false || !str_contains($html, 'ZIP Builder smoke')) {
    throw new RuntimeException('Client did not extract generated website');
}

// Prüft die entpackten ZIP-Quellen vor dem Rendering und weist den Hook nach.
$gitProject = $directory . '/git-project';
mkdir($gitProject . '/.git', 0700, true);
mkdir($gitProject . '/nested');
mkdir($gitProject . '/_plugins');
copy(__DIR__ . '/fixtures/site/_config.yml', $gitProject . '/_config.yml');
copy(__DIR__ . '/fixtures/site/index.html', $gitProject . '/index.html');
file_put_contents($gitProject . '/index.html', "\n{{ site.git_metadata_verified }}\n", FILE_APPEND);
file_put_contents($gitProject . '/.git/config', 'private metadata');
symlink('config', $gitProject . '/.git/link');
file_put_contents($gitProject . '/nested/.git', 'gitdir: elsewhere');
file_put_contents($gitProject . '/.gitignore', 'ignored.txt');
file_put_contents($gitProject . '/_plugins/check_git.rb', <<<'RUBY'
Jekyll::Hooks.register :site, :post_read do |site|
  abort "Git metadata was uploaded" if File.exist?(File.join(site.source, ".git")) ||
    File.exist?(File.join(site.source, "nested", ".git"))
  abort "Other hidden files were excluded" unless File.file?(File.join(site.source, ".gitignore"))
  site.config["git_metadata_verified"] = "git-metadata-verified"
  warn "Warning: preview smoke warning"
  warn "Warning: #{'x' * 5000}"
end
RUBY);
$gitTarget = $directory . '/git-published';
try {
    $client->build($gitProject, $gitTarget);
} catch (JekyllBuilderBuildException $exception) {
    throw new RuntimeException('Git metadata regression build failed: ' . $exception->stderr, 0, $exception);
}
$debug = $client->getDebugInfo();
if (!in_array('Warning: preview smoke warning', $debug['warnings'], true)
    || $debug['truncated'] !== true || strlen(json_encode($debug, JSON_THROW_ON_ERROR)) > 3072) {
    throw new RuntimeException('Warnings were lost or not bounded');
}
$html = file_get_contents($gitTarget . '/index.html');
if ($html === false || !str_contains($html, 'git-metadata-verified')) {
    throw new RuntimeException('Expected successful build with verified Git metadata exclusion');
}

// Ein neuer Build darf weder Hooks noch Warnungen des vorherigen Projekts übernehmen.
$client->build(__DIR__ . '/fixtures/site', $directory . '/warm-published');
$warmDebug = $client->getDebugInfo();
if (in_array('Warning: preview smoke warning', $warmDebug['warnings'], true)) {
    throw new RuntimeException('Project plugin warnings leaked into a later build');
}
echo 'Warm build timings (ms): ' . json_encode($warmDebug['timingInfo'], JSON_THROW_ON_ERROR) . "\n";

try {
    $client->build(__DIR__ . '/fixtures/site', $target);
    throw new RuntimeException('Expected refusal of a nonempty target directory');
} catch (InvalidArgumentException $exception) {
    if (!str_contains($exception->getMessage(), 'must be empty')) {
        throw $exception;
    }
}

try {
    $client->build(__DIR__ . '/fixtures/broken', $directory . '/broken-output');
    throw new RuntimeException('Expected a typed Jekyll build error');
} catch (JekyllBuilderBuildException $exception) {
    $timing = $client->getTimingInfo();
    if (!is_int($timing['jekyll'] ?? null) || $timing['jekyll'] < 0) {
        throw new RuntimeException('Client lost timing on a failed build', 0, $exception);
    }
    if ($exception->sourceFile !== 'index.html'
        || $exception->sourceLine !== 3
        || !str_contains((string) $exception->cause, 'Unknown tag')
        || !str_contains((string) $exception->excerpt, 'definitely_not_a_liquid_tag')) {
        throw new RuntimeException('Client lost Jekyll build diagnostics', 0, $exception);
    }
}

try {
    $client->buildZip($directory . '/invalid.zip');
    throw new RuntimeException('Expected rejection of an invalid ZIP');
} catch (JekyllBuilderBuildException $exception) {
    if ($client->getDebugInfo() !== ['timingInfo' => [], 'warnings' => [], 'truncated' => false]) {
        throw new RuntimeException('Client retained stale debug metadata', 0, $exception);
    }
}

try {
    (new LeuffenJekyllBuilderClient('http://127.0.0.1:1'))
        ->build(__DIR__ . '/fixtures/site', $directory . '/unavailable-output');
    throw new RuntimeException('Expected an unavailable-service exception');
} catch (JekyllBuilderConnectionException $exception) {
    if (!str_contains($exception->getMessage(), 'not available')) {
        throw new RuntimeException('Unexpected connection exception', 0, $exception);
    }
}

if ($mode === 'fork') {
    // Ein Include muss statt der aufrufenden Seite als Fehlerquelle erscheinen.
    $includeProject = $directory . '/include-error';
    mkdir($includeProject . '/_includes', 0700, true);
    copy(__DIR__ . '/fixtures/site/_config.yml', $includeProject . '/_config.yml');
    file_put_contents($includeProject . '/index.html', "---\n---\n{% include bad.html %}\n");
    file_put_contents($includeProject . '/_includes/bad.html', "before\n{% unknown_include_tag %}\nafter\n");
    try {
        $client->build($includeProject, $directory . '/include-error-output');
        throw new RuntimeException('Expected include build failure');
    } catch (JekyllBuilderBuildException $exception) {
        if ($exception->errorType !== 'Liquid::SyntaxError'
            || $exception->sourceFile !== '_includes/bad.html'
            || $exception->sourceLine !== 2
            || !str_contains((string) $exception->cause, 'unknown_include_tag')
            || !str_contains((string) $exception->excerpt, '> 2 |')) {
            throw new RuntimeException('Incorrect native include diagnostic: ' . $exception->stderr, 0, $exception);
        }
    }

    // Ruby-Plugin-Fehler haben eine native Backtrace-Position.
    $pluginProject = $directory . '/plugin-error';
    mkdir($pluginProject . '/_plugins', 0700, true);
    copy(__DIR__ . '/fixtures/site/_config.yml', $pluginProject . '/_config.yml');
    copy(__DIR__ . '/fixtures/site/index.html', $pluginProject . '/index.html');
    file_put_contents($pluginProject . '/_plugins/bad.rb', "Jekyll::Hooks.register :site, :post_read do |site|\n  raise 'plugin diagnostic smoke'\nend\n");
    try {
        $client->build($pluginProject, $directory . '/plugin-error-output');
        throw new RuntimeException('Expected plugin build failure');
    } catch (JekyllBuilderBuildException $exception) {
        if ($exception->errorType !== 'RuntimeError'
            || $exception->sourceFile !== '_plugins/bad.rb'
            || $exception->sourceLine !== 2
            || $exception->cause !== 'plugin diagnostic smoke'
            || !str_contains((string) $exception->excerpt, '> 2 |')) {
            throw new RuntimeException('Incorrect native plugin diagnostic: ' . $exception->stderr, 0, $exception);
        }
    }
}

// Modern Sass and the explicitly enabled Polyglot plugin must work in both modes.
$localizedTarget = $directory . '/localized-published';
$client->build(__DIR__ . '/fixtures/polyglot', $localizedTarget);
foreach (['index.html' => 'language-en', 'de/index.html' => 'language-de'] as $file => $expected) {
    $html = file_get_contents($localizedTarget . '/' . $file);
    if ($html === false || !str_contains($html, $expected)) {
        throw new RuntimeException('Missing localized output: ' . $file);
    }
}
$css = file_get_contents($localizedTarget . '/assets/style.css');
if ($css === false || preg_match('/color:\s*#123456\b/i', $css) !== 1
    || !str_contains($css, '.sass-smoke') || str_contains($css, '@use')) {
    throw new RuntimeException('Dart Sass did not compile the SCSS module');
}

echo "Jekyll Builder PHP client cases passed\n";
