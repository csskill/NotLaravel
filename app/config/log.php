<?php
/*
* Log Levels: emergency, alert, critical, error, warning, notice, info, debug
*/

return [
    'file' => [
        'driver' => 'file',
        'path' => '/logs/info.log', // path is relative to the storagePath set in the application
        'level' => 'info',
        'backtrace' => false
    ],
    'database' => [
        'driver' => 'mongodb',
        'table' => 'logs',
        'level' => 'info',
        'backtrace' => true,
        'backtraceLevels' => 2,
    ],
    'job_manager' => [
        'driver' => 'file',
        'path' => '/logs/job-manager.log',
        'level' => 'info',
        'backtrace' => false
    ]
];
