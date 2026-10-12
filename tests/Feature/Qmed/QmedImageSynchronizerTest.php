<?php

declare(strict_types=1);

use App\Models\Product;
use App\Services\Images\RemoteImageImporter;
use App\Services\Qmed\QmedDraftImporter;
use App\Services\Qmed\QmedImageSynchronizer;
use App\Services\Qmed\QmedImportPreflight;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Qmed\Support\QmedDatasetFactory;

uses(RefreshDatabase::class);

it('normalizes Joomla fragments and deduplicates identical downloaded bytes', function (): void {
    Storage::fake('public');
    $dataset = QmedDatasetFactory::make();
    $dataset['products'][0]['images'] = [
        ['url' => 'https://qmedinfo.pl/images/a.jpg#joomlaImage://local-images/a.jpg?width=600&height=808', 'role' => 'main'],
        ['url' => 'https://qmedinfo.pl/images/a-copy.jpg', 'role' => 'gallery'],
        ['url' => 'https://qmedinfo.pl/images/unique.jpg', 'role' => 'gallery'],
    ];
    app(QmedDraftImporter::class)->import($dataset, 156, 156);

    $downloader = Mockery::mock(RemoteImageImporter::class);
    $downloader->shouldReceive('import')->times(3)->andReturnUsing(
        function (string $url, string $directory, string $disk): array {
            expect($url)->not->toContain('#');
            $contents = str_contains($url, 'unique.jpg') ? 'different-image' : 'same-image';
            $sha = hash('sha256', $contents);
            $path = $directory.'/'.$sha.'.jpg';
            Storage::disk($disk)->put($path, $contents);

            return [
                'disk' => $disk,
                'path' => $path,
                'source_url' => $url,
                'mime_type' => 'image/jpeg',
                'file_size' => strlen($contents),
                'sha256' => $sha,
            ];
        }
    );
    $sync = new QmedImageSynchronizer(app(QmedImportPreflight::class), $downloader);
    expect($sync->inspect($dataset, 0, 1)['safe'])->toBeTrue();
    expect($sync->sync($dataset, 0, 1))->toMatchArray(['created_products' => 1, 'created_images' => 2]);
    expect($sync->inspect($dataset, 0, 1))->toMatchArray(['safe' => true, 'unchanged' => 1, 'products_to_import' => 0]);
    expect($sync->sync($dataset, 0, 1))->toMatchArray(['created_products' => 0, 'unchanged_products' => 1]);

    $rows = Product::query()
        ->where('external_source', 'qmed')
        ->where('external_id', $dataset['products'][0]['external_id'])
        ->firstOrFail()
        ->images()
        ->orderBy('sort_order')
        ->get();
    expect($rows)->toHaveCount(2)
        ->and($rows[0]->source_url)->toBe('https://qmedinfo.pl/images/a.jpg')
        ->and($rows[0]->is_main)->toBeTrue()
        ->and($rows[1]->sort_order)->toBe(1)
        ->and($rows[1]->is_main)->toBeFalse();
});

it('rejects unexpected URL fragments without downloading images', function (): void {
    $dataset = QmedDatasetFactory::make();
    $dataset['products'][0]['images'][0]['url'] .= '#unexpected';
    app(QmedDraftImporter::class)->import($dataset, 156, 156);
    $downloader = Mockery::mock(RemoteImageImporter::class);
    $downloader->shouldNotReceive('import');
    $sync = new QmedImageSynchronizer(app(QmedImportPreflight::class), $downloader);

    expect($sync->inspect($dataset, 0, 1)['safe'])->toBeFalse();
    expect(fn () => $sync->sync($dataset, 0, 1))->toThrow(RuntimeException::class);
});

it('does not attach partial image rows when a download fails', function (): void {
    Storage::fake('public');
    $dataset = QmedDatasetFactory::make();
    $dataset['products'][0]['images'][] = ['url' => 'https://qmedinfo.pl/images/failure.jpg', 'role' => 'gallery'];
    app(QmedDraftImporter::class)->import($dataset, 156, 156);
    $downloader = Mockery::mock(RemoteImageImporter::class);
    $downloader->shouldReceive('import')->once()->andReturn([
        'disk' => 'public', 'path' => 'products/qmed/test/first.jpg',
        'source_url' => $dataset['products'][0]['images'][0]['url'],
        'mime_type' => 'image/jpeg', 'file_size' => 5, 'sha256' => hash('sha256', 'first'),
    ]);
    $downloader->shouldReceive('import')->once()->andThrow(new RuntimeException('Download failed'));
    $sync = new QmedImageSynchronizer(app(QmedImportPreflight::class), $downloader);

    expect(fn () => $sync->sync($dataset, 0, 1))->toThrow(RuntimeException::class, 'Download failed');
    $product = Product::where('external_source', 'qmed')->firstOrFail();
    expect($product->images()->count())->toBe(0);
});
