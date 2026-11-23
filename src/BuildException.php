<?php

declare(strict_types=1);

namespace Leuffen\JekyllBuilder;

use RuntimeException;

final class BuildException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $httpStatus = 400,
        public readonly int $exitCode = 0,
        public readonly string $stdout = '',
        public readonly string $stderr = '',
    ) {
        parent::__construct($message);
    }
}
