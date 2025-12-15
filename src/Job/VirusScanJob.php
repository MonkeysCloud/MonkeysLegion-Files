<?php

declare(strict_types=1);

namespace MonkeysLegion\Files\Job;

/**
 * Job for virus scanning uploaded files.
 */
final class VirusScanJob extends AbstractFileJob
{
    public function __construct(
        private string $fileId,
        private bool $quarantineOnThreat = true,
        private bool $deleteOnThreat = false
    ) {
        $this->queue = 'security';
        $this->maxRetries = 2;
    }

    public function getName(): string
    {
        return 'virus_scan';
    }

    public function handle(): bool
    {
        /** @var \MonkeysLegion\Files\Repository\FileRepository $repository */
        $repository = $this->resolveRepository();
        
        /** @var \MonkeysLegion\Files\Contracts\StorageInterface $storage */
        $storage = $this->resolveStorage();
        
        /** @var \MonkeysLegion\Files\Security\VirusScannerInterface $scanner */
        $scanner = $this->resolveScanner();

        $file = $repository->find($this->fileId);
        
        if ($file === null) {
            return false;
        }

        // Get file stream for scanning
        $stream = $storage->getStream($file->getPath());
        
        if ($stream === null) {
            return false;
        }

        try {
            $result = $scanner->scanStream($stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        // Update file metadata with scan result
        $file->addMetadata('virus_scan', [
            'scanned_at' => (new \DateTimeImmutable())->format('c'),
            'scanner' => $result->scanner,
            'is_clean' => $result->isClean,
            'threat' => $result->threat,
        ]);

        if ($result->hasThreat()) {
            if ($this->deleteOnThreat) {
                // File not safe, delete it
                $repository->forceDelete((string)$file->getId());
            } elseif ($this->quarantineOnThreat) {
                // Move to quarantine location
                $quarantinePath = 'quarantine/' . $file->getPath();
                $storage->move($file->getPath(), $quarantinePath);
                $file->addMetadata('quarantined_at', (new \DateTimeImmutable())->format('c'));
                $file->addMetadata('original_path', $file->getPath());
                $repository->save($file);
            }
        } else {
            $repository->save($file);
        }

        return $result->isClean;
    }

    public function toArray(): array
    {
        return [
            'file_id' => $this->fileId,
            'quarantine_on_threat' => $this->quarantineOnThreat,
            'delete_on_threat' => $this->deleteOnThreat,
        ];
    }

    public static function fromArray(array $data): static
    {
        return new self(
            $data['file_id'],
            $data['quarantine_on_threat'] ?? true,
            $data['delete_on_threat'] ?? false
        );
    }

    private function resolveRepository(): object
    {
        throw new \RuntimeException('Repository must be injected via DI container');
    }

    private function resolveStorage(): object
    {
        throw new \RuntimeException('Storage must be injected via DI container');
    }

    private function resolveScanner(): object
    {
        throw new \RuntimeException('Scanner must be injected via DI container');
    }
}
