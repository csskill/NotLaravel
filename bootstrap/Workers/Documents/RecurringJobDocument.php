<?php

namespace Nraa\Workers\Documents;

use Nraa\Database\Model;

final class RecurringJobDocument extends Model
{
    protected static $collection = 'recurring_jobs';

    public array $task = [];
    public array $instructions = [];
    public ?string $name = null;
    public ?\MongoDB\BSON\UTCDateTime $lastRun = null;
    public array|string $jobCommand = [];
    public string $cron = '';

    public static function create(array $data): self
    {
        $recurring = parent::create(array_merge([
            'lastRun' => null,
        ], (array) $data));

        $recurring->save();
        return $recurring;
    }
}
