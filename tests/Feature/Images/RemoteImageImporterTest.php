<?php

use App\Services\Images\RemoteImageImporter;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

it('resizes oversized-dimension remote images instead of skipping them', function (): void {
    Storage::fake('public');

    $originalContents = generatedTestImageContents(4200, 3200, 'image/jpeg');
    $originalSize = getimagesizefromstring($originalContents);

    expect($originalSize)->not->toBeFalse()
        ->and(max($originalSize[0], $originalSize[1]))->toBeGreaterThan(2200);

    Http::fake([
        'https://www.reh4mat.com/uploads/*' => Http::response($originalContents, 200, [
            'Content-Type' => 'image/jpeg',
        ]),
    ]);

    $result = app(RemoteImageImporter::class)->import(
        'https://www.reh4mat.com/uploads/2026/06/test-large.jpg',
        'products/reh4mat/test',
        'public',
        ['reh4mat.com'],
    );

    Storage::disk('public')->assertExists($result['path']);

    $storedContents = Storage::disk('public')->get($result['path']);
    $storedSize = getimagesizefromstring($storedContents);

    expect($storedSize)->not->toBeFalse()
        ->and(max($storedSize[0], $storedSize[1]))->toBeLessThanOrEqual(2200)
        ->and(strlen($storedContents))->toBeLessThan(strlen($originalContents))
        ->and($result['file_size'])->toBe(strlen($storedContents))
        ->and($result['mime_type'])->toBe('image/jpeg');
});

it('keeps already acceptable remote images unchanged', function (): void {
    Storage::fake('public');

    $originalContents = generatedTestImageContents(1200, 800, 'image/png');
    $originalSha256 = hash('sha256', $originalContents);

    Http::fake([
        'https://www.reh4mat.com/uploads/*' => Http::response($originalContents, 200, [
            'Content-Type' => 'image/png',
        ]),
    ]);

    $result = app(RemoteImageImporter::class)->import(
        'https://www.reh4mat.com/uploads/2026/06/test-small.png',
        'products/reh4mat/test',
        'public',
        ['reh4mat.com'],
    );

    Storage::disk('public')->assertExists($result['path']);

    $storedContents = Storage::disk('public')->get($result['path']);

    expect($storedContents)->toBe($originalContents)
        ->and($result['sha256'])->toBe($originalSha256)
        ->and($result['mime_type'])->toBe('image/png');
});

function generatedTestImageContents(int $width, int $height, string $mimeType): string
{
    $image = imagecreatetruecolor($width, $height);

    if ($image === false) {
        throw new RuntimeException('Unable to create GD test image.');
    }

    try {
        $background = imagecolorallocate($image, 245, 245, 245);
        imagefill($image, 0, 0, $background);

        for ($i = 0; $i < 36; $i++) {
            $color = imagecolorallocate(
                $image,
                ($i * 31) % 255,
                ($i * 57) % 255,
                ($i * 83) % 255,
            );

            imageline(
                $image,
                0,
                (int) round(($height - 1) * ($i / 35)),
                $width - 1,
                (int) round(($height - 1) * ((35 - $i) / 35)),
                $color,
            );

            imagefilledellipse(
                $image,
                (int) round(($width / 36) * ($i + 1)),
                (int) round(($height / 36) * ($i + 1)),
                max(20, (int) round($width / 14)),
                max(20, (int) round($height / 14)),
                $color,
            );
        }

        ob_start();

        if ($mimeType === 'image/png') {
            imagepng($image, null, 6);
        } else {
            imagejpeg($image, null, 92);
        }

        $contents = ob_get_clean();

        if (! is_string($contents)) {
            throw new RuntimeException('Failed to render GD test image.');
        }

        return $contents;
    } finally {
        imagedestroy($image);
    }
}

it('skips images whose decoded dimensions would exceed the safe memory budget', function (): void {
    Storage::fake('public');

    $oversizedPngHeader = "\x89PNG\r\n\x1a\n"
        .pack('N', 13)
        .'IHDR'
        .pack('NNCCCCC', 12000, 12000, 8, 2, 0, 0, 0)
        .pack('N', 0)
        .pack('N', 0)
        .'IEND'
        .pack('N', 0);

    Http::fake([
        'https://antar.net/wp-content/uploads/*' => Http::response($oversizedPngHeader, 200, [
            'Content-Type' => 'image/png',
        ]),
    ]);

    app(RemoteImageImporter::class)->import(
        'https://antar.net/wp-content/uploads/2026/07/huge-image.png',
        'products/antar/test',
        'public',
        ['antar.net'],
    );
})->throws(RuntimeException::class, 'Image dimensions too large to process safely');
