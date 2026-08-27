<?php

$publicLocalDisk = [
    'driver' => 'local',
    'root' => storage_path('app/public'),
    'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
    'visibility' => 'public',
    'throw' => false,
    'report' => false,
];

$publicS3Disk = [
    'driver' => 's3',
    'key' => env('AWS_ACCESS_KEY_ID'),
    'secret' => env('AWS_SECRET_ACCESS_KEY'),
    'region' => env('AWS_DEFAULT_REGION'),
    'bucket' => env('PUBLIC_FILESYSTEM_BUCKET', env('AWS_BUCKET')),
    'url' => env('PUBLIC_FILESYSTEM_URL'),
    'endpoint' => env('AWS_ENDPOINT'),
    'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
    'visibility' => env('PUBLIC_FILESYSTEM_VISIBILITY', 'private'),
    'throw' => env('PUBLIC_FILESYSTEM_THROW', false),
    'report' => env('PUBLIC_FILESYSTEM_REPORT', false),
];

$publicDisk = env('PUBLIC_FILESYSTEM_DRIVER', 'local') === 's3'
    ? $publicS3Disk
    : $publicLocalDisk;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        // Keep the logical disk name `public` stable in the database and
        // importers. Local development stores it under storage/app/public;
        // production can switch the same logical disk to private S3 behind
        // CloudFront by setting PUBLIC_FILESYSTEM_DRIVER=s3.
        'public' => $publicDisk,

        // Explicit migration endpoints allow the catalogue to be copied from
        // the legacy local public volume to S3 while the application remains
        // configured against either implementation of the logical `public` disk.
        'public-local' => $publicLocalDisk,
        'public-s3' => $publicS3Disk,

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
