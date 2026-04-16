<?php
declare(strict_types=1);

namespace MonkeysLegion\Files\Tests\Unit\Security;

use MonkeysLegion\Files\Security\ContentValidator;
use MonkeysLegion\Files\Security\ScanResult;
use MonkeysLegion\Files\Exception\SecurityException;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MonkeysLegion\Files\Security\ContentValidator
 * @covers \MonkeysLegion\Files\Security\ScanResult
 */
final class SecurityTest extends TestCase
{
    // ── ContentValidator ─────────────────────────────────────────

    public function testValidatePassesForMatchingMime(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'ml_test_');
        file_put_contents($tmp, 'Hello world plain text content');

        $validator = new ContentValidator();
        $validator->validate($tmp, 'text/plain');

        // If we get here, no exception was thrown
        $this->assertTrue(true);

        unlink($tmp);
    }

    public function testValidateThrowsOnBlockedMime(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'ml_test_');
        // Write PHP content
        file_put_contents($tmp, '<?php echo "evil"; ?>');

        $validator = new ContentValidator();

        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage('Blocked content type');

        try {
            $validator->validate($tmp, 'image/jpeg');
        } finally {
            unlink($tmp);
        }
    }

    public function testValidateThrowsOnMimeMismatch(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'ml_test_');
        file_put_contents($tmp, 'Just plain text');

        $validator = new ContentValidator();

        $this->expectException(SecurityException::class);

        try {
            $validator->validate($tmp, 'image/jpeg');
        } finally {
            unlink($tmp);
        }
    }

    // ── ScanResult ───────────────────────────────────────────────

    public function testCleanResult(): void
    {
        $result = new ScanResult(isClean: true, scanner: 'clamav');

        $this->assertTrue($result->isClean);
        $this->assertFalse($result->hasThreat);
        $this->assertNull($result->threat);
        $this->assertSame('clamav', $result->scanner);
    }

    public function testThreatResult(): void
    {
        $result = new ScanResult(isClean: false, threat: 'Trojan.Gen', scanner: 'clamav');

        $this->assertFalse($result->isClean);
        $this->assertTrue($result->hasThreat);
        $this->assertSame('Trojan.Gen', $result->threat);
    }

    public function testToArray(): void
    {
        $result = new ScanResult(isClean: true, scanTime: 0.5);
        $arr = $result->toArray();

        $this->assertTrue($arr['is_clean']);
        $this->assertSame(0.5, $arr['scan_time']);
    }
}
