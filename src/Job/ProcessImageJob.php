<?php

declare(strict_types=1);

namespace MonkeysLegion\Files\Job;

use MonkeysLegion\Files\Contracts\StorageInterface;

/**
 * Job for processing image conversions.
 */
final class ProcessImageJob extends AbstractFileJob
{
    public function __construct(
        private string $sourcePath,
        private string $disk,
        private array $conversions = [],
        private array $options = []
    ) {
        $this->queue = 'images';
    }

    public function getName(): string
    {
        return 'process_image';
    }

    public function handle(): bool
    {
        // This would integrate with ImageProcessor
        // Implementation depends on DI container setup
        
        /** @var \MonkeysLegion\Files\Image\ImageProcessor $processor */
        $processor = $this->resolveProcessor();
        $storage = $this->resolveStorage();
        
        foreach ($this->conversions as $name => $config) {
            $processor->thumbnail(
                $storage,
                $this->sourcePath,
                (int) ($config['width'] ?? 200),
                (int) ($config['height'] ?? 200),
                (string) ($config['fit'] ?? 'cover')
            );
        }

        return true;
    }

    public function toArray(): array
    {
        return [
            'source_path' => $this->sourcePath,
            'disk' => $this->disk,
            'conversions' => $this->conversions,
            'options' => $this->options,
        ];
    }

    public static function fromArray(array $data): static
    {
        return new static(
            $data['source_path'],
            $data['disk'],
            $data['conversions'] ?? [],
            $data['options'] ?? []
        );
    }

    private function resolveProcessor(): object
    {
        // This would use the DI container in practice
        if (function_exists('ml_container')) {
            return ml_container()->get(\MonkeysLegion\Files\Image\ImageProcessor::class);
        }
        throw new \RuntimeException('Container not available');
    }

    private function resolveStorage(): StorageInterface
    {
        if (function_exists('ml_container')) {
            $manager = ml_container()->get(\MonkeysLegion\Files\FilesManager::class);
            return $manager->disk($this->disk);
        }
        throw new \RuntimeException('Container not available');
    }
}
