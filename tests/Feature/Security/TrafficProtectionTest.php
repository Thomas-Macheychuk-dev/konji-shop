<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

function writeGoogleCrawlerRanges(array $prefixes): string
{
    $path = storage_path('framework/testing/google-crawler-ranges.json');

    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0755, true);
    }

    file_put_contents($path, json_encode([
        'refreshed_at' => now()->toIso8601String(),
        'prefixes' => $prefixes,
    ], JSON_THROW_ON_ERROR));

    return $path;
}

beforeEach(function (): void {
    Route::middleware('web')->get(
        '/__traffic-protection-test/ok',
        static fn () => response('ok'),
    );

    config([
        'traffic_protection.enabled' => true,
        'traffic_protection.turnstile.site_key' => 'test-site-key',
        'traffic_protection.turnstile.secret_key' => 'test-secret-key',
        'traffic_protection.turnstile.allowed_hostnames' => ['localhost'],
        'traffic_protection.google.ranges_file' => writeGoogleCrawlerRanges([
            '66.249.64.0/19',
            '2001:4860:4801::/48',
        ]),
    ]);
});

afterEach(function (): void {
    @unlink(storage_path('framework/testing/google-crawler-ranges.json'));
});

it('redirects an unverified normal browser to the human challenge', function (): void {
    $response = $this
        ->withHeader('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/151 Safari/537.36')
        ->withHeader('Accept', 'text/html')
        ->get('/privacy-policy');

    $response->assertRedirect(route('traffic.challenge', [
        'return_to' => '/privacy-policy',
    ]));
});

it('blocks known crawler and command line user agents', function (string $userAgent): void {
    $this
        ->withHeader('User-Agent', $userAgent)
        ->withHeader('Accept', 'text/html')
        ->get('/privacy-policy')
        ->assertForbidden();
})->with([
    'Ahrefs' => 'Mozilla/5.0 (compatible; AhrefsBot/7.0; +http://ahrefs.com/robot/)',
    'GPTBot' => 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; GPTBot/1.1',
    'curl' => 'curl/8.12.1',
    'Python requests' => 'python-requests/2.32.3',
]);

it('blocks a spoofed Googlebot outside official Google ranges', function (): void {
    $this
        ->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
        ->withHeader('User-Agent', 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)')
        ->withHeader('Accept', 'text/html')
        ->get('/privacy-policy')
        ->assertForbidden();
});

it('allows a Google crawler only when its IP is in an official range', function (): void {
    $this
        ->withServerVariables(['REMOTE_ADDR' => '66.249.66.1'])
        ->withHeader('User-Agent', 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)')
        ->withHeader('Accept', 'text/html')
        ->get('/__traffic-protection-test/ok')
        ->assertOk()
        ->assertSeeText('ok');
});

it('validates Turnstile server side and issues the human verification cookie', function (): void {
    Http::fake([
        'https://challenges.cloudflare.com/turnstile/v0/siteverify' => Http::response([
            'success' => true,
            'hostname' => 'localhost',
            'action' => 'human-traffic',
        ]),
    ]);

    $response = $this
        ->withServerVariables(['REMOTE_ADDR' => '198.51.100.25'])
        ->withHeader('User-Agent', 'Mozilla/5.0 Chrome/151 Safari/537.36')
        ->post('/human-check', [
            'cf-turnstile-response' => 'valid-test-token',
            'return_to' => '/privacy-policy',
        ]);

    $response
        ->assertRedirect('/privacy-policy')
        ->assertCookie('konji_human_verified');

    Http::assertSent(fn ($request): bool => $request->url() === 'https://challenges.cloudflare.com/turnstile/v0/siteverify'
        && $request['secret'] === 'test-secret-key'
        && $request['response'] === 'valid-test-token'
    );
});

it('rejects a Turnstile result with the wrong hostname', function (): void {
    Http::fake([
        'https://challenges.cloudflare.com/turnstile/v0/siteverify' => Http::response([
            'success' => true,
            'hostname' => 'attacker.example',
            'action' => 'human-traffic',
        ]),
    ]);

    $this
        ->withHeader('User-Agent', 'Mozilla/5.0 Chrome/151 Safari/537.36')
        ->from('/human-check?return_to=%2Fprivacy-policy')
        ->post('/human-check', [
            'cf-turnstile-response' => 'wrong-host-token',
            'return_to' => '/privacy-policy',
        ])
        ->assertRedirect('/human-check?return_to=%2Fprivacy-policy')
        ->assertSessionHasErrors('human_verification');
});

it('keeps Paynow notifications and robots exempt from the human challenge', function (): void {
    $this
        ->withHeader('User-Agent', 'curl/8.12.1')
        ->get('/robots.txt')
        ->assertOk()
        ->assertSee('User-agent: Googlebot')
        ->assertSee("User-agent: *\nDisallow: /", false);

    $response = $this
        ->withHeader('User-Agent', 'curl/8.12.1')
        ->postJson('/payments/paynow/notifications', []);

    expect($response->status())->not->toBe(403);
});
