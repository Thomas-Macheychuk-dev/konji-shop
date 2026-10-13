<?php

declare(strict_types=1);

namespace App\Services\Qmed;

use App\Enums\ProductStatus;
use App\Models\Product;
use App\Models\ProductDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final class QmedDocumentSynchronizer
{
    public function __construct(
        private readonly QmedImportPreflight $preflight,
        private readonly QmedDocumentDownloader $downloader,
    ) {}

    /**
     * @param array<string, mixed> $dataset
     * @return array<string, mixed>
     */
    public function inspect(array $dataset, int $offset, int $limit, array $exceptions = []): array
    {
        $base = $this->preflight->inspect($dataset, 156);
        $errors = $base['errors'] ?? [];
        $records = $dataset['products'] ?? [];
        if (! is_array($records) || ! array_is_list($records) || $offset < 0 || $limit < 1 || $limit > 156 || $offset + $limit > 156) {
            $errors[] = 'Invalid Qmed document batch.';
            $records = [];
        }

        // Validate the entire attachment manifest, not only the selected batch.
        $seen = [];
        $allReferences = 0;
        foreach ($records as $index => $record) {
            if (! is_array($record) || ! is_array($record['attachments'] ?? null) || ! array_is_list($record['attachments'])) {
                $errors[] = "Invalid attachment list at {$index}.";
                continue;
            }
            foreach ($record['attachments'] as $attachment) {
                $allReferences++;
                $fields = is_array($attachment) ? ($attachment['request_fields'] ?? null) : null;
                $id = is_array($fields) ? ($fields['id'] ?? null) : null;
                $valid = is_array($attachment)
                    && ($attachment['source_form_action'] ?? null) === 'https://qmedinfo.pl/index.php'
                    && ($attachment['request_method'] ?? null) === 'POST'
                    && ($attachment['download_url'] ?? null) === null
                    && ($attachment['downloaded'] ?? null) === false
                    && is_string($attachment['label'] ?? null)
                    && trim($attachment['label']) !== ''
                    && strlen($attachment['label']) <= 255
                    && is_array($fields)
                    && count($fields) === 6
                    && ($fields['option'] ?? null) === 'com_ajax'
                    && ($fields['plugin'] ?? null) === 'jmpattachments'
                    && ($fields['group'] ?? null) === 'content'
                    && ($fields['task'] ?? null) === 'download'
                    && ($fields['format'] ?? null) === 'raw'
                    && is_string($id)
                    && preg_match('/^[1-9][0-9]{0,18}$/D', $id) === 1;
                if (! $valid || isset($seen[$id])) {
                    $errors[] = "Invalid or duplicate attachment at product index {$index}.";
                    continue;
                }
                $seen[$id] = true;
            }
        }

        // Exception manifest is fail-closed: each entry binds the exact source record,
        // manufacturer attachment ID, label and explicitly reviewed HTTP status.
        $approved = [];
        if (! array_is_list($exceptions)) {
            $errors[] = 'Exception manifest must be a list.';
        } else {
            foreach ($exceptions as $exception) {
                $id = is_array($exception) ? ($exception['external_id'] ?? null) : null;
                if (! is_array($exception) || count($exception) !== 5
                    || ! is_string($id) || preg_match('/^[1-9][0-9]{0,18}$/D', $id) !== 1
                    || isset($approved[$id])
                    || ($exception['reason'] ?? null) !== 'HTTP_404'
                    || ($exception['review_required'] ?? null) !== true
                    || ! is_string($exception['source_url'] ?? null)
                    || ! is_string($exception['label'] ?? null)) {
                    $errors[] = 'Invalid or duplicate approved exception.';
                    continue;
                }
                $matches = [];
                foreach ($records as $record) {
                    if (! is_array($record)) {
                        continue;
                    }
                    foreach (($record['attachments'] ?? []) as $attachment) {
                        if (($attachment['request_fields']['id'] ?? null) === $id) {
                            $matches[] = [$record, $attachment];
                        }
                    }
                }
                if (count($matches) !== 1
                    || ($matches[0][0]['source_url'] ?? null) !== $exception['source_url']
                    || ($matches[0][1]['label'] ?? null) !== $exception['label']) {
                    $errors[] = "Exception {$id} does not match the source manifest.";
                    continue;
                }
                if (ProductDocument::query()->where('external_source', 'qmed')->where('external_id', $id)->exists()) {
                    $errors[] = "Exception {$id} already has an imported document.";
                    continue;
                }
                $approved[$id] = true;
            }
        }

        $selected = array_slice($records, max(0, $offset), max(0, $limit));
        $pending = 0;
        $unchanged = 0;
        $unavailable = 0;
        $selectedReferences = 0;
        foreach ($selected as $record) {
            if (! is_array($record)) {
                continue;
            }
            $productId = $record['external_id'] ?? null;
            $matches = Product::withTrashed()->where('external_source', 'qmed')->where('external_id', $productId)->get();
            if ($matches->count() !== 1) {
                $errors[] = "Missing or duplicate Qmed product {$productId}.";
                continue;
            }
            $product = $matches->first();
            if ($product->trashed() || $product->status !== ProductStatus::DRAFT || $product->published_at !== null
                || $product->external_parent_sku !== null || $product->variants()->withTrashed()->exists()) {
                $errors[] = "Unsafe Qmed product {$productId}.";
                continue;
            }
            foreach ($record['attachments'] ?? [] as $attachment) {
                $selectedReferences++;
                $id = $attachment['request_fields']['id'] ?? null;
                if (! is_string($id) || preg_match('/^[1-9][0-9]{0,18}$/D', $id) !== 1) {
                    continue;
                }
                $existing = ProductDocument::query()->where('external_source', 'qmed')->where('external_id', $id)->first();
                if ($existing === null && isset($approved[$id])) {
                    $unavailable++;
                    continue;
                }
                if ($existing === null) {
                    $pending++;
                    continue;
                }
                if ($existing->product_id !== $product->id || $existing->title !== trim($attachment['label'])
                    || $existing->disk !== 'local' || $existing->mime_type !== 'application/pdf'
                    || ! preg_match('/^[a-f0-9]{64}$/D', (string) $existing->sha256)
                    || $existing->path !== $this->path($id, $existing->sha256)
                    || $existing->file_name !== $id.'-'.$existing->sha256.'.pdf'
                    || ! Storage::disk('local')->exists($existing->path)
                    || (int) $existing->file_size < 16 || (int) $existing->file_size > QmedDocumentDownloader::MAX_BYTES
                    || strlen(Storage::disk('local')->get($existing->path)) !== (int) $existing->file_size
                    || hash('sha256', Storage::disk('local')->get($existing->path)) !== $existing->sha256) {
                    $errors[] = "Conflicting or damaged stored Qmed document {$id}.";
                    continue;
                }
                $unchanged++;
            }
        }

        return [
            'safe' => $errors === [],
            'errors' => array_values(array_unique($errors)),
            'selected' => count($selected),
            'all_references' => $allReferences,
            'selected_references' => $selectedReferences,
            'to_import' => $pending,
            'unchanged' => $unchanged,
            'unavailable' => $unavailable,
        ];
    }

    /** @param array<string, mixed> $dataset
     * @return array<string, int>
     */
    public function sync(array $dataset, int $offset, int $limit, array $exceptions = []): array
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Qmed document writes are restricted to local/testing environments.');
        }
        $check = $this->inspect($dataset, $offset, $limit, $exceptions);
        if (! $check['safe']) {
            throw new RuntimeException('Qmed document preflight failed: '.implode(' | ', $check['errors']));
        }
        $created = 0;
        $unchanged = 0;
        $unavailable = 0;
        $approved = array_fill_keys(array_column($exceptions, 'external_id'), true);
        foreach (array_slice($dataset['products'], $offset, $limit) as $record) {
            $product = Product::query()->where('external_source', 'qmed')->where('external_id', $record['external_id'])->firstOrFail();
            foreach ($record['attachments'] as $attachment) {
                $id = $attachment['request_fields']['id'];
                if (isset($approved[$id])) {
                    $unavailable++;
                    continue;
                }
                if (ProductDocument::query()->where('external_source', 'qmed')->where('external_id', $id)->exists()) {
                    $unchanged++;
                    continue;
                }
                $document = $this->downloader->download($id);
                if (($document['external_id'] ?? null) !== $id
                    || ($document['mime_type'] ?? null) !== 'application/pdf'
                    || ! is_string($document['bytes'] ?? null)
                    || strlen($document['bytes']) !== ($document['file_size'] ?? null)
                    || hash('sha256', $document['bytes']) !== ($document['sha256'] ?? null)
                    || strlen($document['bytes']) > QmedDocumentDownloader::MAX_BYTES) {
                    throw new RuntimeException("Invalid verified downloader result for {$id}.");
                }
                $path = $this->path($id, $document['sha256']);
                $disk = Storage::disk('local');
                $newFile = ! $disk->exists($path);
                if ($newFile && ! $disk->put($path, $document['bytes'])) {
                    throw new RuntimeException("Unable to persist Qmed document {$id}.");
                }
                if (hash('sha256', $disk->get($path)) !== $document['sha256']) {
                    if ($newFile) {
                        $disk->delete($path);
                    }
                    throw new RuntimeException("Stored Qmed document hash mismatch {$id}.");
                }
                try {
                    DB::transaction(function () use ($product, $attachment, $document, $path, $id): void {
                        $locked = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
                        if ($locked->status !== ProductStatus::DRAFT || $locked->published_at !== null
                            || $locked->external_parent_sku !== null || $locked->variants()->withTrashed()->exists()) {
                            throw new RuntimeException('Qmed product changed during document sync.');
                        }
                        if (ProductDocument::query()->where('external_source', 'qmed')->where('external_id', $id)->exists()) {
                            throw new RuntimeException('Qmed document imported concurrently; inspect before retry.');
                        }
                        ProductDocument::query()->create([
                            'product_id' => $locked->id,
                            'external_source' => 'qmed',
                            'external_id' => $id,
                            'title' => trim($attachment['label']),
                            'file_name' => $id.'-'.$document['sha256'].'.pdf',
                            'disk' => 'local',
                            'path' => $path,
                            'mime_type' => 'application/pdf',
                            'file_size' => $document['file_size'],
                            'sha256' => $document['sha256'],
                        ]);
                    });
                } catch (Throwable $exception) {
                    if ($newFile) {
                        $disk->delete($path);
                    }
                    throw $exception;
                }
                $created++;
            }
        }
        return ['created' => $created, 'unchanged' => $unchanged, 'unavailable' => $unavailable];
    }

    private function path(string $id, string $sha256): string
    {
        return 'product-documents/qmed/'.$id.'-'.$sha256.'.pdf';
    }
}
