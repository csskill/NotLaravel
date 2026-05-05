<?php

namespace Nraa\Workers;

use MongoDB\BSON\UTCDateTime;
use Nraa\Workers\Documents\ScheduledJobDocument;

class ScheduledJobs
{
    /**
     * Schedule a new job to run at the given datetime.
     *
     * @param array $jobData The job data to schedule. This should contain the following keys:
     *   - 'task': The callable to run when the job is executed.
     *   - 'instructions': Optional parameters for the callable.
     *   - 'employer': Optional informational string.
     * @param \DateTime|\DateTimeImmutable $at The datetime to schedule the job at.
     * @return ScheduledJobDocument The scheduled job document.
     */
    public function schedule(array $jobData, \DateTime|\DateTimeImmutable $at): ScheduledJobDocument
    {
        return ScheduledJobDocument::create([
            'job' => $jobData,
            'runAt' => new \MongoDB\BSON\UTCDateTime($at),
            'status' => 'scheduled',
        ]);
    }

    /**
     * Get all scheduled jobs from the database.
     *
     * @return iterable The iterable list of ScheduledJobDocument objects.
     */
    public function all()
    {
        return ScheduledJobDocument::all();
    }

    /**
     * Fetch all scheduled jobs that are due to run at or before the given datetime.
     *
     * @param \DateTime|\DateTimeImmutable $now The datetime to check against.
     * @return iterable The iterable list of ScheduledJobDocument objects that are due to run.
     */
    public function fetchDueJobs(\DateTime|\DateTimeImmutable $now, ?int $limit = null): iterable
    {
        $now = $now instanceof \DateTimeImmutable ? $now : \DateTimeImmutable::createFromMutable($now);
        $nowUtc = new UTCDateTime($now);
        $claimTimeoutSeconds = max(30, (int)($_ENV['JOB_SCHEDULED_DISPATCH_CLAIM_TTL_SECONDS'] ?? 300));
        $staleClaimUtc = new UTCDateTime($now->modify("-{$claimTimeoutSeconds} seconds"));
        $remaining = $limit !== null ? max(1, $limit) : null;

        while (true) {
            if ($remaining !== null && $remaining <= 0) {
                return;
            }

            $result = (new ScheduledJobDocument())->findOneAndUpdate(
                [
                    'runAt' => ['$lte' => $nowUtc],
                    '$or' => [
                        ['status' => 'scheduled'],
                        [
                            'status' => 'dispatching',
                            'dispatchClaimedAt' => ['$lt' => $staleClaimUtc],
                        ],
                    ],
                ],
                [
                    '$set' => [
                        'status' => 'dispatching',
                        'dispatchClaimedAt' => $nowUtc,
                        'updatedAt' => $nowUtc,
                    ],
                ],
                [
                    'sort' => [
                        'runAt' => 1,
                        'createdAt' => 1,
                    ],
                    'returnDocument' => \MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER,
                ]
            );

            if ($result === null) {
                return;
            }

            if ($result instanceof ScheduledJobDocument) {
                if ($remaining !== null) {
                    $remaining--;
                }
                yield $result;
                continue;
            }

            if ($result instanceof \MongoDB\Model\BSONDocument) {
                $result = $result->getArrayCopy();
            } elseif ($result instanceof \stdClass) {
                $result = (array)$result;
            }

            if (!is_array($result) || $result === []) {
                return;
            }

            $scheduled = new ScheduledJobDocument();
            $scheduled->bsonUnserialize($result);
            if (isset($result['_id']) && $result['_id'] instanceof \MongoDB\BSON\ObjectId) {
                $scheduled->id = $result['_id'];
            }

            if ($remaining !== null) {
                $remaining--;
            }
            yield $scheduled;
        }
    }
}
