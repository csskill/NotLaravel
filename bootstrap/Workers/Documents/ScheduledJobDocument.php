<?php

namespace Nraa\Workers\Documents;

use Nraa\Database\Attributes\Index;
use Nraa\Database\Model;
use MongoDB\BSON\UTCDateTime;

#[Index(keys: ['status' => 1, 'runAt' => 1], options: [])]
#[Index(keys: ['status' => 1, 'runAt' => 1, 'createdAt' => 1], options: [])]
#[Index(keys: ['status' => 1, 'dispatchClaimedAt' => 1], options: [])]
#[Index(keys: ['status' => 1, 'dispatchClaimedAt' => 1, 'runAt' => 1, 'createdAt' => 1], options: [])]
#[Index(keys: ['status' => 1, 'terminalAt' => -1], options: [])]
final class ScheduledJobDocument extends Model
{
    protected static $collection = 'scheduled_jobs';

    // Public properties for scheduled job data
    public array $job = [];
    public ?UTCDateTime $runAt = null;
    public string $status = 'scheduled';
    public ?UTCDateTime $dispatchClaimedAt = null;
    public ?UTCDateTime $terminalAt = null;


    public static function create(array $data): self
    {
        $scheduled = parent::create(array_merge([
            'status' => 'scheduled',
        ], $data));

        $scheduled->save();
        return $scheduled;
    }
}
