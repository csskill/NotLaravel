<?php

return [
    'demo' => [
        'driver' => 's3',
        'endpoint' => env('MINIO_ENDPOINT', 'http://127.0.0.1:9000'),
        'version' => 'latest',
        'region' => 'us-east-1',
        'use_path_style_endpoint' => true,
        'bucket' => env('MINIO_BUCKET', 'demo'),
        'credentials' => [
            'key' => env('MINIO_KEY'),
            'secret' => env('MINIO_SECRET')
        ],
    ],
    'local' => [
        'driver' => 'local',
        'root' => 'files'
    ]
    /* Examples of other adapters that can be configured
    'ftp' => [
        'driver' => 'ftp',
        'host' => 'hostname', // required
        'root' => '/root/path/', // required
        'username' => 'username', // required
        'password' => 'password', // required
        'port' => 21,
        'ssl' => false,
        'timeout' => 90,
        'utf8' => false,
        'passive' => true,
        'transferMode' => FTP_BINARY,
        'systemType' => null, // 'windows' or 'unix'
        'ignorePassiveAddress' => null, // true or false
        'timestampsOnUnixListingsEnabled' => false, // true or false
        'recurseManually' => true // true 
    ],
    'sftp' => [
        'driver' => 'sftp',
        'host' => 'localhost', // host (required)
        'username' => 'foo', // username (required)
        'password' => 'pass', // password (optional, default: null) set to null if privateKey is used
        'privateKey' => '/path/to/my/private_key', // private key (optional, default: null) can be used instead of password, set to null if password is set
        'passphrase' => 'my-super-secret-passphrase-for-the-private-key', // passphrase (optional, default: null), set to null if privateKey is not used or has no passphrase
        'port' => 2222, // port (optional, default: 22)
        'useAgent' => true, // use agent (optional, default: false)
        'timeout' => 30, // timeout (optional, default: 10)
        'maxTries' => 10, // max tries (optional, default: 4)
        'hostFingerprint' => 'fingerprint-string', // host fingerprint (optional, default: null),
        'connectivityChecker' => null, // connectivity checker (must be an implementation of 'League\Flysystem\PhpseclibV2\ConnectivityChecker' to check if a connection can be established (optional, omit if you don't need some special handling for setting reliable connections)
        'root' => '/upload', // root path (required)
        'permissions' => [ 
            'file' => [
                'public' => 0640,
                'private' => 0604,
            ],
            'dir' => [
                'public' => 0740,
                'private' => 0700,
            ]        
        ]
    ],
    'google' => [
        'driver' => 'google',
        'project_id' => env('GCS_PROJECT_ID'),
        'key_file_path' => env('GCS_KEY_FILE_PATH'), // path to service account json file
        'bucket' => env('GCS_BUCKET'),
        'prefix' => env('GCS_PREFIX', ''), // optional prefix for all files stored in the bucket
    ],        
    */
];
