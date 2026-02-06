<?php

namespace Nraa\Workers\Documents;

use Nraa\Database\Model;
use MongoDB\BSON\UTCDateTime;

final class ScheduledJobDocument extends Model
{
    protected static $collection = 'scheduled_jobs';

    // Public properties for scheduled job data
    public array $job = [];
    public ?UTCDateTime $runAt = null;
    public string $status = 'scheduled';


    public static function create(array $data): self
    {
        $scheduled = parent::create(array_merge([
            'status' => 'scheduled',
        ], $data));

        $scheduled->save();
        return $scheduled;
    }
}
