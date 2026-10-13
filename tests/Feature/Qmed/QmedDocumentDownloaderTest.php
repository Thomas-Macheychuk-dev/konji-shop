<?php

declare(strict_types=1);

use App\Services\Qmed\QmedDocumentDownloader;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\StreamInterface;

function qmedFakePdf(): string
{
    return "%PDF-1.7\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n";
}

function qmedFakeTransport(int $status, string $mimeType, string $body): ClientInterface
{
    $http = Mockery::mock(ClientInterface::class);
    $http->shouldReceive('request')->once()->andReturnUsing(
        function (string $method, string $url, array $options) use ($status, $mimeType, $body): Response {
            expect($method)->toBe('POST')
                ->and($url)->toBe('https://qmedinfo.pl/index.php')
                ->and($options['allow_redirects'])->toBeFalse()
                ->and($options['verify'])->toBeTrue()
                ->and($options['form_params'])->toMatchArray([
                    'option' => 'com_ajax', 'plugin' => 'jmpattachments',
                    'group' => 'content', 'task' => 'download', 'format' => 'raw',
                ]);
            /** @var StreamInterface $sink */
            $sink = $options['sink'];
            $sink->write($body);

            return new Response($status, ['Content-Type' => $mimeType]);
        }
    );

    return $http;
}

it('retrieves an exact-endpoint PDF and returns verified bytes and metadata', function (): void {
    $bytes = qmedFakePdf();
    $result = (new QmedDocumentDownloader(qmedFakeTransport(200, 'application/pdf', $bytes)))->download('1087');

    expect($result)->toBe([
        'bytes' => $bytes,
        'mime_type' => 'application/pdf',
        'file_size' => strlen($bytes),
        'sha256' => hash('sha256', $bytes),
        'external_id' => '1087',
    ]);
});

it('rejects invalid attachment identifiers before making a request', function (): void {
    $http = Mockery::mock(ClientInterface::class);
    $http->shouldNotReceive('request');
    foreach (['0', '-1', '1x', '1/2', ' 1087', '1087 ', '', str_repeat('9', 20)] as $id) {
        expect(fn () => (new QmedDocumentDownloader($http))->download($id))
            ->toThrow(InvalidArgumentException::class);
    }
});

it('rejects HTTP redirects and unsuccessful responses', function (): void {
    foreach ([301, 302, 403, 500] as $code) {
        expect(fn () => (new QmedDocumentDownloader(
            qmedFakeTransport($code, 'application/pdf', qmedFakePdf())
        ))->download('1087'))->toThrow(RuntimeException::class);
    }
});

it('rejects html responses, broken headers and incomplete PDFs', function (): void {
    foreach ([
        ['text/html', qmedFakePdf()],
        ['application/pdf', '<html>Blocked</html>'],
        ['application/pdf', "%PDF-1.7\nMissing EOF"],
    ] as [$type, $bytes]) {
        expect(fn () => (new QmedDocumentDownloader(
            qmedFakeTransport(200, $type, $bytes)
        ))->download('1087'))->toThrow(RuntimeException::class);
    }
});

it('refuses oversized streamed data before fully writing it', function (): void {
    $http = qmedFakeTransport(200, 'application/pdf', str_repeat('A', QmedDocumentDownloader::MAX_BYTES + 1));

    expect(fn () => (new QmedDocumentDownloader($http))->download('1087'))
        ->toThrow(RuntimeException::class, 'exceeds the maximum');
});

it('propagates transport errors without persisting anything', function (): void {
    $http = Mockery::mock(ClientInterface::class);
    $http->shouldReceive('request')->once()->andThrow(new RuntimeException('network down'));

    expect(fn () => (new QmedDocumentDownloader($http))->download('1087'))
        ->toThrow(RuntimeException::class, 'network down');
});
