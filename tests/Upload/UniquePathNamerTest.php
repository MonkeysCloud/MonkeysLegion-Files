<?php

namespace MonkeysLegion\Files\Tests\Upload;

use MonkeysLegion\Files\Upload\UniquePathNamer;
use PHPUnit\Framework\TestCase;

final class UniquePathNamerTest extends TestCase
{
    private UniquePathNamer $namer;

    protected function setUp(): void
    {
        $this->namer = new UniquePathNamer();
    }

    public function testStructure(): void
    {
        $path = $this->namer->path('test.txt', 'text/plain', 'hash');
        
        // Pattern: YYYY/MM/DD/UUID.ext
        $pattern = '#^\d{4}/\d{2}/\d{2}/[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\.txt$#';
        
        $this->assertMatchesRegularExpression($pattern, $path);
    }

    public function testExtensionPreservation(): void
    {
        $path = $this->namer->path('myimage.PNG', 'image/png', 'hash');
        $this->assertStringEndsWith('.png', $path); // lowercased
    }

    public function testMimeSniffing(): void
    {
        $path = $this->namer->path('blob', 'image/jpeg', 'hash');
        $this->assertStringEndsWith('.jpeg', $path);
    }

    public function testFallback(): void
    {
        $path = $this->namer->path('unknown', 'application/x-unknown-thingy', 'hash');
        $this->assertStringEndsWith('.bin', $path);
    }
}
