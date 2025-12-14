<?php

namespace MonkeysLegion\Mlc {
    class Config
    {
        public function get(string $key, mixed $default = null): mixed
        {
            return $default;
        }
    }
}

namespace Aws\S3 {
    class S3Client {
        public function __construct(array $args) {}
        public function putObject(array $args): \Aws\Result { return new \Aws\Result(); }
        public function deleteObject(array $args): \Aws\Result { return new \Aws\Result(); }
        public function getObject(array $args): \Aws\Result { return new \Aws\Result(); }
        public function headObject(array $args): \Aws\Result { return new \Aws\Result(); }
    }
}

namespace Aws {
    class Result implements \ArrayAccess {
        public function offsetExists($offset): bool { return true; }
        public function offsetGet($offset): mixed { return null; }
        public function offsetSet($offset, $value): void {}
        public function offsetUnset($offset): void {}
    }
}

namespace Google\Cloud\Storage {
    class StorageClient {
        public function __construct(array $config = []) {}
        public function bucket(string $name): Bucket { return new Bucket(); }
    }
    class Bucket {
        public function object(string $name): StorageObject { return new StorageObject(); }
        public function upload($data, array $options = []): StorageObject { return new StorageObject(); }
    }
    class StorageObject {
        public function delete(): void {}
        public function downloadAsStream(): \Psr\Http\Message\StreamInterface { throw new \Exception('Stub'); }
        public function exists(): bool { return true; }
    }
}

namespace MonkeysLegion\DI {
    class ContainerBuilder {
        public function addDefinitions(array $definitions): void {}
    }
}

namespace MonkeysLegion\Tests {
    class TestState {
        public static $container = null;
        public static $config = [];
    }
}

namespace {
    use MonkeysLegion\DI\ContainerBuilder;
    use Psr\Container\ContainerInterface;
    use MonkeysLegion\Tests\TestState;

    if (!function_exists('container')) {
        function container(): ContainerInterface {
            if (TestState::$container) {
                return TestState::$container;
            }
            throw new \RuntimeException('Container not set in TestState.');
        }
    }

    if (!function_exists('config')) {
        function config(): array {
            return TestState::$config;
        }
    }

    if (!function_exists('base_path')) {
        function base_path(string $path = ''): string {
            return '/tmp/ml-test/' . ltrim($path, '/');
        }
    }
}
