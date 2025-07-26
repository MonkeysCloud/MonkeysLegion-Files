<?php
namespace MonkeysLegion\Files\Support;

use MonkeysLegion\Mlc\Config;
use MonkeysLegion\Files\Contracts\FileNamer;
use MonkeysLegion\Files\Contracts\FileStorage;
use MonkeysLegion\Files\Storage\LocalStorage;
use MonkeysLegion\Files\Storage\S3Storage;
use MonkeysLegion\Files\Storage\GcsStorage;
use MonkeysLegion\Files\Upload\HashPathNamer;
use MonkeysLegion\Files\Upload\UploadManager;
use Aws\S3\S3Client;
use Google\Cloud\Storage\StorageClient;

final class ServiceProvider
{
    public function register($container): void
    {
        /** @var Config $mlc */
        $mlc = $container->get(Config::class);

        $defaultDisk = $mlc->get('files.default_disk', 'local');
        $disks       = $mlc->get('files.disks', []);
        $maxBytes    = $mlc->get('files.max_bytes', 20 * 1024 * 1024);
        $mimeAllow   = $mlc->get('files.mime_allow', []);

        // 1) Bind FileNamer
        $container->set(FileNamer::class, fn() => new HashPathNamer());

        // 2) Bind concrete drivers so you can type-hint them directly

        if (isset($disks['local'])) {
            $cfg = $disks['local'];
            $container->set(LocalStorage::class, fn() => new LocalStorage(
                root: $cfg['root'],
                publicBaseUrl: $cfg['public_base_url'] ?? null,
            ));
        }

        if (isset($disks['s3'])) {
            $cfg = $disks['s3'];
            $container->set(S3Storage::class, function() use ($cfg) {
                if (! class_exists(S3Client::class)) {
                    throw new \RuntimeException('aws/aws-sdk-php is required for S3');
                }
                $s3 = new S3Client([
                    'version' => 'latest',
                    'region'  => $cfg['region'] ?? 'us-east-1',
                ]);
                return new S3Storage(
                    $s3,
                    $cfg['bucket'],
                    $cfg['prefix']          ?? '',
                    $cfg['public_base_url'] ?? null
                );
            });
        }

        if (isset($disks['gcs'])) {
            $cfg = $disks['gcs'];

            $container->set(GcsStorage::class, function() use ($cfg) {
                if (! class_exists(StorageClient::class)) {
                    throw new \RuntimeException('google/cloud-storage is required for GCS');
                }

                $clientConfig = [];
                if (! empty($cfg['key_file_path'])) {
                    $clientConfig['keyFilePath'] = $cfg['key_file_path'];
                }
                if (! empty($cfg['project_id'])) {
                    $clientConfig['projectId'] = $cfg['project_id'];
                }

                // Create the client into $gcsClient (or rename consistently)
                $gcsClient = new StorageClient($clientConfig);

                return new GcsStorage(
                    $gcsClient,
                    $cfg['bucket'],
                    $cfg['prefix']          ?? '',
                    $cfg['public_base_url'] ?? null
                );
            });
        }

        // 3) Bind the FileStorage interface
        $container->set(FileStorage::class, function($c) use ($defaultDisk, $disks) {
            return match ($defaultDisk) {
                'local' => $c->get(LocalStorage::class),
                's3'    => $c->get(S3Storage::class),
                'gcs'   => $c->get(GcsStorage::class),
                default => throw new \RuntimeException("Unsupported disk '{$defaultDisk}'"),
            };
        });

        // 4) Bind the UploadManager
        $container->set(UploadManager::class, function($c) use ($maxBytes, $mimeAllow) {
            return new UploadManager(
                storage:   $c->get(FileStorage::class),
                namer:     $c->get(FileNamer::class),
                maxBytes:  (int)   $maxBytes,
                mimeAllow: (array) $mimeAllow,
            );
        });
    }
}