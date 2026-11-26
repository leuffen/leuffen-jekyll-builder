<?php

declare(strict_types=1);

namespace Leuffen\JekyllBuilder\Client\Exception;

use RuntimeException;

final class JekyllBuilderBuildException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $httpStatus,
        public readonly int $exitCode = 0,
        public readonly ?string $sourceFile = null,
        public readonly ?int $sourceLine = null,
        public readonly ?string $cause = null,
        public readonly ?string $excerpt = null,
        public readonly string $stdout = '',
        public readonly string $stderr = '',
        public readonly ?string $errorType = null,
        public readonly ?int $sourceColumn = null,
    ) {
        parent::__construct($message, $exitCode);
    }
}
