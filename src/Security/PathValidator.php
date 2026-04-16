<?php
declare(strict_types=1);

namespace MonkeysLegion\Files\Security;

use MonkeysLegion\Files\Exception\SecurityException;

/**
 * MonkeysLegion Framework — Files Package
 *
 * Reusable path traversal prevention. Validates that a relative path
 * stays within a given base directory.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final readonly class PathValidator
{
    /**
     * Validate that a path is safe (no traversal).
     *
     * @param string $path     Relative path to validate
     * @param string $basePath Resolved absolute base path
     *
     * @return string The validated full path
     *
     * @throws SecurityException If traversal is detected
     */
    public function validate(string $path, string $basePath): string
    {
        $path = ltrim($path, '/');

        // Reject any path containing '..' segments
        if (preg_match('#(^|/)\.\.(/|$)#', $path)) {
            throw new SecurityException("Path traversal detected: {$path}");
        }

        // Reject null bytes
        if (str_contains($path, "\0")) {
            throw new SecurityException("Null byte in path: {$path}");
        }

        $full = rtrim($basePath, '/') . '/' . $path;
        $normalizedBase = realpath($basePath) ?: rtrim($basePath, '/');

        // Build prefix that correctly handles root (/) base path
        $basePrefix = rtrim($normalizedBase, '/');
        $basePrefix = ($basePrefix === '') ? '/' : $basePrefix . '/';

        // If parent exists, double-check via realpath
        $parent = realpath(dirname($full));

        if (
            $parent !== false
            && $parent !== $normalizedBase
            && !str_starts_with($parent, $basePrefix)
        ) {
            throw new SecurityException("Path traversal detected: {$path}");
        }

        return $full;
    }
}
