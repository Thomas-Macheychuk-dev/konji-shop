<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;

it('downloads and atomically stores unique Google crawler CIDR ranges', function (): void {
    $path = storage_path('framework/testing/google-ranges-command.json');

    @unlink($path);

    config([
        'traffic_protection.verified_crawlers.google.ranges_file' => $path,
        'traffic_protection.verified_crawlers.google.sources' => [
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

    $this->artisan('traffic:refresh-crawler-ranges', ['crawler' => ['google']])
        ->expectsOutputToContain('Saved 3 unique google CIDR prefixes')
        ->assertSuccessful();

    $saved = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

    expect($saved['prefixes'])->toBe([
        '192.178.0.0/16',
        '2001:4860:4801::/48',
        '66.249.64.0/19',
    ]);

    @unlink($path);
});

it('keeps refreshing other crawlers when one crawler feed fails', function (): void {
    $googlePath = storage_path('framework/testing/google-ranges-partial.json');
    $bingPath = storage_path('framework/testing/bing-ranges-partial.json');

    @unlink($googlePath);
    @unlink($bingPath);

    config([
        'traffic_protection.verified_crawlers' => [
            'google' => [
                'ranges_file' => $googlePath,
                'sources' => ['https://example.test/google.json'],
                'user_agent_pattern' => '/Googlebot/i',
            ],
            'bing' => [
                'ranges_file' => $bingPath,
                'sources' => ['https://example.test/bing.json'],
                'user_agent_pattern' => '/bingbot/i',
            ],
        ],
        'traffic_protection.crawler_ranges.refresh_attempts' => 1,
    ]);

    Http::fake([
        'https://example.test/google.json' => Http::response([], 503),
        'https://example.test/bing.json' => Http::response([
            'prefixes' => [['ipv4Prefix' => '157.55.39.0/24']],
        ]),
    ]);

    $this->artisan('traffic:refresh-crawler-ranges')
        ->expectsOutputToContain('Saved 1 unique bing CIDR prefixes')
        ->assertFailed();

    expect(is_file($googlePath))->toBeFalse()
        ->and(json_decode((string) file_get_contents($bingPath), true)['prefixes'])->toBe(['157.55.39.0/24']);

    @unlink($bingPath);
});
