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
        public readonly ?string $file = null,
        public readonly ?int $line = null,
        public readonly ?string $excerpt = null,
        public readonly ?string $cause = null,
    ) {
        parent::__construct($message);
    }
}
