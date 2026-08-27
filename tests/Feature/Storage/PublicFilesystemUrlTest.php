<?php

use App\Support\Storage\PublicFilesystemUrl;
use Illuminate\Support\Facades\Storage;

it('keeps legacy local public URLs stable during local development', function (): void {
    config()->set('filesystems.disks.public.driver', 'local');

    expect(PublicFilesystemUrl::url('products/example/image.webp'))
        ->toBe('/storage/products/example/image.webp')
        ->and(PublicFilesystemUrl::path('/storage/products/example/image.webp'))
        ->toBe('products/example/image.webp');
});

it('resolves object-storage public URLs back to their catalogue paths', function (): void {
    config()->set('filesystems.disks.public', [
        'driver' => 'local',
        'root' => storage_path('framework/testing/disks/public-url-test'),
        'url' => 'https://cdn.example.test',
        'visibility' => 'public',
        'throw' => false,
        'report' => false,
    ]);
    Storage::forgetDisk('public');

    expect(PublicFilesystemUrl::path('https://cdn.example.test/products/example/manual.pdf'))
        ->toBe('products/example/manual.pdf');
});
