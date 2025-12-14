<?php

declare(strict_types=1);

namespace MonkeysLegion\Files\Exception;

/**
 * Rate limit exceeded.
 */
class RateLimitException extends FilesException
{
    public function __construct(
        string $message,
        public readonly int $retryAfter = 60,
        public readonly ?string $limitType = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct(
            message: $message,
            previous: $previous,
            context: [
                'retry_after' => $retryAfter,
                'limit_type' => $limitType,
            ]
        );
    }
}
