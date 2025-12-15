<?php

declare(strict_types=1);

namespace MonkeysLegion\Files\Exception;

/**
 * Invalid file extension.
 */
class ExtensionException extends UploadException
{
    public function __construct(
        string $extension,
        array $allowed,
        ?\Throwable $previous = null
    ) {
        parent::__construct(
            message: sprintf(
                "Extension '%s' not allowed. Allowed extensions: %s",
                $extension,
                implode(', ', $allowed)
            ),
            previous: $previous,
            context: ['extension' => $extension, 'allowed' => $allowed]
        );
    }
}
