<?php

declare(strict_types=1);

$testCacheDirectory = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
    .DIRECTORY_SEPARATOR
    .'konji-shop-testing-'.getmypid();

if (! is_dir($testCacheDirectory) && ! mkdir($testCacheDirectory, 0777, true) && ! is_dir($testCacheDirectory)) {
    fwrite(STDERR, "Unable to create isolated test cache directory [{$testCacheDirectory}].".PHP_EOL);

    exit(1);
}

$testEnvironment = [
    'APP_ENV' => 'testing',
    'APP_MAINTENANCE_DRIVER' => 'file',
    'APP_CONFIG_CACHE' => $testCacheDirectory.'/config.php',
    'APP_EVENTS_CACHE' => $testCacheDirectory.'/events.php',
    'APP_PACKAGES_CACHE' => $testCacheDirectory.'/packages.php',
    'APP_ROUTES_CACHE' => $testCacheDirectory.'/routes.php',
    'APP_SERVICES_CACHE' => $testCacheDirectory.'/services.php',
    'BCRYPT_ROUNDS' => '4',
    'BROADCAST_CONNECTION' => 'null',
    'CACHE_STORE' => 'array',
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => ':memory:',
    'DB_URL' => '',
    'MAIL_MAILER' => 'array',
    'QUEUE_CONNECTION' => 'sync',
    'SESSION_DRIVER' => 'array',
    'PULSE_ENABLED' => 'false',
    'TELESCOPE_ENABLED' => 'false',
    'NIGHTWATCH_ENABLED' => 'false',
];

// Seed the isolated environment before Composer or Laravel are loaded. The
// dedicated cache paths are essential: an existing bootstrap/cache/config.php
// may contain the development MySQL connection and otherwise override every
// database environment value below.
foreach ($testEnvironment as $key => $value) {
    putenv("{$key}={$value}");
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}

$readEnvironment = static function (string $key): ?string {
    $value = $_SERVER[$key] ?? $_ENV[$key] ?? getenv($key);

    return is_string($value) ? $value : null;
};

$expectedEnvironment = [
    'APP_ENV' => 'testing',
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => ':memory:',
];

$violations = [];

foreach ($expectedEnvironment as $key => $expectedValue) {
    $actualValue = $readEnvironment($key);

    if ($actualValue !== $expectedValue) {
        $violations[] = sprintf(
            '%s must be [%s], received [%s]',
            $key,
            $expectedValue,
            $actualValue ?? 'unset',
        );
    }
}

if ($violations !== []) {
    fwrite(
        STDERR,
        'Unsafe test database configuration detected. '
        .'Tests were stopped before Laravel booted.'
        .PHP_EOL
        .implode(PHP_EOL, $violations)
        .PHP_EOL,
    );

    exit(1);
}

require dirname(__DIR__).'/vendor/autoload.php';
