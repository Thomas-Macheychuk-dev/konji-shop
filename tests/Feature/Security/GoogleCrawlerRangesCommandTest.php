<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;

it('downloads and atomically stores unique Google crawler CIDR ranges', function (): void {
    $path = storage_path('framework/testing/google-ranges-command.json');

    @unlink($path);

    config([
        'traffic_protection.google.ranges_file' => $path,
        'traffic_protection.google.sources' => [
            'https://example.test/common.json',
            'https://example.test/special.json',
        ],
    ]);

    Http::fake([
        'https://example.test/common.json' => Http::response([
            'creationTime' => '2026-08-21T14:45:42.000000',
            'prefixes' => [
                ['ipv4Prefix' => '66.249.64.0/19'],
                ['ipv6Prefix' => '2001:4860:4801::/48'],
            ],
        ]),
        'https://example.test/special.json' => Http::response([
            'creationTime' => '2026-08-21T14:45:42.000000',
            'prefixes' => [
                ['ipv4Prefix' => '66.249.64.0/19'],
                ['ipv4Prefix' => '192.178.0.0/16'],
            ],
        ]),
    ]);

    $this->artisan('traffic:refresh-google-crawler-ranges')
        ->expectsOutputToContain('Saved 3 unique Google CIDR prefixes')
        ->assertSuccessful();

    $saved = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

    expect($saved['prefixes'])->toBe([
        '192.178.0.0/16',
        '2001:4860:4801::/48',
        '66.249.64.0/19',
    ]);

    @unlink($path);
});
