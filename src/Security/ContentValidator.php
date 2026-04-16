<?php
declare(strict_types=1);

namespace MonkeysLegion\Files\Security;

use MonkeysLegion\Files\Exception\SecurityException;

/**
 * MonkeysLegion Framework — Files Package
 *
 * Validates file content MIME type matches the declared/extension MIME.
 * Prevents MIME spoofing attacks where a PHP file is uploaded as image/jpeg.
 *
 * **Unique to ML Files** — Laravel trusts the upload's declared MIME.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final readonly class ContentValidator
{
    /** @var list<string> MIME types that are always dangerous */
    private const array BLOCKED_MIMES = [
        'application/x-httpd-php',
        'application/x-php',
        'text/x-php',
        'application/x-executable',
        'application/x-sharedlib',
    ];

    /**
     * Validate that the actual file content matches the claimed MIME type.
     *
     * @param string $filePath     Path to the file on disk
     * @param string $claimedMime  The MIME type declared by the uploader
     *
     * @throws SecurityException If the content doesn't match or is blocked
     */
    public function validate(string $filePath, string $claimedMime): void
    {
        $finfo    = new \finfo(FILEINFO_MIME_TYPE);
        $detected = $finfo->file($filePath);

        if ($detected === false) {
            throw new SecurityException('Unable to detect file content type');
        }

        // Always block dangerous MIME types regardless of what was claimed
        if (in_array($detected, self::BLOCKED_MIMES, true)) {
            throw new SecurityException(
                "Blocked content type detected: {$detected}",
            );
        }

        // Compare the major type (image/*, video/*, application/*, etc.)
        $claimedMajor  = explode('/', $claimedMime, 2)[0] ?? '';
        $detectedMajor = explode('/', $detected, 2)[0] ?? '';

        if ($claimedMajor !== $detectedMajor && $claimedMajor !== 'application') {
            throw new SecurityException(
                "MIME mismatch: claimed '{$claimedMime}', detected '{$detected}'",
            );
        }
    }
}
