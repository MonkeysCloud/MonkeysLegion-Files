<?php
declare(strict_types=1);

namespace MonkeysLegion\Files\Contracts;

use MonkeysLegion\Files\Security\ScanResult;

/**
 * MonkeysLegion Framework — Files Package
 *
 * Contract for virus scanner implementations.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
interface VirusScannerInterface
{
    /**
     * Scan a file on disk for viruses.
     *
     * @param string $path Absolute path to the file
     */
    public function scan(string $path): ScanResult;

    /**
     * Scan file contents from a stream.
     *
     * @param resource $stream Readable stream
     */
    public function scanStream(mixed $stream): ScanResult;

    /** Check if the scanner backend is reachable. */
    public function isAvailable(): bool;

    /** Get the scanner implementation name. */
    public function getName(): string;
}
