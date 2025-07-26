<?php

namespace MonkeysLegion\Files\Validation;

use Psr\Http\Message\UploadedFileInterface;
use MonkeysLegion\Files\Exceptions\UploadException;

/**
 * Validates uploaded files.
 *
 * @see \MonkeysLegion\Files\Validation\UploadRulesTest
 */
final class UploadRules
{
    /**
     * Validates the uploaded file against size and MIME type constraints.
     *
     * @param UploadedFileInterface $file The uploaded file to validate.
     * @param int                   $maxBytes Maximum allowed file size in bytes.
     * @param string[]              $mimeAllow Allowed MIME types.
     * @throws UploadException If validation fails.
     */
    public static function validate(UploadedFileInterface $file, int $maxBytes, array $mimeAllow): void
    {
        if ($file->getError() !== UPLOAD_ERR_OK) {
            throw new UploadException('Upload failed with code ' . $file->getError());
        }
        if ($file->getSize() > $maxBytes) {
            throw new UploadException('File too large.');
        }
        $mime = $file->getClientMediaType() ?: 'application/octet-stream';
        if ($mimeAllow && !in_array($mime, $mimeAllow, true)) {
            throw new UploadException('Disallowed MIME type.');
        }
    }
}
