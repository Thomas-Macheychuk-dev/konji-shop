<?php

declare(strict_types=1);

namespace App\Services\Storage;

use App\Models\Product;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final class PublicProductMediaMigrationService
{
    /**
     * @return array{
     *     source_files:int,
     *     copied:int,
     *     already_present:int,
     *     planned:int,
     *     failed:int,
     *     failures:list<string>,
     *     descriptions_updated:int
     * }
     */
    public function migrate(
        string $sourceDisk,
        string $targetDisk,
        string $prefix = 'products',
        bool $write = false,
        bool $rewriteDescriptions = false,
    ): array {
        if ($sourceDisk === $targetDisk) {
            throw new RuntimeException('Source and target media disks must be different.');
        }

        $prefix = trim($prefix, '/');

        if ($prefix === '') {
            throw new RuntimeException('Media migration prefix must not be empty.');
        }

        if ($rewriteDescriptions && ! $write) {
            throw new RuntimeException('Description rewriting requires --write.');
        }

        $source = Storage::disk($sourceDisk);
        $target = Storage::disk($targetDisk);
        $files = $source->allFiles($prefix);
        $copied = 0;
        $alreadyPresent = 0;
        $planned = 0;
        $failures = [];

        foreach ($files as $path) {
            try {
                $sourceSize = $source->size($path);

                if ($target->exists($path) && $target->size($path) === $sourceSize) {
                    $alreadyPresent++;

                    continue;
                }

                if (! $write) {
                    $planned++;

                    continue;
                }

                $this->copyAndVerify($source, $target, $path, $sourceSize);
                $copied++;
            } catch (Throwable $exception) {
                $failures[] = $path.' — '.$exception->getMessage();
            }
        }

        $descriptionsUpdated = 0;

        if ($rewriteDescriptions) {
            $descriptionsUpdated = $this->rewriteLegacyProductStorageUrls($target, $prefix);
        }

        return [
            'source_files' => count($files),
            'copied' => $copied,
            'already_present' => $alreadyPresent,
            'planned' => $planned,
            'failed' => count($failures),
            'failures' => $failures,
            'descriptions_updated' => $descriptionsUpdated,
        ];
    }

    private function copyAndVerify(
        FilesystemAdapter $source,
        FilesystemAdapter $target,
        string $path,
        int $sourceSize,
    ): void {
        $stream = $source->readStream($path);

        if (! is_resource($stream)) {
            throw new RuntimeException('Unable to open source stream.');
        }

        try {
            if (! $target->put($path, $stream)) {
                throw new RuntimeException('Target storage rejected the write.');
            }
        } finally {
            fclose($stream);
        }

        if (! $target->exists($path)) {
            throw new RuntimeException('Target object is missing after write.');
        }

        if ($target->size($path) !== $sourceSize) {
            $target->delete($path);

            throw new RuntimeException('Target object size does not match the source.');
        }
    }

    private function rewriteLegacyProductStorageUrls(FilesystemAdapter $target, string $prefix): int
    {
        $configuredUrl = trim((string) config('filesystems.disks.public-s3.url'));

        if ($configuredUrl === '' || ! str_starts_with($configuredUrl, 'https://')) {
            throw new RuntimeException(
                'PUBLIC_FILESYSTEM_URL must be an HTTPS public/CDN URL before product descriptions can be rewritten.'
            );
        }

        $publicBaseUrl = rtrim($configuredUrl, '/');
        $updated = 0;
        $needle = '/storage/'.$prefix.'/';

        Product::withTrashed()
            ->where(function ($query) use ($needle): void {
                $query->where('description', 'like', '%'.$needle.'%')
                    ->orWhere('short_description', 'like', '%'.$needle.'%');
            })
            ->select(['id', 'description', 'short_description'])
            ->chunkById(100, function ($products) use ($target, $prefix, $publicBaseUrl, &$updated): void {
                foreach ($products as $product) {
                    $description = $this->rewriteField((string) ($product->description ?? ''), $target, $prefix, $publicBaseUrl);
                    $shortDescription = $this->rewriteField((string) ($product->short_description ?? ''), $target, $prefix, $publicBaseUrl);
                    $changes = [];

                    if ($description !== (string) ($product->description ?? '')) {
                        $changes['description'] = $description;
                    }

                    if ($shortDescription !== (string) ($product->short_description ?? '')) {
                        $changes['short_description'] = $shortDescription;
                    }

                    if ($changes === []) {
                        continue;
                    }

                    Product::withTrashed()->whereKey($product->id)->update($changes);
                    $updated++;
                }
            });

        return $updated;
    }

    private function rewriteField(
        string $html,
        FilesystemAdapter $target,
        string $prefix,
        string $publicBaseUrl,
    ): string {
        if ($html === '' || ! str_contains($html, '/storage/'.$prefix.'/')) {
            return $html;
        }

        $pattern = '~(?:https?://[^"\'<>\s]+)?/storage/('.preg_quote($prefix, '~').'/[^"\'<>\s?#]+)~iu';

        return preg_replace_callback($pattern, function (array $matches) use ($target, $publicBaseUrl): string {
            $path = rawurldecode((string) ($matches[1] ?? ''));

            if ($path === '' || ! $target->exists($path)) {
                throw new RuntimeException('Cannot rewrite legacy product media URL because the target object is missing: '.$path);
            }

            return $publicBaseUrl.'/'.ltrim($path, '/');
        }, $html) ?? $html;
    }
}
