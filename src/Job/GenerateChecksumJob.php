<?php

declare(strict_types=1);

namespace MonkeysLegion\Files\Job;

/**
 * Job for generating file checksums.
 */
final class GenerateChecksumJob extends AbstractFileJob
{
    public function __construct(
        private string $fileId,
        private string $algorithm = 'sha256'
    ) {
        $this->queue = 'default';
    }

    public function getName(): string
    {
        return 'generate_checksum';
    }

    public function handle(): bool
    {
        /** @var \MonkeysLegion\Files\Repository\FileRepository $repository */
        $repository = $this->resolveRepository();
        
        /** @var \MonkeysLegion\Files\Contracts\StorageInterface $storage */
        $storage = $this->resolveStorage();
        
        $file = $repository->find($this->fileId);
        
        if ($file === null) {
            return false;
        }

        $contents = $storage->get($file->getPath());
        
        if ($contents === null) {
            return false;
        }

        $checksum = hash($this->algorithm, $contents);
        $file->setChecksum($checksum, $this->algorithm);
        
        $repository->save($file);

        return true;
    }

    public function toArray(): array
    {
        return [
            'file_id' => $this->fileId,
            'algorithm' => $this->algorithm,
        ];
    }

    public static function fromArray(array $data): static
    {
        return new static(
            $data['file_id'],
            $data['algorithm'] ?? 'sha256'
        );
    }

    private function resolveRepository(): object
    {
        // Would use DI container
        throw new \RuntimeException('Repository must be injected via DI container');
    }

    private function resolveStorage(): object
    {
        // Would use DI container
        throw new \RuntimeException('Storage must be injected via DI container');
    }
}
