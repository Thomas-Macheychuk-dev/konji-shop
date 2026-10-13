<?php

declare(strict_types=1);

namespace App\Services\Qmed;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Stream;
use GuzzleHttp\Psr7\StreamDecoratorTrait;
use InvalidArgumentException;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Throwable;

final class QmedDocumentDownloader
{
    public const MAX_BYTES = 20 * 1024 * 1024;

    private const ENDPOINT = 'https://qmedinfo.pl/index.php';

    public function __construct(private readonly ?ClientInterface $http = null) {}

    /**
     * Download PDF bytes into a bounded temporary stream, without modifying the database.
     * The caller owns the verified bytes and their subsequent private-storage lifecycle.
     *
     * @return array{bytes: string, mime_type: string, file_size: int, sha256: string, external_id: string}
     */
    public function download(string $attachmentId): array
    {
        if (preg_match('/^[1-9][0-9]{0,18}$/D', $attachmentId) !== 1) {
            throw new InvalidArgumentException('Qmed attachment ID must be a positive decimal integer.');
        }

        $resource = fopen('php://temp/maxmemory:2097152', 'w+b');
        if ($resource === false) {
            throw new RuntimeException('Could not allocate Qmed document download stream.');
        }

        $boundedStream = new class(new Stream($resource)) implements StreamInterface {
            use StreamDecoratorTrait;

            private int $written = 0;

            public function __construct(StreamInterface $stream)
            {
                $this->stream = $stream;
            }

            public function write(string $string): int
            {
                if (strlen($string) > QmedDocumentDownloader::MAX_BYTES - $this->written) {
                    throw new RuntimeException('Qmed PDF exceeds the maximum permitted size.');
                }

                $count = $this->stream->write($string);
                $this->written += $count;

                return $count;
            }
        };

        try {
            $response = ($this->http ?? new Client())->request('POST', self::ENDPOINT, [
                'allow_redirects' => false,
                'connect_timeout' => 10,
                'timeout' => 40,
                'http_errors' => false,
                'verify' => true,
                'sink' => $boundedStream,
                'headers' => ['Accept' => 'application/pdf'],
                'form_params' => [
                    'option' => 'com_ajax',
                    'plugin' => 'jmpattachments',
                    'group' => 'content',
                    'task' => 'download',
                    'format' => 'raw',
                    'id' => $attachmentId,
                ],
            ]);

            if ($response->getStatusCode() !== 200) {
                throw new RuntimeException('Qmed document endpoint returned unexpected HTTP status.');
            }

            $contentType = strtolower(trim(explode(';', $response->getHeaderLine('Content-Type'))[0]));
            if ($contentType !== 'application/pdf') {
                throw new RuntimeException('Qmed attachment is not served as a PDF.');
            }

            $boundedStream->rewind();
            $bytes = $boundedStream->getContents();
            $size = strlen($bytes);
            if ($size < 16 || $size > self::MAX_BYTES || ! str_starts_with($bytes, '%PDF-')) {
                throw new RuntimeException('Invalid Qmed PDF signature or file size.');
            }

            // Minimal structural check; this is not a substitute for a full PDF parser.
            if (preg_match('/%%EOF\s*$/D', $bytes) !== 1) {
                throw new RuntimeException('Qmed PDF is missing its end-of-file marker.');
            }

            return [
                'bytes' => $bytes,
                'mime_type' => 'application/pdf',
                'file_size' => $size,
                'sha256' => hash('sha256', $bytes),
                'external_id' => $attachmentId,
            ];
        } catch (Throwable $exception) {
            throw $exception;
        } finally {
            $boundedStream->close();
        }
    }
}
