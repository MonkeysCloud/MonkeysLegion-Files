<?php

declare(strict_types=1);

namespace MonkeysLegion\Files\Job;

/**
 * Base class for file jobs.
 */
abstract class AbstractFileJob implements FileJobInterface
{
    protected int $maxRetries = 3;
    protected int $retryDelay = 60;
    protected ?string $queue = null;

    public function getMaxRetries(): int
    {
        return $this->maxRetries;
    }

    public function getRetryDelay(): int
    {
        return $this->retryDelay;
    }

    public function getQueue(): ?string
    {
        return $this->queue;
    }
}
