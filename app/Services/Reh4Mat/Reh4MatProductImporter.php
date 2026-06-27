<?php

declare(strict_types=1);

namespace App\Services\Reh4Mat;

use App\Enums\CategoryStatus;
use App\Enums\Currency;
use App\Enums\ProductStatus;
use App\Enums\ProductVariantStatus;
use App\Enums\StockStatus;
use App\Enums\VatRate;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Services\Images\RemoteImageImporter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

final class Reh4MatProductImporter
{
    private const MAX_DATABASE_STRING_LENGTH = 190;

    /**
     * @var list<string>
     */
    private const DOWNLOAD_ALLOWED_HOSTS = ['reh4mat.com', 'www.reh4mat.com'];

    private const DOWNLOAD_DISK = 'public';

    private const DOWNLOAD_MAX_BYTES = 20 * 1024 * 1024;

    /**
     * @var list<string>
     */
    private array $warnings = [];

    public function __construct(
        private readonly RemoteImageImporter $remoteImageImporter,
    ) {}

    /**
     * @param  array<string, mixed>  $scraped
     * @return array{product: Product, warnings: list<string>}
     */
    public function import(
        array $scraped,
        ProductStatus $status = ProductStatus::DRAFT,
        bool $importImages = true,
        ?int $imageLimit = 10,
        bool $importDownloads = true,
    ): array {
        $this->warnings = [];
        $externalId = $this->externalProductId($scraped);
        $downloadContext = $this->downloadContext($scraped, $externalId, $importDownloads);

        $product = DB::transaction(function () use ($scraped, $externalId, $status, $importImages, $imageLimit, $downloadContext): Product {
            $product = $this->resolveProduct($scraped, $externalId, $status, $downloadContext);

            $this->syncCategories($product, $scraped);
            $this->syncDefaultVariant($product, $scraped, $status);

            if ($importImages) {
                $this->syncImages($product, $scraped, $imageLimit);
            }

            return $product->fresh([
                'categories.parent',
                'images',
                'variants.attributeValues.attribute',
            ]);
        });

        return [
            'product' => $product,
            'warnings' => $this->warnings,
        ];
    }

    /**
     * @param  array<string, mixed>  $scraped
     */
    /**
     * @param  array<string, mixed>  $downloadContext
     */
    private function resolveProduct(array $scraped, string $externalId, ProductStatus $status, array $downloadContext): Product
    {
        $product = Product::withTrashed()
            ->where('external_source', 'reh4mat')
            ->where('external_id', $externalId)
            ->first();

        $baseSlug = $this->stringOrNull($scraped['slug'] ?? null)
            ?: $this->slugFromUrl($this->stringOrNull($scraped['canonical_url'] ?? null))
                ?: $this->slugFromUrl($this->stringOrNull($scraped['source_url'] ?? null))
                    ?: Str::slug((string) ($scraped['name'] ?? 'reh4mat-product-'.$externalId));

        if ($baseSlug === '') {
            $baseSlug = 'reh4mat-product-'.$externalId;
        }

        $name = $this->stringOrNull($scraped['name'] ?? null)
            ?: $this->stringOrNull($scraped['sku'] ?? null)
                ?: 'Reh4Mat product '.$externalId;

        $attributes = [
            'name' => $name,
            'slug' => $this->uniqueProductSlug($baseSlug, $product?->id, $externalId),
            'short_description' => $this->shortDescriptionHtml($scraped, $downloadContext),
            'description' => $this->productDescriptionHtml($scraped, $downloadContext),
            'seo_title' => $this->stringOrNull($scraped['seo_title'] ?? null) ?: $name,
            'seo_description' => $this->seoDescription($scraped),
            'status' => $status,
            'external_source' => 'reh4mat',
            'external_id' => $externalId,
            'external_parent_sku' => $this->parentSku($scraped, $externalId),
        ];

        if ($product !== null) {
            if ($product->trashed()) {
                $product->restore();
            }

            $product->update($attributes);

            return $product;
        }

        return Product::query()->create($attributes);
    }

    /**
     * @param  array<string, mixed>  $scraped
     */
    private function syncCategories(Product $product, array $scraped): void
    {
        $categoryNames = $this->categoryNames($scraped);

        if ($categoryNames === []) {
            return;
        }

        $parent = null;
        $resolved = [];

        foreach ($categoryNames as $categoryName) {
            $parent = $this->resolveCategory($categoryName, $parent);
            $resolved[] = $parent;
        }

        if ($resolved === []) {
            return;
        }

        $leafCategory = end($resolved);
        $syncPayload = [];

        foreach ($resolved as $category) {
            $syncPayload[$category->id] = [
                'is_primary' => $category->is($leafCategory),
            ];
        }

        $product->categories()->sync($syncPayload);
    }

    private function resolveCategory(string $name, ?Category $parent): Category
    {
        $slug = Str::slug($name);

        if ($slug === '') {
            $slug = 'reh4mat-category-'.substr(sha1($name), 0, 12);
        }

        $category = Category::withTrashed()->where('slug', $slug)->first();

        $attributes = [
            'parent_id' => $parent?->id,
            'name' => $name,
            'status' => CategoryStatus::ACTIVE,
        ];

        if ($category !== null) {
            if ($category->trashed()) {
                $category->restore();
            }

            $category->update($attributes);

            return $category;
        }

        return Category::query()->create($attributes + [
            'slug' => $slug,
        ]);
    }

    /**
     * @param  array<string, mixed>  $scraped
     */
    private function syncDefaultVariant(Product $product, array $scraped, ProductStatus $status): void
    {
        $externalVariantId = 'reh4mat-'.$product->external_id.'-default';
        $grossAmount = $this->moneyToMinorUnits($scraped['price_gross_amount'] ?? null);
        $vatRate = VatRate::VAT_8;

        $variant = ProductVariant::updateOrCreate(
            [
                'product_id' => $product->id,
                'external_variant_id' => $externalVariantId,
            ],
            [
                'sku' => $this->uniqueSku(
                    $this->stringOrNull($scraped['sku'] ?? null) ?: 'REH4MAT-'.$product->external_id,
                    $product->id,
                    $externalVariantId,
                ),
                'status' => $this->variantStatusForProductStatus($status),
                'price_net_amount' => $grossAmount === null ? null : $vatRate->netFromGross($grossAmount),
                'price_gross_amount' => $grossAmount,
                'currency' => Currency::PLN,
                'vat_rate' => $vatRate,
                'stock_status' => $this->stockStatus($scraped),
                'is_default' => true,
            ]
        );

        $variant->attributeValues()->sync([]);

        ProductVariant::query()
            ->where('product_id', $product->id)
            ->where('id', '!=', $variant->id)
            ->delete();
    }

    /**
     * @param  array<string, mixed>  $scraped
     */
    private function syncImages(Product $product, array $scraped, ?int $imageLimit): void
    {
        $imageRows = [];
        $seenUrls = [];
        $maxImages = $imageLimit !== null && $imageLimit > 0 ? $imageLimit : null;

        foreach (($scraped['images'] ?? []) as $imageData) {
            if (! is_array($imageData)) {
                continue;
            }

            if ($maxImages !== null && count($imageRows) >= $maxImages) {
                break;
            }

            $url = $this->stringOrNull($imageData['url'] ?? null);

            if ($url === null || isset($seenUrls[$url])) {
                continue;
            }

            $seenUrls[$url] = true;

            try {
                $imported = $this->remoteImageImporter->import(
                    $url,
                    'products/reh4mat/'.$product->external_id.'/gallery',
                    'public',
                    ['reh4mat.com', 'stabilobedsystem.pl']
                );
            } catch (Throwable $exception) {
                $this->warnings[] = 'Image skipped for '.$product->external_id.': '.$url.' — '.$exception->getMessage();

                continue;
            }

            $alt = $this->stringOrNull($imageData['alt'] ?? null) ?: $product->name;

            $imageRows[] = [
                'disk' => $imported['disk'],
                'path' => $imported['path'],
                'source_url' => $imported['source_url'],
                'mime_type' => $imported['mime_type'],
                'file_size' => $imported['file_size'],
                'sha256' => $imported['sha256'],
                'alt_text' => $alt,
                'title' => $alt,
                'sort_order' => count($imageRows),
                'is_main' => count($imageRows) === 0,
            ];
        }

        $paths = array_column($imageRows, 'path');

        ProductImage::query()
            ->where('product_id', $product->id)
            ->when(
                $paths !== [],
                fn ($query) => $query->whereNotIn('path', $paths),
                fn ($query) => $query
            )
            ->delete();

        foreach ($imageRows as $row) {
            ProductImage::updateOrCreate(
                [
                    'product_id' => $product->id,
                    'path' => $row['path'],
                ],
                [
                    'disk' => $row['disk'],
                    'source_url' => $row['source_url'],
                    'mime_type' => $row['mime_type'],
                    'file_size' => $row['file_size'],
                    'sha256' => $row['sha256'],
                    'alt_text' => $row['alt_text'],
                    'title' => $row['title'],
                    'is_main' => $row['is_main'],
                    'sort_order' => $row['sort_order'],
                ]
            );
        }
    }

    /**
     * @param  array<string, mixed>  $scraped
     * @return list<string>
     */
    private function categoryNames(array $scraped): array
    {
        $names = [];

        foreach (($scraped['source_category_path'] ?? []) as $categoryName) {
            $categoryName = $this->stringOrNull($categoryName);

            if ($categoryName !== null) {
                $names[] = $categoryName;
            }
        }

        foreach (($scraped['categories'] ?? []) as $categoryName) {
            $categoryName = $this->stringOrNull($categoryName);

            if ($categoryName !== null) {
                $names[] = $categoryName;
            }
        }

        if ($names === []) {
            $category = $this->stringOrNull($scraped['category'] ?? null)
                ?: $this->stringOrNull($scraped['source_category_name'] ?? null)
                    ?: $this->stringOrNull($scraped['source_top_category_name'] ?? null);

            if ($category !== null) {
                $names[] = $category;
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @param  array<string, mixed>  $scraped
     */
    private function parentSku(array $scraped, string $externalId): string
    {
        $sku = $this->stringOrNull($scraped['sku'] ?? null)
            ?: $this->productMetaValue($scraped, 'Kod katalogowy')
                ?: $this->productMetaValue($scraped, 'Model')
                    ?: 'REH4MAT-'.$externalId;

        return $this->limitDatabaseString($this->normaliseSku($sku));
    }

    /**
     * @param  array<string, mixed>  $scraped
     */
    private function productMetaValue(array $scraped, string $key): ?string
    {
        $meta = $scraped['product_meta'] ?? null;

        if (! is_array($meta)) {
            return null;
        }

        return $this->stringOrNull($meta[$key] ?? null);
    }

    /**
     * @param  array<string, mixed>  $scraped
     */
    private function seoDescription(array $scraped): ?string
    {
        return $this->productSummaryText($scraped, 300)
            ?: $this->plainTextSnippet($scraped['seo_description'] ?? null, 300);
    }

    /**
     * @param  array<string, mixed>  $scraped
     */
    private function productSummaryText(array $scraped, int $limit): ?string
    {
        foreach ([
            $scraped['description_html'] ?? null,
            $scraped['description'] ?? null,
            $scraped['short_description_html'] ?? null,
            $scraped['short_description'] ?? null,
            $scraped['seo_description'] ?? null,
        ] as $candidate) {
            $summary = $this->productSummarySnippet($candidate, $limit);

            if ($summary !== null && ! $this->looksLikeMetaSummary($summary)) {
                return $summary;
            }
        }

        return null;
    }

    private function productSummarySnippet(mixed $value, int $limit): ?string
    {
        $string = $this->stringOrNull($value);

        if ($string === null) {
            return null;
        }

        $string = $this->removeUnsafeHtmlBlocksForText($string);

        foreach ($this->paragraphTexts($string) as $paragraph) {
            if (! $this->looksLikeMetaSummary($paragraph)) {
                return Str::limit($paragraph, $limit, '');
            }
        }

        return $this->plainTextSnippet($string, $limit);
    }

    /**
     * @return list<string>
     */
    private function paragraphTexts(string $html): array
    {
        if (! preg_match_all('/<p\b[^>]*>(.*?)<\/p>/isu', $html, $matches)) {
            return [];
        }

        $paragraphs = [];

        foreach ($matches[1] as $paragraphHtml) {
            $paragraph = trim(preg_replace('/\s+/', ' ', strip_tags($paragraphHtml)) ?? strip_tags($paragraphHtml));

            if ($paragraph === '') {
                continue;
            }

            $paragraphs[] = html_entity_decode($paragraph, ENT_QUOTES | ENT_HTML5);
        }

        return $paragraphs;
    }

    private function looksLikeMetaSummary(string $summary): bool
    {
        $normalized = Str::of($summary)->lower()->ascii()->value();

        $signals = 0;

        foreach ([
            'wyrob medyczny',
            'marka:',
            'kod umdns',
            'kod nfz',
            'rozmiar uniwersalny',
            'minimum device',
            'maximum effect',
            'press-slide',
        ] as $needle) {
            if (str_contains($normalized, $needle)) {
                $signals++;
            }
        }

        return $signals >= 3;
    }

    /**
     * @param  array<string, mixed>  $scraped
     */
    private function stockStatus(array $scraped): StockStatus
    {
        $availability = Str::of((string) ($scraped['availability'] ?? ''))
            ->lower()
            ->ascii()
            ->replaceMatches('/[^a-z0-9]+/', '_')
            ->trim('_')
            ->value();

        $label = Str::of((string) ($scraped['availability_label'] ?? ''))
            ->lower()
            ->ascii()
            ->value();

        if (in_array($availability, ['out_of_stock', 'unavailable', 'sold_out', 'not_available'], true)
            || Str::of($label)->contains(['brak', 'niedostepn', 'wyprzedan'])) {
            return StockStatus::OUT_OF_STOCK;
        }

        return StockStatus::IN_STOCK;
    }

    private function variantStatusForProductStatus(ProductStatus $status): ProductVariantStatus
    {
        return match ($status) {
            ProductStatus::ACTIVE => ProductVariantStatus::ACTIVE,
            ProductStatus::ARCHIVED => ProductVariantStatus::ARCHIVED,
            ProductStatus::DRAFT => ProductVariantStatus::DRAFT,
        };
    }

    /**
     * @param  array<string, mixed>  $scraped
     */
    private function externalProductId(array $scraped): string
    {
        $externalId = $this->stringOrNull($scraped['external_product_id'] ?? null)
            ?: $this->stringOrNull($scraped['external_id'] ?? null)
                ?: $this->stringOrNull($scraped['sku'] ?? null)
                    ?: $this->stringOrNull($scraped['slug'] ?? null);

        if ($externalId !== null) {
            return $this->limitDatabaseString($externalId);
        }

        $sourceUrl = $this->stringOrNull($scraped['canonical_url'] ?? null)
            ?: $this->stringOrNull($scraped['source_url'] ?? null)
                ?: (string) ($scraped['name'] ?? 'reh4mat-product');

        return substr(sha1($sourceUrl), 0, 32);
    }

    /**
     * @param  array<string, mixed>  $scraped
     */
    /**
     * @param  array<string, mixed>  $downloadContext
     */
    private function shortDescriptionHtml(array $scraped, array $downloadContext): ?string
    {
        $summary = $this->productSummaryText($scraped, 500);

        if ($summary === null) {
            return null;
        }

        return $this->cleanImportedHtml('<p>'.e($summary).'</p>', $downloadContext);
    }

    /**
     * @param  array<string, mixed>  $scraped
     */
    /**
     * @param  array<string, mixed>  $downloadContext
     */
    private function productDescriptionHtml(array $scraped, array $downloadContext): ?string
    {
        $sections = [];
        $mainHtml = $this->stringOrNull($scraped['description_html'] ?? null);

        if ($mainHtml === null && $this->stringOrNull($scraped['description'] ?? null) !== null) {
            $mainHtml = '<p>'.e((string) $scraped['description']).'</p>';
        }

        if ($mainHtml !== null) {
            $sections[] = $mainHtml;
        }

        $sections = array_merge($sections, $this->tabSections($scraped));

        $metaSection = $this->metadataSection($scraped);
        if ($metaSection !== null) {
            $sections[] = $metaSection;
        }

        $pictogramSection = $this->pictogramSection($scraped);
        if ($pictogramSection !== null) {
            $sections[] = $pictogramSection;
        }

        $regulatorySection = $this->regulatoryIconSection($scraped);
        if ($regulatorySection !== null) {
            $sections[] = $regulatorySection;
        }

        $downloadsSection = $this->downloadsSection($downloadContext);
        if ($downloadsSection !== null) {
            $sections[] = $downloadsSection;
        }

        $medicalNotice = $this->stringOrNull($scraped['medical_device_notice'] ?? null);
        if ($medicalNotice !== null) {
            $sections[] = '<section class="reh4mat-medical-notice"><p><strong>'.e($medicalNotice).'</strong></p></section>';
        }

        if ($sections === []) {
            return null;
        }

        return $this->cleanImportedHtml(implode("\n", $sections), $downloadContext);
    }

    /**
     * @param  array<string, mixed>  $scraped
     * @return list<string>
     */
    private function tabSections(array $scraped): array
    {
        $sections = [];

        foreach (($scraped['tabs'] ?? []) as $tab) {
            if (! is_array($tab)) {
                continue;
            }

            $title = $this->stringOrNull($tab['title'] ?? null);
            $html = $this->stringOrNull($tab['html'] ?? null);

            if ($title === null || $html === null || Str::of($title)->lower()->ascii()->trim()->value() === 'opis') {
                continue;
            }

            $sections[] = '<section class="reh4mat-tab"><h2>'.e($title).'</h2>'.$html.'</section>';
        }

        return $sections;
    }

    /**
     * @param  array<string, mixed>  $scraped
     */
    private function metadataSection(array $scraped): ?string
    {
        $rows = [];

        foreach (($scraped['product_meta'] ?? []) as $key => $value) {
            $key = $this->stringOrNull($key);
            $value = $this->stringOrNull($value);

            if ($key !== null && $value !== null) {
                $rows[] = '<tr><th>'.e($key).'</th><td>'.e($value).'</td></tr>';
            }
        }

        foreach (($scraped['codes'] ?? []) as $codeType => $values) {
            $codeType = $this->stringOrNull($codeType);
            $values = is_array($values) ? $this->stringList($values) : [];

            if ($codeType !== null && $values !== []) {
                $rows[] = '<tr><th>'.e($codeType).'</th><td>'.e(implode(', ', $values)).'</td></tr>';
            }
        }

        if ($rows === []) {
            return null;
        }

        return '<section class="reh4mat-product-meta"><h2>Dane produktu</h2><table><tbody>'.implode('', $rows).'</tbody></table></section>';
    }

    /**
     * @param  array<string, mixed>  $scraped
     */
    private function pictogramSection(array $scraped): ?string
    {
        $items = [];

        foreach (($scraped['pictograms'] ?? []) as $pictogram) {
            if (! is_array($pictogram)) {
                continue;
            }

            $label = $this->stringOrNull($pictogram['label'] ?? null);
            $description = $this->stringOrNull($pictogram['description'] ?? null);

            if ($label === null) {
                continue;
            }

            $items[] = '<li><strong>'.e($label).'</strong>'.($description === null ? '' : ' — '.e($description)).'</li>';
        }

        if ($items === []) {
            return null;
        }

        return '<section class="reh4mat-pictograms"><h2>Piktogramy produktu</h2><ul>'.implode('', $items).'</ul></section>';
    }

    /**
     * @param  array<string, mixed>  $scraped
     */
    private function regulatoryIconSection(array $scraped): ?string
    {
        $items = [];

        foreach (($scraped['regulatory_icons'] ?? []) as $icon) {
            if (! is_array($icon)) {
                continue;
            }

            $label = $this->stringOrNull($icon['label'] ?? null);
            $description = $this->stringOrNull($icon['description'] ?? null);

            if ($label === null) {
                continue;
            }

            $items[] = '<li><strong>'.e($label).'</strong>'.($description === null ? '' : ' — '.e($description)).'</li>';
        }

        if ($items === []) {
            return null;
        }

        return '<section class="reh4mat-regulatory-icons"><h2>Oznaczenia medyczne</h2><ul>'.implode('', $items).'</ul></section>';
    }

    /**
     * @param  array<string, mixed>  $downloadContext
     */
    private function downloadsSection(array $downloadContext): ?string
    {
        $items = [];

        foreach (($downloadContext['downloads'] ?? []) as $download) {
            if (! is_array($download)) {
                continue;
            }

            $label = $this->stringOrNull($download['label'] ?? null);
            $url = $this->stringOrNull($download['local_url'] ?? null);

            if ($label === null) {
                continue;
            }

            if ($url !== null) {
                $items[] = '<li><a href="'.e($url).'">'.e($label).'</a></li>';
            } else {
                $items[] = '<li>'.e($label).'</li>';
            }
        }

        if ($items === []) {
            return null;
        }

        return '<section class="reh4mat-downloads"><h2>Do pobrania</h2><ul>'.implode('', $items).'</ul></section>';
    }

    /**
     * @param  array<string, mixed>  $scraped
     * @return array{downloads: list<array<string, mixed>>, url_map: array<string, string>}
     */
    private function downloadContext(array $scraped, string $externalId, bool $importDownloads): array
    {
        $downloads = [];
        $urlMap = [];

        foreach (($scraped['downloads'] ?? []) as $download) {
            if (! is_array($download)) {
                continue;
            }

            $label = $this->stringOrNull($download['label'] ?? null);
            $url = $this->stringOrNull($download['url'] ?? null);

            if ($label === null) {
                continue;
            }

            $localized = [
                'label' => $label,
                'original_url' => $url,
                'local_url' => null,
            ];

            if ($url !== null && $importDownloads) {
                try {
                    $localUrl = $this->downloadFile($url, $externalId, $label);
                    $localized['local_url'] = $localUrl;
                    $urlMap[$this->normalizeUrlForMap($url)] = $localUrl;
                } catch (Throwable $exception) {
                    $this->warnings[] = 'Download skipped for '.$externalId.': '.$label.' — '.$exception->getMessage();
                }
            }

            $downloads[] = $localized;
        }

        return [
            'downloads' => $downloads,
            'url_map' => $urlMap,
        ];
    }

    private function downloadFile(string $url, string $externalId, string $label): string
    {
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            throw new \RuntimeException('Invalid download URL.');
        }

        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || ! $this->hostIsAllowed($host, self::DOWNLOAD_ALLOWED_HOSTS)) {
            throw new \RuntimeException('Disallowed download host ['.$host.'].');
        }

        $response = Http::timeout(30)
            ->retry(2, 750)
            ->withHeaders([
                'User-Agent' => 'KonjiShopBot/1.0',
                'Accept' => 'application/pdf,application/octet-stream,*/*;q=0.8',
            ])
            ->get($url);

        if (! $response->successful()) {
            throw new \RuntimeException('HTTP '.$response->status());
        }

        $contents = $response->body();

        if ($contents === '') {
            throw new \RuntimeException('Downloaded file is empty.');
        }

        if (strlen($contents) > self::DOWNLOAD_MAX_BYTES) {
            throw new \RuntimeException('Downloaded file is too large.');
        }

        $mimeType = $this->downloadMimeType($response->header('Content-Type'));
        $extension = $this->downloadExtension($url, $mimeType);
        $filename = Str::slug($label) ?: 'download';
        $filename .= '-'.substr(sha1($url), 0, 10).'.'.$extension;
        $path = 'products/reh4mat/'.$externalId.'/downloads/'.$filename;

        if (! Storage::disk(self::DOWNLOAD_DISK)->exists($path)) {
            Storage::disk(self::DOWNLOAD_DISK)->put($path, $contents);
        }

        return Storage::disk(self::DOWNLOAD_DISK)->url($path);
    }

    private function downloadMimeType(?string $contentType): string
    {
        if ($contentType === null) {
            return 'application/octet-stream';
        }

        return mb_strtolower(trim(explode(';', $contentType)[0]));
    }

    private function downloadExtension(string $url, string $mimeType): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        $extension = is_string($path) ? mb_strtolower(pathinfo($path, PATHINFO_EXTENSION)) : '';

        if ($extension !== '') {
            return preg_replace('/[^a-z0-9]+/', '', $extension) ?: 'bin';
        }

        return match ($mimeType) {
            'application/pdf' => 'pdf',
            default => 'bin',
        };
    }

    /**
     * @param  array<string, mixed>  $downloadContext
     */
    private function cleanImportedHtml(?string $html, array $downloadContext): ?string
    {
        $html = $this->cleanHtml($html);

        if ($html === null) {
            return null;
        }

        $html = $this->rewriteDownloadUrls($html, $downloadContext);
        $html = $this->removeRemoteAssetTags($html);
        $html = $this->rewriteExternalAnchors($html);
        $html = preg_replace('/\s+(srcset|src)\s*=\s*("[^"]*reh4mat\.com[^"]*"|\'[^\']*reh4mat\.com[^\']*\')/iu', '', $html) ?? $html;
        $html = preg_replace('/\s+(srcset|src)\s*=\s*("[^"]*stabilobedsystem\.pl[^"]*"|\'[^\']*stabilobedsystem\.pl[^\']*\')/iu', '', $html) ?? $html;
        $html = trim(preg_replace('/\s+/', ' ', $html) ?? $html);

        return $html === '' ? null : $html;
    }

    /**
     * @param  array<string, mixed>  $downloadContext
     */
    private function rewriteDownloadUrls(string $html, array $downloadContext): string
    {
        foreach (($downloadContext['url_map'] ?? []) as $sourceUrl => $localUrl) {
            if (! is_string($sourceUrl) || ! is_string($localUrl)) {
                continue;
            }

            $html = str_replace($sourceUrl, $localUrl, $html);
            $html = str_replace(e($sourceUrl), e($localUrl), $html);
        }

        return $html;
    }

    private function removeRemoteAssetTags(string $html): string
    {
        $html = preg_replace_callback('/<img\b[^>]*>/isu', function (array $matches): string {
            $tag = $matches[0];
            $src = $this->attributeValue($tag, 'src');

            if ($src !== null && $this->isReh4MatExternalUrl($src)) {
                return '';
            }

            $srcset = $this->attributeValue($tag, 'srcset');

            if ($srcset !== null && preg_match('/(?:reh4mat\.com|stabilobedsystem\.pl)/iu', $srcset)) {
                return '';
            }

            return $tag;
        }, $html) ?? $html;

        return $html;
    }

    private function rewriteExternalAnchors(string $html): string
    {
        return preg_replace_callback('/<a\b([^>]*)>(.*?)<\/a>/isu', function (array $matches): string {
            $attributes = $matches[1];
            $innerHtml = $matches[2];
            $href = $this->attributeValue($attributes, 'href');

            if ($href === null || ! $this->isReh4MatExternalUrl($href)) {
                return $matches[0];
            }

            $label = trim(preg_replace('/\s+/', ' ', strip_tags($innerHtml)) ?? strip_tags($innerHtml));

            if ($label === '') {
                $label = 'Powiązany produkt';
            }

            if ($this->looksLikeDownloadUrl($href)) {
                return '<span class="reh4mat-pending-download">'.e($label).'</span>';
            }

            $localUrl = $this->localProductUrlForExternalUrl($href);

            if ($localUrl !== null) {
                return '<a href="'.e($localUrl).'">'.e($label).'</a>';
            }

            $externalSlug = $this->slugFromUrl($href);

            if ($externalSlug !== null) {
                return '<span class="reh4mat-pending-accessory" data-external-source="reh4mat" data-external-slug="'.e($externalSlug).'">'.e($label).'</span>';
            }

            return '<span class="reh4mat-pending-accessory">'.e($label).'</span>';
        }, $html) ?? $html;
    }

    private function looksLikeDownloadUrl(string $url): bool
    {
        $path = parse_url($url, PHP_URL_PATH);

        if (! is_string($path)) {
            return false;
        }

        return preg_match('/\.(pdf|doc|docx|xls|xlsx|zip)$/iu', $path) === 1;
    }

    private function localProductUrlForExternalUrl(string $url): ?string
    {
        $slug = $this->slugFromUrl($url);

        if ($slug === null) {
            return null;
        }

        $product = Product::query()
            ->where('external_source', 'reh4mat')
            ->where('slug', $slug)
            ->first();

        return $product === null ? null : '/products/'.$product->slug;
    }

    private function attributeValue(string $html, string $attribute): ?string
    {
        if (! preg_match('/\b'.preg_quote($attribute, '/').'\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))/iu', $html, $matches)) {
            return null;
        }

        return html_entity_decode($matches[2] ?? $matches[3] ?? $matches[4] ?? '', ENT_QUOTES | ENT_HTML5);
    }

    private function isReh4MatExternalUrl(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $this->hostIsAllowed($host, [
            'reh4mat.com',
            'www.reh4mat.com',
            'stabilobedsystem.pl',
            'www.stabilobedsystem.pl',
        ]);
    }

    /**
     * @param  list<string>  $allowedHosts
     */
    private function hostIsAllowed(string $host, array $allowedHosts): bool
    {
        $host = mb_strtolower($host);

        foreach ($allowedHosts as $allowedHost) {
            $allowedHost = mb_strtolower(ltrim($allowedHost, '.'));

            if ($host === $allowedHost || str_ends_with($host, '.'.$allowedHost)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeUrlForMap(string $url): string
    {
        return html_entity_decode($url, ENT_QUOTES | ENT_HTML5);
    }

    private function uniqueProductSlug(string $baseSlug, ?int $currentProductId, string $externalId): string
    {
        $baseSlug = Str::slug($baseSlug) ?: 'reh4mat-product-'.$externalId;
        $candidate = $baseSlug;
        $suffix = 2;

        while ($this->productSlugExists($candidate, $currentProductId)) {
            if ($suffix === 2) {
                $candidate = $baseSlug.'-reh4mat-'.$externalId;
            } else {
                $candidate = $baseSlug.'-reh4mat-'.$externalId.'-'.$suffix;
            }

            $suffix++;
        }

        return $candidate;
    }

    private function productSlugExists(string $slug, ?int $currentProductId): bool
    {
        return Product::withTrashed()
            ->where('slug', $slug)
            ->when($currentProductId !== null, fn ($query) => $query->whereKeyNot($currentProductId))
            ->exists();
    }

    private function uniqueSku(string $sku, int $productId, string $externalVariantId): string
    {
        $sku = $this->normaliseSku($sku) ?: 'REH4MAT-'.$externalVariantId;
        $candidate = $sku;
        $suffix = 2;

        while (ProductVariant::withTrashed()
            ->where('sku', $candidate)
            ->where(function ($query) use ($productId, $externalVariantId): void {
                $query
                    ->where('product_id', '!=', $productId)
                    ->orWhere('external_variant_id', '!=', $externalVariantId);
            })
            ->exists()) {
            $candidate = $sku.'-'.$suffix;
            $suffix++;
        }

        return $candidate;
    }

    private function normaliseSku(string $sku): string
    {
        $sku = html_entity_decode(trim($sku), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        if ($sku === '') {
            return '';
        }

        $sku = str_replace(['/', '\\'], '-', $sku);
        $sku = preg_replace('/\s+/', '-', $sku) ?? $sku;
        $sku = preg_replace('/[^A-Za-z0-9._-]+/', '-', $sku) ?? $sku;
        $sku = preg_replace('/-+/', '-', $sku) ?? $sku;

        return Str::upper(trim($sku, '-._'));
    }

    private function moneyToMinorUnits(mixed $value): ?int
    {
        if (is_int($value) || is_float($value)) {
            return (int) round(((float) $value) * 100);
        }

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        $normalized = str_replace(["\xc2\xa0", ' '], '', $value);
        $normalized = str_replace(',', '.', $normalized);
        $normalized = preg_replace('/[^0-9.\-]/', '', $normalized) ?? $normalized;

        if ($normalized === '' || ! is_numeric($normalized)) {
            return null;
        }

        return (int) round(((float) $normalized) * 100);
    }

    private function slugFromUrl(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        $path = parse_url($url, PHP_URL_PATH);

        if (! is_string($path)) {
            return null;
        }

        $parts = array_values(array_filter(explode('/', trim($path, '/'))));
        $slug = end($parts);

        return is_string($slug) && $slug !== '' ? Str::slug($slug) : null;
    }

    private function cleanHtml(?string $html): ?string
    {
        if ($html === null) {
            return null;
        }

        $html = preg_replace('/<script\b[^>]*>.*?<\/script>/isu', '', $html) ?? $html;
        $html = preg_replace('/<style\b[^>]*>.*?<\/style>/isu', '', $html) ?? $html;
        $html = preg_replace('/<iframe\b[^>]*>.*?<\/iframe>/isu', '', $html) ?? $html;
        $html = preg_replace('/<embed\b[^>]*>.*?<\/embed>/isu', '', $html) ?? $html;
        $html = preg_replace('/<embed\b[^>]*\/?>/isu', '', $html) ?? $html;
        $html = preg_replace('/<object\b[^>]*>.*?<\/object>/isu', '', $html) ?? $html;
        $html = preg_replace('/\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/iu', '', $html) ?? $html;
        $html = preg_replace('/<p>\s*(?:&nbsp;)?\s*<\/p>/i', '', $html) ?? $html;
        $html = trim(preg_replace('/\s+/', ' ', $html) ?? $html);

        return $html === '' ? null : $html;
    }

    private function plainTextSnippet(mixed $value, int $limit): ?string
    {
        $string = $this->stringOrNull($value);

        if ($string === null) {
            return null;
        }

        $string = $this->removeUnsafeHtmlBlocksForText($string);
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($string)) ?? strip_tags($string));

        if ($text === '') {
            return null;
        }

        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5);

        return Str::limit($text, $limit, '');
    }

    private function removeUnsafeHtmlBlocksForText(string $html): string
    {
        $html = preg_replace('/<script\b[^>]*>.*?<\/script>/isu', '', $html) ?? $html;
        $html = preg_replace('/<style\b[^>]*>.*?<\/style>/isu', '', $html) ?? $html;
        $html = preg_replace('/<iframe\b[^>]*>.*?<\/iframe>/isu', '', $html) ?? $html;
        $html = preg_replace('/<embed\b[^>]*>.*?<\/embed>/isu', '', $html) ?? $html;
        $html = preg_replace('/<embed\b[^>]*\/?>/isu', '', $html) ?? $html;
        $html = preg_replace('/<object\b[^>]*>.*?<\/object>/isu', '', $html) ?? $html;

        return $html;
    }

    /**
     * @param  mixed  $values
     * @return list<string>
     */
    private function stringList(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $strings = [];

        foreach ($values as $value) {
            $string = $this->stringOrNull($value);

            if ($string !== null) {
                $strings[] = $string;
            }
        }

        return array_values(array_unique($strings));
    }

    private function limitNullableDatabaseString(?string $value): ?string
    {
        return $value === null ? null : $this->limitDatabaseString($value);
    }

    private function limitDatabaseString(string $value): string
    {
        $value = trim($value);

        if (mb_strlen($value) <= self::MAX_DATABASE_STRING_LENGTH) {
            return $value;
        }

        $hash = substr(sha1($value), 0, 10);
        $prefixLength = self::MAX_DATABASE_STRING_LENGTH - mb_strlen($hash) - 1;

        return rtrim(mb_substr($value, 0, max(1, $prefixLength)), '-_ .').'_'.$hash;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
