<?php
declare(strict_types=1);

namespace MonkeysLegion\Files\Security;

use MonkeysLegion\Files\Contracts\VirusScannerInterface;
use MonkeysLegion\Files\Exception\StorageException;

/**
 * MonkeysLegion Framework — Files Package
 *
 * Composite virus scanner that tries ClamAV socket first,
 * then falls back to an HTTP-based scanning API.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final class VirusScanner implements VirusScannerInterface
{
    /** @var list<VirusScannerInterface> */
    private readonly array $scanners;

    public function __construct(VirusScannerInterface ...$scanners)
    {
        $this->scanners = $scanners;
    }

    public function scan(string $path): ScanResult
    {
        $start = microtime(true);

        foreach ($this->scanners as $scanner) {
            if (!$scanner->isAvailable()) {
                continue;
            }

            $result = $scanner->scan($path);

            return new ScanResult(
                isClean: $result->isClean,
                threat: $result->threat,
                scanner: $scanner->getName(),
                scanTime: microtime(true) - $start,
                metadata: $result->metadata,
            );
        }

        // No scanner available — return clean by default (configurable policy)
        return new ScanResult(
            isClean: true,
            scanner: 'none',
            scanTime: microtime(true) - $start,
            metadata: ['warning' => 'No virus scanner available'],
        );
    }

    public function scanStream(mixed $stream): ScanResult
    {
        $start = microtime(true);

        foreach ($this->scanners as $scanner) {
            if (!$scanner->isAvailable()) {
                continue;
            }

            $result = $scanner->scanStream($stream);

            return new ScanResult(
                isClean: $result->isClean,
                threat: $result->threat,
                scanner: $scanner->getName(),
                scanTime: microtime(true) - $start,
                metadata: $result->metadata,
            );
        }

        return new ScanResult(
            isClean: true,
            scanner: 'none',
            scanTime: microtime(true) - $start,
            metadata: ['warning' => 'No virus scanner available'],
        );
    }

    public function isAvailable(): bool
    {
        foreach ($this->scanners as $scanner) {
            if ($scanner->isAvailable()) {
                return true;
            }
        }

        return false;
    }

    public function getName(): string
    {
        $names = array_map(
            fn(VirusScannerInterface $s) => $s->getName(),
            $this->scanners,
        );

        return 'composite[' . implode(', ', $names) . ']';
    }
}
