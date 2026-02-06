<?php


return [
    'download' => [
        'name' => 'Download Pool',
        'description' => 'For download jobs',
        'workers' => 10,
        'capacity' => 3,
        'timeout' => 600,
        'jobs' => [
            \Nraa\Jobs\CS2\DownloadCS2DemoJob::class,
        ],
        'supervisor_group' => 'notlaravel-worker-download',
        'priority' => 7, // High priority (unblocks parsing)
        'worker_config_provider' => null,
    ],

    /**
     * General Pool
     * 
     * Default pool for all jobs not assigned to specific pools.
     * Handles quick operations, API calls, notifications, etc.
     */
    'general' => [
        'name' => 'General Pool',
        'description' => 'Default pool for all other jobs',
        'workers' => 10,
        'capacity' => 3, // High capacity for quick jobs
        'timeout' => 300, // 5 minutes
        'jobs' => [], // Empty = accepts all jobs not in other pools
        'supervisor_group' => 'notlaravel-worker-general',
        'priority' => 3, // Lower priority
        'worker_config_provider' => null, // No special config needed
    ],
];
