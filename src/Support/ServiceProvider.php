<?php
namespace MonkeysLegion\Files\Support;

use MonkeysLegion\DI\ContainerBuilder;
use MonkeysLegion\Mlc\Config as MlcConfig;
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
    public function register(ContainerBuilder $builder): void
    {
        $builder->addDefinitions([
            // 1) FileNamer
            FileNamer::class => fn() => new HashPathNamer(),

            // 2) FileStorage (only local here; extend with S3/GCS if needed)
            FileStorage::class => function ($c) {
                /** @var MlcConfig $mlc */
                $mlc = $c->get(MlcConfig::class);

                $disk   = $mlc->get('files.default_disk', 'local');
                $disks  = $mlc->get('files.disks', []);
                $cfg    = $disks[$disk] ?? [];
                $root   = $cfg['root'] ?? 'storage/app';
                $public = rtrim($cfg['public_base_url'] ?? '', '/');

                // ensure storage root
                if (! is_dir($root)) {
                    mkdir($root, 0755, true);
                }

                // ensure public folder under public/{fragment}
                if ($public !== '' && ! preg_match('#^https?://#i', $public)) {
                    $frag = ltrim($public, '/');
                    $pubDir = dirname(__DIR__, 3) . "/public/{$frag}";
                    if (! is_dir($pubDir)) {
                        mkdir($pubDir, 0755, true);
                    }
                }

                return new LocalStorage($root, $public);
            },

            // 3) UploadManager
            UploadManager::class => function ($c) {
                /** @var MlcConfig $mlc */
                $mlc      = $c->get(MlcConfig::class);
                $maxBytes = (int) $mlc->get('files.max_bytes', 20 * 1024 * 1024);
                $mimeAllow= (array)$mlc->get('files.mime_allow', []);

                return new UploadManager(
                    $c->get(FileStorage::class),
                    $c->get(FileNamer::class),
                    $maxBytes,
                    $mimeAllow
                );
            },
        ]);
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