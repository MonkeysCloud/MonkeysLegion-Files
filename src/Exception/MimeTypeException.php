<?php

declare(strict_types=1);

namespace MonkeysLegion\Files\Exception;

/**
 * MIME type not allowed.
 */
class MimeTypeException extends UploadException
{
    public function __construct(
        string $mimeType,
        array $allowed,
        ?\Throwable $previous = null
    ) {
        parent::__construct(
            message: sprintf(
                "MIME type '%s' not allowed. Allowed types: %s",
                $mimeType,
                implode(', ', $allowed)
            ),
            previous: $previous,
            context: ['mime_type' => $mimeType, 'allowed' => $allowed]
        );
    }
}
