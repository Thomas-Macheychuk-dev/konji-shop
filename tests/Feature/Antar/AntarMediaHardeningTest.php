<?php

use App\Models\Product;
use App\Services\Antar\AntarProductImporter;

it('does not expose unresolved Antar documents as pending storefront downloads', function (): void {
    $importer = app(AntarProductImporter::class);
    $reflection = new ReflectionClass($importer);
    $method = $reflection->getMethod('documentsSection');

    $html = $method->invoke($importer, [
        'documents' => [
            [
                'label' => 'Broken supplier instruction',
                'local_url' => null,
            ],
            [
                'label' => 'Valid instruction',
                'local_url' => 'http://localhost/storage/products/antar/example/documents/instruction.pdf',
            ],
        ],
    ]);

    expect($html)
        ->toContain('Valid instruction')
        ->toContain('/storage/products/antar/example/documents/instruction.pdf')
        ->not->toContain('Broken supplier instruction')
        ->not->toContain('antar-pending-document');

    expect($method->invoke($importer, [
        'documents' => [[
            'label' => 'Broken supplier instruction',
            'local_url' => null,
        ]],
    ]))->toBeNull();
});

it('preserves stale Antar image rows whenever any source image remains unresolved', function (): void {
    $importer = app(AntarProductImporter::class);
    $reflection = new ReflectionClass($importer);
    $method = $reflection->getMethod('shouldPruneStaleImages');

    expect($method->invoke($importer, false))->toBeTrue()
        ->and($method->invoke($importer, true))->toBeFalse();
});

it('maps reviewed oversized rescues only for the exact approved Antar product and source URL pairs', function (): void {
    $importer = app(AntarProductImporter::class);
    $reflection = new ReflectionClass($importer);
    $method = $reflection->getMethod('reviewedOversizedImageRescueRelativePath');

    $product = (new Product)->forceFill(['external_id' => 'lawka-wannowa-obrotowa-at51053']);

    expect($method->invoke(
        $importer,
        $product,
        'https://antar.net/wp-content/uploads/2025/10/AT51053-1.jpg',
    ))->toBe('import-data/antar/media-rescue/AT51053-1.jpg');

    expect($method->invoke(
        $importer,
        $product,
        'https://antar.net/wp-content/uploads/2025/10/not-approved.jpg',
    ))->toBeNull();

    $wrongProduct = (new Product)->forceFill(['external_id' => 'different-product']);

    expect($method->invoke(
        $importer,
        $wrongProduct,
        'https://antar.net/wp-content/uploads/2025/10/AT51053-1.jpg',
    ))->toBeNull();
});

it('keeps all reviewed Antar rescue assets within the shared importer storage and dimension boundaries', function (): void {
    $files = [
        'AT51053-1.jpg',
        'AT51053-2.jpg',
        'AT51053-3.jpg',
        'AT51125-4.jpg',
        'AT51125-unnumbered.jpg',
        'AT51049-ZESTAW.jpg',
    ];

    foreach ($files as $filename) {
        $path = resource_path('import-data/antar/media-rescue/'.$filename);

        expect(is_file($path))->toBeTrue("Missing reviewed rescue asset: {$filename}")
            ->and(filesize($path))->toBeGreaterThan(0)
            ->and(filesize($path))->toBeLessThanOrEqual(10 * 1024 * 1024);

        $size = getimagesize($path);

        expect($size)->toBeArray()
            ->and((int) $size[0])->toBeGreaterThan(0)
            ->and((int) $size[1])->toBeGreaterThan(0)
            ->and(max((int) $size[0], (int) $size[1]))->toBeLessThanOrEqual(2200)
            ->and(strtolower((string) ($size['mime'] ?? '')))->toBe('image/jpeg');
    }
});
