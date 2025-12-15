<?php

declare(strict_types=1);

namespace MonkeysLegion\Files\Security;

use MonkeysLegion\Files\Exception\VirusScanException;

/**
 * Composite scanner that runs multiple scanners.
 */
class CompositeScanner implements VirusScannerInterface
{
    /** @var VirusScannerInterface[] */
    private array $scanners = [];
    private bool $requireAll;

    public function __construct(bool $requireAll = false)
    {
        $this->requireAll = $requireAll;
    }

    public function addScanner(VirusScannerInterface $scanner): self
    {
        $this->scanners[] = $scanner;
        return $this;
    }

    public function scan(string $path): ScanResult
    {
        if (empty($this->scanners)) {
            throw new VirusScanException('No scanners configured');
        }

        $start = microtime(true);
        $results = [];
        $threats = [];

        foreach ($this->scanners as $scanner) {
            if (!$scanner->isAvailable()) {
                continue;
            }

            $result = $scanner->scan($path);
            $results[] = $result;

            if ($result->hasThreat()) {
                $threats[] = $scanner->getName() . ': ' . $result->threat;
                
                if (!$this->requireAll) {
                    return new ScanResult(
                        isClean: false,
                        threat: implode('; ', $threats),
                        scanner: 'Composite',
                        scanTime: microtime(true) - $start,
                        metadata: ['results' => array_map(fn($r) => $r->toArray(), $results)]
                    );
                }
            }
        }

        return new ScanResult(
            isClean: empty($threats),
            threat: empty($threats) ? null : implode('; ', $threats),
            scanner: 'Composite',
            scanTime: microtime(true) - $start,
            metadata: ['results' => array_map(fn($r) => $r->toArray(), $results)]
        );
    }

    public function scanStream($stream): ScanResult
    {
        // For stream scanning, we need to copy to a temp file
        $tempFile = tempnam(sys_get_temp_dir(), 'scan_');
        
        try {
            $tempStream = fopen($tempFile, 'wb');
            stream_copy_to_stream($stream, $tempStream);
            fclose($tempStream);
            
            return $this->scan($tempFile);
        } finally {
            @unlink($tempFile);
        }
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
        return 'Composite';
    }
}
