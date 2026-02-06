<?php

/**
 * Register recurring jobs for the application
 * 
 * This file is loaded during application bootstrap to register
 * jobs that should run on a schedule.
 */

use Nraa\Jobs\WeeklyRace\CloseWeeklyRacesJob;

// Register recurring job to close weekly races on Sunday 23:59 UTC
$recurring->register(
    [CloseWeeklyRacesJob::class, 'handle'],
    '59 23 * * 0' // Sunday 23:59 UTC
);
