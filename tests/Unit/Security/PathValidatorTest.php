<?php
declare(strict_types=1);

namespace MonkeysLegion\Files\Tests\Unit\Security;

use MonkeysLegion\Files\Exception\SecurityException;
use MonkeysLegion\Files\Security\PathValidator;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MonkeysLegion\Files\Security\PathValidator
 */
final class PathValidatorTest extends TestCase
{
    public function testValidateReturnsFullPathForSafeInput(): void
    {
        $base = sys_get_temp_dir() . '/ml_path_' . bin2hex(random_bytes(4));

        try {
            $this->assertTrue(mkdir($base, 0o755, true));

            $validator = new PathValidator();
            $fullPath = $validator->validate('docs/file.txt', $base);

            $this->assertSame($base . '/docs/file.txt', $fullPath);
        } finally {
            @rmdir($base);
        }
    }

    public function testValidateBlocksTraversal(): void
    {
        $validator = new PathValidator();

        $this->expectException(SecurityException::class);
        $validator->validate('../etc/passwd', sys_get_temp_dir());
    }

    public function testValidateBlocksNullByte(): void
    {
        $validator = new PathValidator();

        $this->expectException(SecurityException::class);
        $validator->validate("bad\0name.txt", sys_get_temp_dir());
    }
}
