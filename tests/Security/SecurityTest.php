<?php

namespace MonkeysLegion\Files\Tests\Security;

use MonkeysLegion\Files\Tests\TestCase;
use MonkeysLegion\Files\Security\ScanResult;
use MonkeysLegion\Files\Security\CompositeScanner;
use MonkeysLegion\Files\Security\VirusScannerInterface;

class SecurityTest extends TestCase
{
    public function testScanResult()
    {
        $result = new ScanResult(true, null, 'clamav', 0.5);
        $this->assertTrue($result->isClean);
        $this->assertFalse($result->hasThreat());
        
        $threat = new ScanResult(false, 'EICAR', 'clamav', 0.1);
        $this->assertFalse($threat->isClean);
        $this->assertTrue($threat->hasThreat());
        $this->assertEquals('EICAR', $threat->threat);
    }

    public function testCompositeScanner()
    {
        $scanner1 = \Mockery::mock(VirusScannerInterface::class);
        $scanner1->shouldReceive('isAvailable')->andReturn(true);
        $scanner1->shouldReceive('getName')->andReturn('scanner1');
        $scanner1->shouldReceive('scan')
            ->with('file.txt')
            ->andReturn(new ScanResult(true, null, 'scanner1'));

        $scanner2 = \Mockery::mock(VirusScannerInterface::class);
        $scanner2->shouldReceive('isAvailable')->andReturn(true);
        $scanner2->shouldReceive('getName')->andReturn('scanner2');
        $scanner2->shouldReceive('scan')
            ->with('file.txt')
            ->andReturn(new ScanResult(true, null, 'scanner2'));

        $composite = new CompositeScanner(false);
        $composite->addScanner($scanner1);
        $composite->addScanner($scanner2);

        $result = $composite->scan('file.txt');
        $this->assertTrue($result->isClean);
        $this->assertEquals('Composite', $result->scanner);
    }

    public function testCompositeScannerDetectsThreat()
    {
        $scanner1 = \Mockery::mock(VirusScannerInterface::class);
        $scanner1->shouldReceive('isAvailable')->andReturn(true);
        $scanner1->shouldReceive('getName')->andReturn('scanner1');
        $scanner1->shouldReceive('scan')
            ->andReturn(new ScanResult(false, 'Threat1', 'scanner1'));

        $composite = new CompositeScanner(false);
        $composite->addScanner($scanner1);

        $result = $composite->scan('file.txt');
        $this->assertFalse($result->isClean);
        $this->assertStringContainsString('Threat1', $result->threat);
    }
}
