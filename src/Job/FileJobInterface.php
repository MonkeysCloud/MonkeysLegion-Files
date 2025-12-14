<?php

declare(strict_types=1);

namespace MonkeysLegion\Files\Job;

/**
 * Interface for file jobs.
 * 
 * Jobs implement this interface to be queued for background processing.
 */
interface FileJobInterface
{
    /**
     * Execute the job.
     *
     * @return bool True on success
     */
    public function handle(): bool;

    /**
     * Get the job name for logging.
     */
    public function getName(): string;

    /**
     * Get job data for serialization.
     */
    public function toArray(): array;

    /**
     * Create job from serialized data.
     */
    public static function fromArray(array $data): static;

    /**
     * Get the maximum number of retry attempts.
     */
    public function getMaxRetries(): int;

    /**
     * Get the retry delay in seconds.
     */
    public function getRetryDelay(): int;
}
