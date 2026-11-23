<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/JekyllBuildService.php';

use Leuffen\JekyllBuilder\JekyllBuildService;
use Leuffen\JekyllBuilder\BuildException;

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) !== '/build') {
    header('Content-Type: application/json; charset=utf-8');
    header('Allow: POST');
    http_response_code(404);
    echo json_encode(['error' => 'Not found; use POST /build'], JSON_THROW_ON_ERROR);
    exit;
}

try {
    $service = new JekyllBuildService();
    $archive = $service->buildFromRequest();
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="site.zip"');
    header('Content-Length: ' . filesize($archive));
    readfile($archive);
    @unlink($archive);
} catch (BuildException $e) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($e->httpStatus);
    echo json_encode([
        'error' => $e->getMessage(),
        'exitCode' => $e->exitCode,
        'stdout' => $e->stdout,
        'stderr' => $e->stderr,
    ], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    error_log('Jekyll Builder internal error: ' . $e->getMessage());
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode(['error' => 'Internal build error'], JSON_THROW_ON_ERROR);
}
