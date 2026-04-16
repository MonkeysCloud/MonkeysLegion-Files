<?php
declare(strict_types=1);

namespace MonkeysLegion\Files;

use MonkeysLegion\Files\Attributes\Disk;
use MonkeysLegion\Files\Attributes\StorageConfig;
use MonkeysLegion\Files\Contracts\StorageInterface;
use MonkeysLegion\Files\Driver\GcsDriver;
use MonkeysLegion\Files\Driver\LocalDriver;
use MonkeysLegion\Files\Driver\MemoryDriver;
use MonkeysLegion\Files\Driver\S3Driver;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * MonkeysLegion Framework — Files Package
 *
 * Attribute-driven service provider. Scans for classes annotated with
 * #[StorageConfig] and #[Disk] to auto-register storage drivers and
 * build the FilesManager instance.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final class FilesServiceProvider
{
    /** Known driver factories. */
    private const array DRIVERS = [
        'local'  => LocalDriver::class,
        's3'     => S3Driver::class,
        'gcs'    => GcsDriver::class,
        'memory' => MemoryDriver::class,
    ];

    /**
     * Build a FilesManager from a configuration array.
     *
     * @param array<string, array<string, mixed>> $disks       Disk configs keyed by name
     * @param string                              $defaultDisk Default disk name
     * @param LoggerInterface                     $logger      PSR logger
     */
    public static function create(
        array $disks,
        string $defaultDisk = 'local',
        ?LoggerInterface $logger = null,
    ): FilesManager {
        $drivers = [];

        foreach ($disks as $name => $config) {
            $drivers[$name] = self::buildDriver($config);
        }

        return new FilesManager(
            disks: $drivers,
            defaultDisk: $defaultDisk,
            logger: $logger ?? new NullLogger(),
        );
    }

    /**
     * Build a FilesManager from attribute-annotated configuration class.
     *
     * @param object          $configInstance Instance of the #[StorageConfig] class
     * @param LoggerInterface $logger         PSR logger
     */
    public static function fromAttributes(
        object $configInstance,
        ?LoggerInterface $logger = null,
    ): FilesManager {
        $reflection = new \ReflectionClass($configInstance);
        $attrs      = $reflection->getAttributes(StorageConfig::class);

        if ($attrs === []) {
            throw new Exception\StorageException(
                'Configuration class must be annotated with #[StorageConfig]',
            );
        }

        /** @var StorageConfig $config */
        $config      = $attrs[0]->newInstance();
        $defaultDisk = $config->defaultDisk;
        $drivers     = [];

        // Scan methods for #[Disk] attributes
        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $diskAttrs = $method->getAttributes(Disk::class);

            if ($diskAttrs === []) {
                continue;
            }

            /** @var Disk $disk */
            $disk = $diskAttrs[0]->newInstance();

            $methodConfig = $method->invoke($configInstance);

            if ($methodConfig instanceof StorageInterface) {
                $drivers[$disk->name] = $methodConfig;
            } elseif (is_array($methodConfig)) {
                $methodConfig['driver'] = $methodConfig['driver'] ?? $disk->driver;
                $drivers[$disk->name]   = self::buildDriver($methodConfig);
            }
        }

        return new FilesManager(
            disks: $drivers,
            defaultDisk: $defaultDisk,
            logger: $logger ?? new NullLogger(),
        );
    }

    /**
     * Build a driver instance from a configuration array.
     *
     * @param array<string, mixed> $config
     */
    private static function buildDriver(array $config): StorageInterface
    {
        $driverName = $config['driver'] ?? 'local';

        return match ($driverName) {
            'local' => new LocalDriver(
                basePath: $config['base_path'] ?? $config['root'] ?? '/tmp/storage',
                baseUrl: $config['base_url'] ?? $config['url'] ?? '',
                dirPermissions: $config['dir_permissions'] ?? 0o755,
                filePermissions: $config['file_permissions'] ?? 0o644,
                defaultVisibility: Visibility::tryFrom($config['visibility'] ?? 'public')
                    ?? Visibility::Public,
            ),
            's3' => new S3Driver(
                bucket: $config['bucket'] ?? '',
                region: $config['region'] ?? 'us-east-1',
                endpoint: $config['endpoint'] ?? null,
                accessKey: $config['key'] ?? $config['access_key'] ?? null,
                secretKey: $config['secret'] ?? $config['secret_key'] ?? null,
                prefix: $config['prefix'] ?? '',
                defaultVisibility: Visibility::tryFrom($config['visibility'] ?? 'private')
                    ?? Visibility::Private,
            ),
            'gcs' => new GcsDriver(
                bucket: $config['bucket'] ?? '',
                keyFilePath: $config['key_file'] ?? $config['key_file_path'] ?? null,
                projectId: $config['project_id'] ?? null,
                prefix: $config['prefix'] ?? '',
                defaultVisibility: Visibility::tryFrom($config['visibility'] ?? 'private')
                    ?? Visibility::Private,
            ),
            'memory' => new MemoryDriver(
                defaultVisibility: Visibility::tryFrom($config['visibility'] ?? 'private')
                    ?? Visibility::Private,
            ),
            default => throw new Exception\StorageException("Unknown driver: {$driverName}"),
        };
    }
}
