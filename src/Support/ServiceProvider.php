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

        // 1) FileNamer
        $container->set(FileNamer::class, fn() => new HashPathNamer());

        // 2) FileStorage via factory methods
        $container->set(FileStorage::class, function() use ($defaultDisk, $disks) {
            if (! isset($disks[$defaultDisk])) {
                throw new \RuntimeException("Disk '{$defaultDisk}' not configured");
            }

            return match ($defaultDisk) {
                'local' => $this->makeLocal($disks['local']),
                's3'    => $this->makeS3($disks['s3']),
                'gcs'   => $this->makeGcs($disks['gcs']),
                default => throw new \RuntimeException("Unsupported disk '{$defaultDisk}'"),
            };
        });

        // 3) UploadManager
        $container->set(UploadManager::class, function($c) use ($maxBytes, $mimeAllow) {
            return new UploadManager(
                $c->get(FileStorage::class),
                $c->get(FileNamer::class),
                (int)   $maxBytes,
                (array) $mimeAllow
            );
        });
    }

    private function makeLocal(array $cfg): FileStorage
    {
        $root      = $cfg['root'];
        $publicUrl = rtrim($cfg['public_base_url'] ?? '', '/');

        // Ensure storage root
        if (! is_dir($root)) {
            mkdir($root, 0755, true);
        }

        // Ensure public folder under project/public/{fragment}
        if ($publicUrl !== '' && ! preg_match('#^https?://#i', $publicUrl)) {
            $fragment  = ltrim($publicUrl, '/');
            $publicDir = dirname(__DIR__, 2) . '/public/' . $fragment;
            if (! is_dir($publicDir)) {
                mkdir($publicDir, 0755, true);
            }
        }

        return new LocalStorage($root, $publicUrl);
    }

    private function makeS3(array $cfg): FileStorage
    {
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
    }

    private function makeGcs(array $cfg): FileStorage
    {
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

        $gcsClient = new StorageClient($clientConfig);

        return new GcsStorage(
            $gcsClient,
            $cfg['bucket'],
            $cfg['prefix']          ?? '',
            $cfg['public_base_url'] ?? null
        );
    }
}