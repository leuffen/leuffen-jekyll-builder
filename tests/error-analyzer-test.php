<?php

declare(strict_types=1);

require __DIR__ . '/../container/src/JekyllErrorAnalyzer.php';

use Leuffen\JekyllBuilder\JekyllErrorAnalyzer;

$analyzer = new JekyllErrorAnalyzer();
$root = realpath(__DIR__ . '/fixtures/site');
if ($root === false) {
    throw new RuntimeException('Source fixture directory is missing');
}

$fallback = $analyzer->analyze('Jekyll process failed without a source path', '', $root);
if ($fallback['file'] !== null || $fallback['line'] !== null || $fallback['excerpt'] !== null) {
    throw new RuntimeException('Ambiguous errors must not be attributed to a file');
}

$named = $analyzer->analyze('Unexpected syntax error in index.html:3', '', $root);
if ($named['file'] !== 'index.html' || $named['line'] !== 3) {
    throw new RuntimeException('Expected ZIP-relative filename and line number');
}

echo "Jekyll error analysis passed\n";
