<?php

namespace MonkeysLegion\Files\Tests\Job;

use MonkeysLegion\Files\Tests\TestCase;
use MonkeysLegion\Files\Job\GenerateChecksumJob;
use MonkeysLegion\Files\Job\SyncJobDispatcher;
use MonkeysLegion\Files\Job\FileJobInterface;

class JobTest extends TestCase
{
    public function testJobSerialization()
    {
        $job = new GenerateChecksumJob(123, 'md5');
        $data = $job->toArray();
        
        $this->assertEquals(123, $data['file_id']);
        $this->assertEquals('md5', $data['algorithm']);
        
        $recreated = GenerateChecksumJob::fromArray($data);
        $this->assertEquals($job->getName(), $recreated->getName());
        $this->assertEquals($data, $recreated->toArray());
    }

    public function testDispatcher()
    {
        $job = \Mockery::mock(FileJobInterface::class);
        $job->shouldReceive('handle')->once()->andReturn(true);
        $job->shouldReceive('getName')->andReturn('test_job');

        $dispatcher = new SyncJobDispatcher();
        $dispatcher->dispatch($job);
        
        $this->assertEquals(1, $dispatcher->getPendingCount());
        
        $results = $dispatcher->processAll();
        
        $this->assertEquals(0, $dispatcher->getPendingCount());
        $this->assertTrue($results['test_job']);
    }
}
