<?php

declare(strict_types=1);

namespace MonkeysLegion\Files\Security;

use MonkeysLegion\Files\Exception\VirusScanException;

/**
 * Interface for virus scanners.
 */
interface VirusScannerInterface
{
    /**
     * Scan a file for viruses.
     *
     * @param string $path Path to the file to scan
     * @return ScanResult The scan result
     * @throws VirusScanException If scanning fails
     */
    public function scan(string $path): ScanResult;

    /**
     * Scan file contents from a stream.
     *
     * @param resource $stream Stream to scan
     * @return ScanResult The scan result
     * @throws VirusScanException If scanning fails
     */
    public function scanStream($stream): ScanResult;

    /**
     * Check if the scanner is available.
     */
    public function isAvailable(): bool;

    /**
     * Get the scanner name.
     */
    public function getName(): string;
}
