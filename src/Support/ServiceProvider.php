<?php
namespace MonkeysLegion\Files\Support;

use MonkeysLegion\DI\ContainerBuilder;
use MonkeysLegion\Mlc\Config as MlcConfig;
use MonkeysLegion\Files\Contracts\FileNamer;
use MonkeysLegion\Files\Contracts\FileStorage;
use MonkeysLegion\Files\Storage\LocalStorage;
use MonkeysLegion\Files\Storage\S3Storage;
use MonkeysLegion\Files\Storage\GcsStorage;
use MonkeysLegion\Files\Upload\UniquePathNamer;
use MonkeysLegion\Files\Upload\UploadManager;
use Aws\S3\S3Client;
use Google\Cloud\Storage\StorageClient;

/**
 * Service provider for the Files module.
 * Registers FileNamer, FileStorage, and UploadManager services.
 */
final class ServiceProvider
{
    /**
     * Register the service definitions in the DI container.
     *
     * @param ContainerBuilder $builder The DI container builder.
     */
    public function register(ContainerBuilder $builder): void
    {
        $builder->addDefinitions([
            FileNamer::class => fn() => new UniquePathNamer(),

            FileStorage::class => function ($c) {
                /** @var MlcConfig $mlc */
                $mlc   = $c->get(MlcConfig::class);
                $disk  = $mlc->get('files.default_disk', 'local');
                $disks = $mlc->get('files.disks', []);
                if (!isset($disks[$disk])) {
                    throw new \RuntimeException("Disk '{$disk}' not configured in files.mlc");
                }
                $cfg = $disks[$disk];

                return match ($disk) {
                    'local' => $this->makeLocal($cfg),
                    's3'    => $this->makeS3($cfg),
                    'gcs'   => $this->makeGcs($cfg),
                    default => throw new \RuntimeException("Unsupported disk '{$disk}'"),
                };
            },

            UploadManager::class => function ($c) {
                /** @var MlcConfig $mlc */
                $mlc = $c->get(MlcConfig::class);
                return new UploadManager(
                    $c->get(FileStorage::class),
                    $c->get(FileNamer::class),
                    (int) $mlc->get('files.max_bytes', 20 * 1024 * 1024),
                    (array) $mlc->get('files.mime_allow', [])
                );
            },
        ]);
    }

    /**
     * Create a FileStorage instance based on the configuration.
     *
     * @param array $cfg Configuration for the storage.
     * @return FileStorage
     */

    private function makeLocal(array $cfg): FileStorage
    {
        $rootConf  = $cfg['root'] ?? 'storage/app';
        // Resolve to absolute path relative to project root
        $root = str_starts_with($rootConf, DIRECTORY_SEPARATOR) ? $rootConf : base_path($rootConf);

        $publicUrl = rtrim($cfg['public_base_url'] ?? '', '/');

        // Ensure storage root
        if (!is_dir($root)) {
            mkdir($root, 0755, true);
        }

        // Ensure public folder under project/public/{fragment} when it's a path (not full URL)
        if ($publicUrl !== '' && !preg_match('#^https?://#i', $publicUrl)) {
            $fragment = ltrim($publicUrl, '/');
            $publicDir = base_path('public/' . $fragment);
            if (!is_dir($publicDir)) {
                mkdir($publicDir, 0755, true);
            }
        }

        return new LocalStorage($root, $publicUrl);
    }

    /**
     * Create a FileStorage instance for S3 or GCS based on the configuration.
     *
     * @param array $cfg Configuration for the storage.
     * @return FileStorage
     * @throws \RuntimeException if required SDK is not installed.
     */
    private function makeS3(array $cfg): FileStorage
    {
        if (!class_exists(S3Client::class)) {
            throw new \RuntimeException('aws/aws-sdk-php is required for S3 storage.');
        }
        $s3 = new S3Client([
            'version' => 'latest',
            'region'  => $cfg['region'] ?? 'us-east-1',
        ]);

        return new S3Storage(
            $s3,
            $cfg['bucket'] ?? '',
            $cfg['prefix']          ?? '',
            $cfg['public_base_url'] ?? null
        );
    }

    /**
     * Create a FileStorage instance for Google Cloud Storage based on the configuration.
     *
     * @param array $cfg Configuration for the storage.
     * @return FileStorage
     * @throws \RuntimeException if required SDK is not installed.
     */
    private function makeGcs(array $cfg): FileStorage
    {
        if (!class_exists(StorageClient::class)) {
            throw new \RuntimeException('google/cloud-storage is required for GCS storage.');
        }

        $clientConfig = [];
        if (!empty($cfg['key_file_path'])) {
            $clientConfig['keyFilePath'] = $cfg['key_file_path'];
        }
        if (!empty($cfg['project_id'])) {
            $clientConfig['projectId'] = $cfg['project_id'];
        }

        $gcs = new StorageClient($clientConfig);

        return new GcsStorage(
            $gcs,
            $cfg['bucket'] ?? '',
            $cfg['prefix']          ?? '',
            $cfg['public_base_url'] ?? null
        );
    }
}