<?php

namespace Nraa\Workers\Contracts;

use Nraa\Workers\Documents\JobDocument;

interface QueueTransportInterface
{
    public function enqueue(array|object $jobData, bool $preventDuplicates = true): ?JobDocument;

    public function claimNextJob(string $workerId, string $poolName, ?array $workerConfig = null): ?JobDocument;

    public function supportsDispatcherAssignments(): bool;

    /**
     * @param array<int, string>|null $poolNames
     * @return array<string, int>
     */
    public function releaseDueJobs(?array $poolNames = null, ?\DateTimeImmutable $now = null): array;

    /**
     * @param array<int, string> $jobIds
     */
    public function reconcileJobs(array $jobIds, ?\DateTimeImmutable $now = null): int;

    /**
     * @return array<int, JobDocument>
     */
    public function fetchPendingForPool(string $poolName, int $limit, ?\DateTimeImmutable $now = null): array;

    public function markAssigned(string $jobId, string $workerId, ?array $instructions = null): bool;

    /**
     * @param array<int, string> $workerIds
     * @return array<string, int>
     */
    public function getActiveWorkerLoadMap(array $workerIds): array;

    /**
     * @param array<int, string> $jobClasses
     * @return array<string, int>
     */
    public function getActiveJobClassLoadMap(array $jobClasses): array;

    public function afterJobCompleted(JobDocument $job, string $workerId): void;

    public function afterJobRequeued(JobDocument $job, string $workerId, int $delaySeconds, string $errorMessage): void;

    public function afterJobTerminal(JobDocument $job, string $workerId, string $status, ?string $errorMessage = null): void;
}
