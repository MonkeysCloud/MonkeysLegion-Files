<?php

namespace MonkeysLegion\Files\Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

abstract class TestCase extends BaseTestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if (class_exists(\Mockery::class)) {
            \Mockery::close();
        }
    }
}
