<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\ProductDocument;
use App\Services\Qmed\QmedDocumentDownloader;
use App\Services\Qmed\QmedDocumentSynchronizer;
use App\Services\Qmed\QmedDraftImporter;
use App\Services\Qmed\QmedImportPreflight;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Qmed\Support\QmedDatasetFactory;

uses(RefreshDatabase::class);

function qmedDocumentFixture(): array
{
    $dataset = QmedDatasetFactory::make();
    $dataset['products'][0]['attachments'] = [[
        'label' => 'Instrukcja używania',
        'source_form_action' => 'https://qmedinfo.pl/index.php',
        'request_method' => 'POST',
        'request_fields' => [
            'option' => 'com_ajax', 'plugin' => 'jmpattachments',
            'group' => 'content', 'task' => 'download', 'format' => 'raw', 'id' => '1087',
        ],
        'download_url' => null,
        'downloaded' => false,
    ]];
    app(QmedDraftImporter::class)->import($dataset, 156, 156);
    return $dataset;
}

function qmedDocumentSyncWithResponses(array $responses): QmedDocumentSynchronizer
{
    $handler = HandlerStack::create(new MockHandler($responses));
    $downloader = new QmedDocumentDownloader(new Client(['handler' => $handler]));
    return new QmedDocumentSynchronizer(app(QmedImportPreflight::class), $downloader);
}

it('validates the complete manifest, including attachments outside the batch', function (): void {
    Storage::fake('local');
    $dataset = qmedDocumentFixture();
    $dataset['products'][3]['attachments'] = $dataset['products'][0]['attachments'];
    $sync = qmedDocumentSyncWithResponses([]);
    expect($sync->inspect($dataset, 0, 1)['safe'])->toBeFalse();
    expect(ProductDocument::count())->toBe(0);
});

it('imports a PDF privately, then skips a repeated batch without downloading', function (): void {
    Storage::fake('local');
    $dataset = qmedDocumentFixture();
    $pdf = "%PDF-1.7\n1 0 obj\n<<>>\nendobj\n%%EOF\n";
    $sync = qmedDocumentSyncWithResponses([
        new Response(200, ['Content-Type' => 'application/pdf'], $pdf),
    ]);
    expect($sync->inspect($dataset, 0, 1))->toMatchArray(['safe' => true, 'to_import' => 1]);
    expect($sync->sync($dataset, 0, 1))->toBe(['created' => 1, 'unchanged' => 0, 'unavailable' => 0]);
    $row = ProductDocument::firstOrFail();
    expect($row->disk)->toBe('local')
        ->and($row->external_id)->toBe('1087')
        ->and($row->sha256)->toBe(hash('sha256', $pdf))
        ->and(Storage::disk('local')->get($row->path))->toBe($pdf);
    expect($sync->inspect($dataset, 0, 1))->toMatchArray(['safe' => true, 'to_import' => 0, 'unchanged' => 1]);
    expect($sync->sync($dataset, 0, 1))->toBe(['created' => 0, 'unchanged' => 1, 'unavailable' => 0]);
});

it('rejects changed document metadata and does not overwrite an imported PDF', function (): void {
    Storage::fake('local');
    $dataset = qmedDocumentFixture();
    $pdf = "%PDF-1.7\n1 0 obj\n<<>>\nendobj\n%%EOF\n";
    $sync = qmedDocumentSyncWithResponses([new Response(200, ['Content-Type' => 'application/pdf'], $pdf)]);
    $sync->sync($dataset, 0, 1);
    $dataset['products'][0]['attachments'][0]['label'] = 'Changed document';
    expect($sync->inspect($dataset, 0, 1)['safe'])->toBeFalse()
        ->and(ProductDocument::count())->toBe(1);
});

it('leaves no document rows or stored files after a rejected PDF', function (): void {
    Storage::fake('local');
    $dataset = qmedDocumentFixture();
    $sync = qmedDocumentSyncWithResponses([new Response(200, ['Content-Type' => 'text/html'], '<html>bad</html>')]);
    expect(fn () => $sync->sync($dataset, 0, 1))->toThrow(RuntimeException::class);
    expect(ProductDocument::count())->toBe(0)
        ->and(Storage::disk('local')->allFiles('product-documents/qmed'))->toBe([]);
});

it('only skips an exact reviewed unavailable attachment and imports the remaining PDF', function (): void {
    Storage::fake('local');
    $dataset = qmedDocumentFixture();
    $dataset['products'][0]['attachments'][] = array_replace_recursive(
        $dataset['products'][0]['attachments'][0],
        ['request_fields' => ['id' => '897'], 'label' => 'Deklaracja zgodności UE'],
    );
    $exceptions = [[
        'external_id' => '1087',
        'source_url' => $dataset['products'][0]['source_url'],
        'label' => 'Instrukcja używania',
        'reason' => 'HTTP_404',
        'review_required' => true,
    ]];
    $pdf = "%PDF-1.7\n1 0 obj\n<<>>\nendobj\n%%EOF\n";
    $sync = qmedDocumentSyncWithResponses([new Response(200, ['Content-Type' => 'application/pdf'], $pdf)]);
    expect($sync->inspect($dataset, 0, 1, $exceptions))->toMatchArray([
        'safe' => true, 'to_import' => 1, 'unavailable' => 1,
    ]);
    expect($sync->sync($dataset, 0, 1, $exceptions))->toBe([
        'created' => 1, 'unchanged' => 0, 'unavailable' => 1,
    ]);
    expect(ProductDocument::pluck('external_id')->all())->toBe(['897']);
    expect($sync->inspect($dataset, 0, 1, $exceptions))->toMatchArray([
        'safe' => true, 'to_import' => 0, 'unchanged' => 1, 'unavailable' => 1,
    ]);
});

it('rejects mismatched, duplicated and unrecognized exception entries without writes', function (): void {
    Storage::fake('local');
    $dataset = qmedDocumentFixture();
    $valid = [
        'external_id' => '1087',
        'source_url' => $dataset['products'][0]['source_url'],
        'label' => 'Instrukcja używania',
        'reason' => 'HTTP_404',
        'review_required' => true,
    ];
    $sync = qmedDocumentSyncWithResponses([]);
    foreach ([
        [array_replace($valid, ['label' => 'Other'])],
        [array_replace($valid, ['source_url' => 'https://qmedinfo.pl/other'])],
        [array_replace($valid, ['external_id' => '123456'])],
        [$valid, $valid],
        [array_replace($valid, ['reason' => 'HTTP_403'])],
    ] as $invalid) {
        expect($sync->inspect($dataset, 0, 1, $invalid)['safe'])->toBeFalse();
        expect(fn () => $sync->sync($dataset, 0, 1, $invalid))->toThrow(RuntimeException::class);
    }
    expect(ProductDocument::count())->toBe(0);
});
