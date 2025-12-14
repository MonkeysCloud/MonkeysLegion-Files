<?php

declare(strict_types=1);

namespace MonkeysLegion\Files\Exception;

/**
 * Virus scan failures or detections.
 */
class VirusScanException extends FilesException
{
    public function __construct(
        string $message,
        public readonly bool $threatDetected = false,
        public readonly ?string $threatName = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct(
            message: $message,
            previous: $previous,
            context: array_filter([
                'threat_detected' => $threatDetected,
                'threat_name' => $threatName,
            ])
        );
    }
}
