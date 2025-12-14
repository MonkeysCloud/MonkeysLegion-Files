<?php

declare(strict_types=1);

namespace MonkeysLegion\Files;

use MonkeysLegion\Cache\CacheManager;
use MonkeysLegion\Database\Factory\ConnectionFactory;
use MonkeysLegion\Mlc\Config;
use MonkeysLegion\Mlc\Loader;
use MonkeysLegion\Mlc\Parser;
use MonkeysLegion\Files\Cdn\CdnUrlGenerator;
use MonkeysLegion\Files\Contracts\ChunkedUploadInterface;
use MonkeysLegion\Files\Contracts\StorageInterface;
use MonkeysLegion\Files\Image\ImageProcessor;
use MonkeysLegion\Files\Maintenance\GarbageCollector;
use MonkeysLegion\Files\RateLimit\UploadRateLimiter;
use MonkeysLegion\Files\Repository\FileRepository;
use MonkeysLegion\Files\Security\ClamAvScanner;
use MonkeysLegion\Files\Security\HttpVirusScanner;
use MonkeysLegion\Files\Security\VirusScannerInterface;
use MonkeysLegion\Files\Storage\GoogleCloudStorage;
use MonkeysLegion\Files\Storage\LocalStorage;
use MonkeysLegion\Files\Storage\S3Storage;
use MonkeysLegion\Files\Upload\ChunkedUploadManager;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Service provider for MonkeysLegion-Files package.
 * 
 * Integrates with:
 * - MonkeysLegion-Mlc for configuration
 * - MonkeysLegion-Cache for caching and rate limiting
 * - MonkeysLegion-Database for file tracking
 */
final class FilesServiceProvider
{
    private Config $config;
    private CacheManager $cacheManager;
    private ?object $dbConnection = null;
    private array $disks = [];
    private ?LoggerInterface $logger = null;

    /**
     * @param object $container PSR-11 compatible container
     * @param Config|array|null $config Configuration (Config object or array)
     * @param CacheManager|null $cacheManager Cache manager instance
     * @param object|null $dbConnection Database connection
     * @param LoggerInterface|null $logger Logger instance
     */
    public function __construct(
        private object $container,
        Config|array|null $config = null,
        ?CacheManager $cacheManager = null,
        ?object $dbConnection = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->config = $this->resolveConfig($config);
        $this->cacheManager = $cacheManager ?? $this->resolveCacheManager();
        $this->dbConnection = $dbConnection;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Register all services into the container.
     */
    public function register(): void
    {
        $this->registerStorageDrivers();
        $this->registerCoreServices();
        $this->registerOptionalServices();
    }

    /**
     * Boot the service provider.
     */
    public function boot(): void
    {
        // Initialize any services that need early setup
    }

    /**
     * Get the configuration.
     */
    public function getConfig(): Config
    {
        return $this->config;
    }

    /**
     * Get a storage disk by name.
     */
    public function disk(?string $name = null): StorageInterface
    {
        $name ??= $this->config->getString('files.default', 'local');

        if (!isset($this->disks[$name])) {
            $this->disks[$name] = $this->createStorage($name);
        }

        return $this->disks[$name];
    }

    /**
     * Get the cache manager.
     */
    public function getCacheManager(): CacheManager
    {
        return $this->cacheManager;
    }

    /**
     * Get the database connection.
     */
    public function getDbConnection(): ?object
    {
        return $this->dbConnection;
    }

    /**
     * Register storage drivers for all configured disks.
     */
    private function registerStorageDrivers(): void
    {
        // Register the default storage interface
        $this->bind(StorageInterface::class, fn() => $this->disk());

        // Register individual disks from config
        $disksConfig = $this->config->get('files.disks', []);
        
        if (is_array($disksConfig)) {
            foreach (array_keys($disksConfig) as $diskName) {
                $this->bind(
                    "files.disk.{$diskName}",
                    fn() => $this->disk($diskName)
                );
            }
        }
    }

    /**
     * Register core file management services.
     */
    private function registerCoreServices(): void
    {
        // Files Manager (main facade)
        $this->singleton(FilesManager::class, function () {
            $manager = new FilesManager(
                $this->config->all(), // Pass full config array
                $this->logger
            );

            $manager->setCache($this->cacheManager->store());

            if ($this->config->getBool('files.database.enabled', false)) {
                $manager->setRepository($this->resolve(FileRepository::class));
            }
            
            return $manager;
        });

        // Chunked Upload Manager
        $this->singleton(ChunkedUploadInterface::class, function () {
            return new ChunkedUploadManager(
                storage: $this->disk(),
                tempDir: $this->config->getString('files.upload.temp_dir', sys_get_temp_dir() . '/ml-uploads'),
                cache: $this->cacheManager->store(),
                chunkSize: $this->config->getInt('files.upload.chunk_size', 5 * 1024 * 1024),
                uploadExpiry: $this->config->getInt('files.upload.chunk_expiry', 86400),
            );
        });

        // Alias for ChunkedUploadManager
        $this->singleton(ChunkedUploadManager::class, function () {
            return $this->resolve(ChunkedUploadInterface::class);
        });

        // File Repository (database tracking)
        if ($this->config->getBool('files.database.enabled', false)) {
            $this->singleton(FileRepository::class, function () {
                $connection = $this->dbConnection ?? $this->resolveDbConnection();
                
                return new FileRepository(
                    connection: $connection,
                    tableName: $this->config->getString('files.database.tables.files', 'ml_files'),
                    conversionsTable: $this->config->getString('files.database.tables.conversions', 'ml_file_conversions'),
                    trackAccess: $this->config->getBool('files.database.track_access', true),
                    softDelete: $this->config->getBool('files.database.soft_delete', true),
                );
            });
        }

        // Rate Limiter
        if ($this->config->getBool('files.rate_limiting.enabled', true)) {
            $this->singleton(UploadRateLimiter::class, function () {
                return new UploadRateLimiter(
                    cache: $this->cacheManager,
                    maxUploadsPerMinute: $this->config->getInt('files.rate_limiting.uploads_per_minute', 10),
                    maxBytesPerHour: $this->config->getInt('files.rate_limiting.bytes_per_hour', 104857600),
                    maxConcurrentUploads: $this->config->getInt('files.rate_limiting.concurrent_uploads', 3),
                );
            });
        }
    }

    /**
     * Register optional services.
     */
    private function registerOptionalServices(): void
    {
        // Image Processor
        if ($this->isImageProcessingAvailable()) {
            $this->singleton(ImageProcessor::class, function () {
                return new ImageProcessor(
                    driver: $this->config->getString('files.image.driver', 'gd'),
                    quality: $this->config->getInt('files.image.quality', 85),
                );
            });
        }

        // CDN URL Generator
        if ($this->config->getBool('files.cdn.enabled', false)) {
            $this->singleton(CdnUrlGenerator::class, function () {
                return new CdnUrlGenerator(
                    baseUrl: $this->config->getString('files.cdn.url', ''),
                    signingKey: $this->config->getString('files.cdn.signing_key'),
                    defaultTtl: $this->config->getInt('files.cdn.default_ttl', 86400),
                );
            });
        }

        // Virus Scanner
        if ($this->config->getBool('files.security.virus_scan.enabled', false)) {
            $this->singleton(VirusScannerInterface::class, function () {
                $driver = $this->config->getString('files.security.virus_scan.driver', 'clamav');
                
                return match ($driver) {
                    'clamav' => new ClamAvScanner(
                        socketPath: $this->config->getString(
                            'files.security.virus_scan.clamav_socket',
                            '/var/run/clamav/clamd.ctl'
                        ),
                    ),
                    'http' => new HttpVirusScanner(
                        apiUrl: $this->config->getString('files.security.virus_scan.http_endpoint', ''),
                        apiKey: $this->config->getString('files.security.virus_scan.http_api_key'),
                    ),
                    default => throw new \InvalidArgumentException(
                        "Unknown virus scanner driver: {$driver}"
                    ),
                };
            });
        }

        // Garbage Collector
        $this->singleton(GarbageCollector::class, function () {
            return new GarbageCollector(
                storage: $this->disk(),
                repository: $this->config->getBool('files.database.enabled', false)
                    ? $this->resolve(FileRepository::class)
                    : null,
                config: [
                    'deleted_files_days' => $this->config->getInt('files.garbage_collection.deleted_files_days', 30),
                    'incomplete_uploads_hours' => $this->config->getInt('files.garbage_collection.incomplete_uploads_hours', 24),
                    'unused_conversions_days' => $this->config->getInt('files.garbage_collection.unused_conversions_days', 7),
                ],
                logger: $this->logger,
            );
        });
    }

    /**
     * Create a storage driver instance.
     */
    private function createStorage(string $diskName): StorageInterface
    {
        $diskConfig = $this->config->get("files.disks.{$diskName}");
        
        if (!$diskConfig || !is_array($diskConfig)) {
            throw new \InvalidArgumentException("Unknown disk: {$diskName}");
        }

        $driver = $diskConfig['driver'] ?? 'local';

        return match ($driver) {
            'local' => new LocalStorage(
                basePath: $diskConfig['root'] ?? 'storage/files',
                baseUrl: $diskConfig['url'] ?? '',
                directoryPermissions: $diskConfig['permissions']['dir'] ?? 0755,
                filePermissions: $diskConfig['permissions']['file'] ?? 0644,
                visibility: $diskConfig['visibility'] ?? 'public',
            ),

            's3', 'minio', 'spaces', 'r2' => new S3Storage(
                bucket: $diskConfig['bucket'] ?? '',
                region: $diskConfig['region'] ?? 'us-east-1',
                endpoint: $diskConfig['endpoint'] ?? null,
                accessKey: $diskConfig['key'] ?? null,
                secretKey: $diskConfig['secret'] ?? null,
                visibility: $diskConfig['visibility'] ?? 'private',
                options: $diskConfig,
            ),

            'gcs', 'google', 'firebase' => new GoogleCloudStorage(
                bucketName: $diskConfig['bucket'] ?? '',
                projectId: $diskConfig['project_id'] ?? null,
                keyFilePath: $diskConfig['key_file_path'] ?? null,
                keyFile: $diskConfig['key_file'] ?? null,
                visibility: $diskConfig['visibility'] ?? 'private',
                pathPrefix: $diskConfig['path_prefix'] ?? null,
                publicUrl: $diskConfig['url'] ?? null,
                options: $diskConfig,
            ),

            default => throw new \InvalidArgumentException(
                "Unknown storage driver: {$driver}"
            ),
        };
    }

    /**
     * Resolve configuration from various sources.
     */
    private function resolveConfig(Config|array|null $config): Config
    {
        if ($config instanceof Config) {
            return $config;
        }

        if (is_array($config)) {
            return new Config($config);
        }

        // Try to load from MLC file
        try {
            $loader = new Loader(
                parser: new Parser(),
                baseDir: $this->getConfigPath(),
            );
            
            return $loader->loadOne('files');
        } catch (\Throwable) {
            // Fall back to PHP config or default
            $phpConfigPath = $this->getConfigPath() . '/files.php';
            
            if (file_exists($phpConfigPath)) {
                return new Config(require $phpConfigPath);
            }
            
            return new Config($this->getDefaultConfig());
        }
    }

    /**
     * Resolve cache manager.
     */
    private function resolveCacheManager(): CacheManager
    {
        // Try to get from container
        try {
            if (method_exists($this->container, 'get')) {
                $manager = $this->container->get(CacheManager::class);
                if ($manager instanceof CacheManager) {
                    return $manager;
                }
            }
        } catch (\Throwable) {
            // Fall through to create new instance
        }

        // Create a default file-based cache manager
        return new CacheManager([
            'default' => 'file',
            'stores' => [
                'file' => [
                    'driver' => 'file',
                    'path' => sys_get_temp_dir() . '/ml-files-cache',
                    'prefix' => 'ml_files_',
                ],
            ],
        ]);
    }

    /**
     * Resolve database connection.
     */
    private function resolveDbConnection(): object
    {
        // Try to get from container
        try {
            if (method_exists($this->container, 'get')) {
                return $this->container->get('database');
            }
        } catch (\Throwable) {
            // Fall through
        }

        // Try ConnectionFactory
        $dbConfigPath = $this->getConfigPath() . '/database.php';
        
        if (file_exists($dbConfigPath)) {
            $dbConfig = require $dbConfigPath;
            return ConnectionFactory::create($dbConfig);
        }

        throw new \RuntimeException(
            'Database connection not configured. Please provide a connection or configure database.php'
        );
    }

    /**
     * Get the configuration path.
     */
    private function getConfigPath(): string
    {
        // Try common config paths
        $paths = [
            dirname(__DIR__) . '/config',
            getcwd() . '/config',
            dirname(__DIR__, 4) . '/config', // Vendor installation
        ];

        foreach ($paths as $path) {
            if (is_dir($path)) {
                return $path;
            }
        }

        return dirname(__DIR__) . '/config';
    }

    /**
     * Get default configuration.
     */
    private function getDefaultConfig(): array
    {
        return [
            'files' => [
                'default' => 'local',
                'disks' => [
                    'local' => [
                        'driver' => 'local',
                        'root' => 'storage/files',
                        'visibility' => 'private',
                    ],
                ],
                'upload' => [
                    'max_size' => 20 * 1024 * 1024,
                    'chunk_size' => 5 * 1024 * 1024,
                    'chunk_expiry' => 86400,
                    'temp_dir' => sys_get_temp_dir() . '/ml-uploads',
                ],
                'rate_limiting' => [
                    'enabled' => true,
                    'uploads_per_minute' => 10,
                    'bytes_per_hour' => 100 * 1024 * 1024,
                    'concurrent_uploads' => 3,
                ],
                'database' => [
                    'enabled' => false,
                ],
                'cdn' => [
                    'enabled' => false,
                ],
                'security' => [
                    'virus_scan' => [
                        'enabled' => false,
                    ],
                ],
            ],
        ];
    }

    /**
     * Check if image processing extensions are available.
     */
    private function isImageProcessingAvailable(): bool
    {
        return extension_loaded('gd') || extension_loaded('imagick');
    }

    /**
     * Bind a service into the container.
     */
    private function bind(string $abstract, callable $concrete): void
    {
        if (method_exists($this->container, 'bind')) {
            $this->container->bind($abstract, $concrete);
        } elseif (method_exists($this->container, 'set')) {
            $this->container->set($abstract, $concrete);
        }
    }

    /**
     * Bind a singleton service into the container.
     */
    private function singleton(string $abstract, callable $concrete): void
    {
        if (method_exists($this->container, 'singleton')) {
            $this->container->singleton($abstract, $concrete);
        } elseif (method_exists($this->container, 'share')) {
            $this->container->share($abstract, $concrete);
        } else {
            $instance = null;
            $this->bind($abstract, function () use ($concrete, &$instance) {
                return $instance ??= $concrete();
            });
        }
    }

    /**
     * Resolve a service from the container.
     */
    private function resolve(string $abstract): mixed
    {
        try {
            if (method_exists($this->container, 'get')) {
                return $this->container->get($abstract);
            }
            if (method_exists($this->container, 'make')) {
                return $this->container->make($abstract);
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }
}
