<?php

use App\Services\Storage\PublicMediaDeliveryReadiness;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    config()->set('filesystems.disks.public-s3.driver', 's3');
    config()->set('filesystems.disks.public-s3.bucket', 'konji-production-media');
    config()->set('filesystems.disks.public-s3.url', 'https://d111111abcdef8.cloudfront.net');
    config()->set('filesystems.disks.public-s3.visibility', 'private');
    config()->set('filesystems.disks.public-s3.options.CacheControl', 'public,max-age=86400,s-maxage=604800');

    Storage::fake('public-s3');
});

it('accepts a private S3 target with an HTTPS CDN URL and positive cache TTL', function (): void {
    $readiness = app(PublicMediaDeliveryReadiness::class);

    expect($readiness->isConfigured())->toBeTrue()
        ->and(collect($readiness->checks())->where('status', '!=', 'ready'))->toHaveCount(0);
});

it('rejects direct S3 URLs as the public product-media delivery URL', function (): void {
    config()->set(
        'filesystems.disks.public-s3.url',
        'https://konji-production-media.s3.eu-central-1.amazonaws.com'
    );

    $readiness = app(PublicMediaDeliveryReadiness::class);

    expect($readiness->isConfigured())->toBeFalse()
        ->and(collect($readiness->checks())->firstWhere('name', 'HTTPS CDN URL')['status'])->toBe('missing');
});

it('probes the private S3 write path and reads the same object through the CDN URL', function (): void {
    Http::fake(function (Request $request) {
        $path = ltrim((string) parse_url($request->url(), PHP_URL_PATH), '/');

        if (! Storage::disk('public-s3')->exists($path)) {
            return Http::response('missing', 404);
        }

        return Http::response(Storage::disk('public-s3')->get($path), 200, [
            'Content-Type' => 'text/plain',
        ]);
    });

    expect(Artisan::call('shop:check-public-media', ['--probe' => true]))->toBe(0)
        ->and(Artisan::output())->toContain('Private S3 write and CloudFront/CDN read probe succeeded.')
        ->and(Storage::disk('public-s3')->allFiles('products/__readiness'))->toBe([]);

    Http::assertSent(fn (Request $request): bool => str_starts_with(
        $request->url(),
        'https://d111111abcdef8.cloudfront.net/products/__readiness/'
    ));
});

it('fails the live probe when CloudFront cannot read the private S3 object and still cleans up', function (): void {
    Http::fake([
        'https://d111111abcdef8.cloudfront.net/*' => Http::response('Forbidden', 403),
    ]);

    expect(Artisan::call('shop:check-public-media', ['--probe' => true]))->toBe(1)
        ->and(Artisan::output())->toContain('CDN probe returned HTTP 403')
        ->and(Storage::disk('public-s3')->allFiles('products/__readiness'))->toBe([]);
});

it('requires a positive cache TTL before the S3 CloudFront cutover', function (): void {
    config()->set('filesystems.disks.public-s3.options.CacheControl', 'no-store');

    expect(app(PublicMediaDeliveryReadiness::class)->isConfigured())->toBeFalse()
        ->and(Artisan::call('shop:check-public-media'))->toBe(1)
        ->and(Artisan::output())->toContain('PUBLIC_FILESYSTEM_CACHE_CONTROL');
});
