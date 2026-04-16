<?php
declare(strict_types=1);

namespace MonkeysLegion\Files\Upload;

use MonkeysLegion\Files\Entity\FileRecord;

/**
 * MonkeysLegion Framework — Files Package
 *
 * Immutable result of an upload operation.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final class UploadResult
{
    /** Computed failure flag via get hook. */
    public bool $failed {
        get => !$this->success;
    }

    /**
     * @param bool             $success Whether the upload succeeded
     * @param FileRecord|null  $file    The stored file record (null on failure)
     * @param list<string>     $errors  Validation/processing errors
     */
    public function __construct(
        public readonly bool $success,
        public readonly ?FileRecord $file = null,
        public readonly array $errors = [],
    ) {}

    public static function ok(FileRecord $file): self
    {
        return new self(success: true, file: $file);
    }

    /**
     * @param list<string> $errors
     */
    public static function fail(array $errors): self
    {
        return new self(success: false, errors: $errors);
    }
}
