<?php

declare(strict_types=1);

namespace MonkeysLegion\Files\Job;

/**
 * Simple job dispatcher for testing and small deployments.
 * 
 * For production, integrate with a proper queue system (Redis, RabbitMQ, etc.)
 */
class SyncJobDispatcher
{
    private array $jobs = [];

    public function dispatch(FileJobInterface $job): void
    {
        $this->jobs[] = $job;
    }

    public function dispatchNow(FileJobInterface $job): bool
    {
        return $job->handle();
    }

    public function processAll(): array
    {
        $results = [];
        
        foreach ($this->jobs as $job) {
            $results[$job->getName()] = $job->handle();
        }
        
        $this->jobs = [];
        
        return $results;
    }

    public function getPendingCount(): int
    {
        return count($this->jobs);
    }
}
