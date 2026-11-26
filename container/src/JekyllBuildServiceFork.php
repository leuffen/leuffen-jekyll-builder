<?php

declare(strict_types=1);

namespace Leuffen\JekyllBuilder;

require_once __DIR__ . '/JekyllBuildService.php';
require_once __DIR__ . '/JekyllErrorAnalyzerFork.php';

final class JekyllBuildServiceFork extends JekyllBuildService
{
    protected function errorAnalyzer(): JekyllErrorAnalyzer
    {
        return new JekyllErrorAnalyzerFork();
    }

    protected function runBuild(string $root): int
    {
        try {
            return $this->runPreloadedBuild($root);
        } finally {
            $phaseTimings = json_decode((string) @file_get_contents($root . '/timing.json'), true);
            foreach (['jekyllWorkerStartup', 'jekyllConfig', 'jekyllSetup', 'jekyllRead',
                'jekyllGenerate', 'jekyllRender', 'jekyllCleanup', 'jekyllWrite'] as $phase) {
                if (is_int($phaseTimings[$phase] ?? null) && $phaseTimings[$phase] >= 0) {
                    $this->timingInfo[$phase] = $phaseTimings[$phase];
                }
            }
        }
    }

    private function runPreloadedBuild(string $root): int
    {
        $socket = @stream_socket_client('unix:///run/jekyll-builder/build.sock', $errno, $error, 5);
        if ($socket === false) {
            throw new BuildException('Cannot connect to Jekyll preloader: ' . $error, 500);
        }

        try {
            // Der Ruby-Supervisor beendet den Child spätestens nach 125 Sekunden.
            stream_set_timeout($socket, self::BUILD_TIMEOUT + 10);
            $request = $root . "\n";
            if (fwrite($socket, $request) !== strlen($request)) {
                throw new BuildException('Cannot send build to Jekyll preloader', 500);
            }

            $response = fgets($socket, 4096);
            $result = json_decode($response === false ? '' : $response, true);
            if (!is_int($result['exitCode'] ?? null)) {
                throw new BuildException(
                    'Jekyll preloader failed: ' . ($result['error'] ?? 'No valid response'),
                    500,
                );
            }

            return $result['exitCode'];
        } finally {
            fclose($socket);
        }
    }

}
