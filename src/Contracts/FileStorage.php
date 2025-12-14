<?php

declare(strict_types=1);

namespace MonkeysLegion\Files\Contracts;

/**
 * Alias for StorageInterface.
 * 
 * Maintained for backward compatibility with v1.x.
 * 
 * @deprecated Use StorageInterface instead
 */
interface FileStorage extends StorageInterface
{
    // All methods inherited from StorageInterface
}
