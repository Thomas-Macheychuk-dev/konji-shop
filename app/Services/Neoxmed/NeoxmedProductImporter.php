<?php

declare(strict_types=1);

namespace App\Services\Neoxmed;

use App\Enums\AttributeDisplayType;
use App\Enums\CategoryStatus;
use App\Enums\Currency;
use App\Enums\ProductStatus;
use App\Enums\ProductVariantStatus;
use App\Enums\StockStatus;
use App\Enums\VatRate;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Services\Images\RemoteImageImporter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Import ONLY the recomputed, commercially approved NeoxMed priced-map contract.
 * Caller must verify the priced map against the exact structural map and approvals.
 */
final class NeoxmedProductImporter
{
    public function __construct(
        private readonly NeoxmedImportDatabaseAudit $databaseAudit,
        private readonly RemoteImageImporter $remoteImageImporter,
    ) {}

    /**
     * Read-only validation; rechecks live DB even when the frozen mapping previously passed.
     *
     * @param  array<string,mixed>  $map
     * @return array<string,mixed>
     */
    public function preflight(array $map): array
    {
        $errors = [];
        $products = $this->records($map['products'] ?? null);
        if (($map['source'] ?? null) !== 'neoxmed'
            || ($map['mode'] ?? null) !== 'priced_import_mapping_dry_run'
            || ($map['ready_for_database_write'] ?? false) !== true
            || ($map['errors'] ?? []) !== []
            || ($map['blocking_review_items'] ?? []) !== []) {
            $errors[] = 'The NeoxMed priced map is not approved and ready for database writes.';
        }
        if ($products === [] || count($products) !== (int) ($map['mapped_product_count'] ?? -1)
            || count($products) !== (int) ($map['source_product_count'] ?? -1)) {
            $errors[] = 'The priced map must include the complete source catalogue.';
        }
        $seenProducts = [];
        $seenSkus = [];
        $seenSlugs = [];
        foreach ($products as $mapped) {
            $product = is_array($mapped['product'] ?? null) ? $mapped['product'] : [];
            $externalId = $this->nonEmpty($product['external_id'] ?? null);
            $name = $this->nonEmpty($product['name'] ?? null);
            $slug = $this->nonEmpty($product['slug'] ?? null);
            $variants = $this->records($mapped['variants'] ?? null);
            $variant = $variants[0] ?? [];
            $sku = $this->nonEmpty($variant['sku'] ?? null);
            $pricing = is_array($mapped['pricing'] ?? null) ? $mapped['pricing'] : [];
            $vat = is_int($pricing['vat_rate'] ?? null) ? VatRate::tryFrom($pricing['vat_rate']) : null;
            $net = $pricing['net_minor'] ?? null;
            $gross = $pricing['gross_minor'] ?? null;
            $label = $externalId ?? '[missing external ID]';

            if ($externalId === null || isset($seenProducts[$externalId])) {
                $errors[] = $label.': missing/duplicate external ID.';
            }
            if ($slug === null || isset($seenSlugs[$slug])) {
                $errors[] = $label.': missing/duplicate product slug.';
            }
            if ($sku === null || isset($seenSkus[$sku]) || ! str_starts_with($sku, 'NEOX-')) {
                $errors[] = $label.': missing/duplicate/unprefixed variant SKU.';
            }
            if ($externalId !== null) {
                $seenProducts[$externalId] = true;
            }
            if ($slug !== null) {
                $seenSlugs[$slug] = true;
            }
            if ($sku !== null) {
                $seenSkus[$sku] = true;
            }
            if ($name === null || ($product['external_source'] ?? null) !== 'neoxmed'
                || ($product['status'] ?? null) !== 'draft'
                || ($product['external_parent_sku'] ?? null) !== $sku
                || count($variants) !== 1
                || ($variant['external_variant_id'] ?? null) !== 'neoxmed-'.$externalId.'-default'
                || ($variant['status'] ?? null) !== 'draft'
                || ($variant['stock_status'] ?? null) !== 'out_of_stock'
                || ($variant['is_default'] ?? null) !== true
                || ($mapped['availability']['planned_stock_status'] ?? null) !== 'out_of_stock'
                || ($mapped['sizing']['variant_generation_allowed'] ?? null) !== false) {
                $errors[] = $label.': unsafe product or placeholder-variant contract.';
            }
            if (! is_int($net) || $net <= 0 || ! is_int($gross) || $gross <= 0
                || $vat === null || $vat->grossFromNet(is_int($net) ? $net : 0) !== $gross
                || ($pricing['currency'] ?? null) !== 'PLN'
                || ($variant['price_net_minor'] ?? null) !== $net
                || ($variant['price_gross_minor'] ?? null) !== $gross
                || ($variant['vat_rate'] ?? null) !== $pricing['vat_rate']
                || ($variant['currency'] ?? null) !== 'PLN') {
                $errors[] = $label.': incomplete or inconsistent approved net/gross/VAT pricing.';
            }
            if ($this->records($mapped['categories'] ?? null) === []) {
                $errors[] = $label.': no mapped target categories.';
            }
            if ($this->records($mapped['images'] ?? null) === []) {
                $errors[] = $label.': approved normal product image missing.';
            }
            foreach (array_merge($this->records($mapped['images'] ?? null), $this->records($mapped['sizing']['size_chart_images'] ?? null)) as $image) {
                if ($this->safeImageHost($image['source_url'] ?? null) === null) {
                    $errors[] = $label.': unsafe or invalid HTTPS image URL.';
                }
            }
        }

        // Audit catches cross-source slug/SKU conflicts and missing/inactive category slugs.
        $audit = $products === [] ? ['errors' => ['Empty catalogue.']] : $this->databaseAudit->audit($map);
        foreach ($audit['errors'] ?? [] as $error) {
            $errors[] = 'Database audit: '.$error;
        }
        // Do not let a subsequent run overwrite products already activated or manually expanded.
        foreach (Product::withTrashed()->where('external_source', 'neoxmed')
            ->whereIn('external_id', array_keys($seenProducts))->get() as $existing) {
            if ($existing->trashed() || $existing->status !== ProductStatus::DRAFT) {
                $errors[] = $existing->external_id.': existing product is not an ordinary draft.';
            }
            if ($existing->variants()->count() > 1) {
                $errors[] = $existing->external_id.': existing product has manually expanded variants.';
            }
        }

        return [
            'database_writes' => false,
            'products' => count($products),
            'errors' => array_values(array_unique($errors)),
            'database_audit' => $audit,
            'safe' => $errors === [],
        ];
    }

    /**
     * Image downloads finish BEFORE the atomic database write. A download failure
     * cannot create half-populated products; retry is safe (content-addressed media).
     *
     * @param  array<string,mixed>  $map
     * @return array{selected:int,created:int,updated:int,images:int}
     */
    public function import(array $map, bool $importImages = true, ?int $limit = null, int $offset = 0): array
    {
        $check = $this->preflight($map);
        if ($check['safe'] !== true) {
            throw new InvalidArgumentException(implode(' | ', $check['errors']));
        }
        $selected = array_slice($this->records($map['products']), max(0, $offset), $limit);
        if ($selected === []) {
            throw new InvalidArgumentException('No NeoxMed products selected.');
        }
        $preparedMedia = [];
        if ($importImages) {
            foreach ($selected as $mapped) {
                $id = (string) $mapped['product']['external_id'];
                $preparedMedia[$id] = $this->downloadImages($mapped);
            }
        }

        return DB::transaction(function () use ($selected, $preparedMedia, $importImages): array {
            $created = 0;
            $updated = 0;
            $images = 0;
            foreach ($selected as $mapped) {
                [$product, $isNew] = $this->persistProduct($mapped);
                $this->syncCategories($product, $mapped);
                $this->syncAttributes($product, $mapped);
                $this->syncPlaceholderVariant($product, $mapped);
                if ($importImages) {
                    $images += $this->syncImages($product, $preparedMedia[(string) $product->external_id]);
                }
                $isNew ? $created++ : $updated++;
            }

            return ['selected' => count($selected), 'created' => $created, 'updated' => $updated, 'images' => $images];
        });
    }

    /** @param array<string,mixed> $mapped @return array{Product,bool} */
    private function persistProduct(array $mapped): array
    {
        $row = $mapped['product'];
        $id = (string) $row['external_id'];
        $existing = Product::withTrashed()->where('external_source', 'neoxmed')->where('external_id', $id)->lockForUpdate()->first();
        if ($existing !== null && ($existing->trashed() || $existing->status !== ProductStatus::DRAFT)) {
            throw new InvalidArgumentException($id.': refusing to overwrite a deleted or non-draft NeoxMed product.');
        }
        if (Product::withTrashed()->where('slug', $row['slug'])
            ->when($existing !== null, fn ($query) => $query->whereKeyNot($existing->id))->exists()) {
            throw new InvalidArgumentException($id.': product slug is already occupied.');
        }
        $description = (string) ($row['description_html'] ?? '');
        if ($description === '') {
            $description = (string) ($row['short_description_html'] ?? '');
        }
        $note = $this->nonEmpty($mapped['sizing']['size_note'] ?? null);
        if ($note !== null && ! str_contains(strip_tags($description), $note)) {
            $description .= '<p><strong>Rozmiary:</strong> '.e($note).'</p>';
        }
        $codes = $mapped['nfz']['codes'] ?? [];
        if (is_array($codes) && $codes !== []) {
            $description .= '<p><strong>Kody NFZ:</strong> '.e(implode(', ', array_filter($codes, 'is_string'))).'</p>';
        }
        if ($this->records($mapped['sizing']['size_chart_images'] ?? null) !== []) {
            $description .= '<p>Tabele rozmiarów znajdują się w galerii produktu.</p>';
        }
        $values = [
            'name' => $row['name'],
            'slug' => $row['slug'],
            'short_description' => $row['short_description_html'] ?? null,
            'description' => $description,
            'seo_title' => $row['seo_title'] ?? $row['name'],
            'seo_description' => $row['seo_description'] ?? null,
            'status' => ProductStatus::DRAFT,
            'published_at' => null,
            'external_source' => 'neoxmed',
            'external_id' => $id,
            'external_parent_sku' => $row['external_parent_sku'],
        ];
        if ($existing !== null) {
            $existing->update($values);

            return [$existing, false];
        }

        return [Product::query()->create($values), true];
    }

    /** @param array<string,mixed> $mapped */
    private function syncCategories(Product $product, array $mapped): void
    {
        $ids = [];
        foreach ($this->records($mapped['categories'] ?? null) as $entry) {
            $slug = $this->nonEmpty($entry['target_slug'] ?? null);
            $category = $slug === null ? null : Category::query()->where('slug', $slug)
                ->where('status', CategoryStatus::ACTIVE->value)->first();
            if ($category === null) {
                throw new InvalidArgumentException($product->external_id.': target category is unavailable: '.($slug ?? '[missing]'));
            }
            $ids[$category->id] = ['is_primary' => $ids === []];
        }
        if ($ids === []) {
            throw new InvalidArgumentException($product->external_id.': no target categories.');
        }
        $product->categories()->sync($ids);
    }

    /** @param array<string,mixed> $mapped */
    private function syncAttributes(Product $product, array $mapped): void
    {
        $attributes = [];
        $brand = $this->nonEmpty($mapped['product']['brand'] ?? null);
        if ($brand !== null) {
            $attributes[] = $this->attributeValue('Producent', $brand);
        }
        if (($mapped['medical_device']['is_medical_device'] ?? null) === true) {
            $attributes[] = $this->attributeValue('Wyrób medyczny', 'Tak');
        }
        foreach (($mapped['nfz']['codes'] ?? []) as $code) {
            if (($code = $this->nonEmpty($code)) !== null) {
                $attributes[] = $this->attributeValue('Kod NFZ', $code);
            }
        }
        $product->attributeValues()->sync(array_fill_keys(array_map(
            static fn (AttributeValue $value): int => $value->id,
            $attributes,
        ), []));
    }

    private function attributeValue(string $label, string $value): AttributeValue
    {
        $attribute = Attribute::query()->firstOrCreate(
            ['slug' => Str::slug($label)],
            ['name' => $label, 'display_type' => AttributeDisplayType::SELECT],
        );

        return AttributeValue::query()->firstOrCreate(
            ['attribute_id' => $attribute->id, 'slug' => Str::slug($value)],
            ['value' => Str::limit($value, 190, ''), 'sort_order' => 0],
        );
    }

    /** @param array<string,mixed> $mapped */
    private function syncPlaceholderVariant(Product $product, array $mapped): void
    {
        $row = $mapped['variants'][0];
        $pricing = $mapped['pricing'];
        $existing = ProductVariant::withTrashed()->where('product_id', $product->id)
            ->where('external_variant_id', $row['external_variant_id'])->lockForUpdate()->first();
        if ($existing !== null && ($existing->trashed() || $existing->status !== ProductVariantStatus::DRAFT)) {
            throw new InvalidArgumentException($product->external_id.': existing placeholder variant is not an ordinary draft.');
        }
        if ($product->variants()->whereKeyNot($existing?->id ?? 0)->exists()) {
            throw new InvalidArgumentException($product->external_id.': refusing to replace additional/manual variants.');
        }
        if (ProductVariant::withTrashed()->where('sku', $row['sku'])
            ->when($existing !== null, fn ($query) => $query->whereKeyNot($existing->id))->exists()) {
            throw new InvalidArgumentException($product->external_id.': SKU is already occupied.');
        }
        $values = [
            'product_id' => $product->id,
            'external_variant_id' => $row['external_variant_id'],
            'sku' => $row['sku'],
            'status' => ProductVariantStatus::DRAFT,
            'is_default' => true,
            'stock_status' => StockStatus::OUT_OF_STOCK,
            'currency' => Currency::PLN,
            'vat_rate' => VatRate::from($pricing['vat_rate']),
            'price_net_amount' => $pricing['net_minor'],
            'price_gross_amount' => $pricing['gross_minor'],
        ];
        if ($existing !== null) {
            $existing->update($values);
        } else {
            ProductVariant::query()->create($values);
        }
    }

    /** @param array<string,mixed> $mapped @return list<array<string,mixed>> */
    private function downloadImages(array $mapped): array
    {
        $id = (string) $mapped['product']['external_id'];
        $rows = [];
        $seen = [];
        $sources = array_merge(
            $this->records($mapped['images'] ?? null),
            $this->records($mapped['sizing']['size_chart_images'] ?? null),
        );
        foreach ($sources as $image) {
            $url = (string) $image['source_url'];
            if (isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;
            $host = $this->safeImageHost($url);
            if ($host === null) {
                throw new InvalidArgumentException($id.': invalid approved HTTPS image URL.');
            }
            $stored = $this->remoteImageImporter->import(
                $url,
                'products/neoxmed/'.Str::slug($id).'/gallery',
                'public',
                [$host],
            );
            $chart = ($image['role'] ?? 'product') === 'size_chart';
            $rows[] = array_merge($stored, [
                'alt_text' => $this->nonEmpty($image['alt'] ?? null)
                    ?? ($chart ? 'Tabela rozmiarów – '.$mapped['product']['name'] : $mapped['product']['name']),
                'is_product_image' => ! $chart,
            ]);
        }
        if ($rows === []) {
            throw new InvalidArgumentException($id.': no images downloaded.');
        }

        return $rows;
    }

    /** @param list<array<string,mixed>> $rows */
    private function syncImages(Product $product, array $rows): int
    {
        $existingMain = $product->images()->where('is_main', true)->exists();
        $first = null;
        $count = 0;
        foreach ($rows as $index => $row) {
            $isMain = ! $existingMain && $first === null && $row['is_product_image'];
            $image = ProductImage::updateOrCreate(
                ['product_id' => $product->id, 'source_url' => $row['source_url']],
                [
                    'disk' => $row['disk'],
                    'path' => $row['path'],
                    'mime_type' => $row['mime_type'],
                    'file_size' => $row['file_size'],
                    'sha256' => $row['sha256'],
                    'alt_text' => $row['alt_text'],
                    'title' => $row['alt_text'],
                    'sort_order' => $index,
                    'is_main' => $isMain || ($existingMain && $product->images()
                        ->where('source_url', $row['source_url'])->where('is_main', true)->exists()),
                ],
            );
            if ($first === null && $row['is_product_image']) {
                $first = $image;
                $existingMain = true;
            }
            $count++;
        }
        if ($product->default_image_id === null && $first !== null) {
            $product->update([
                'default_image_type' => Product::DEFAULT_IMAGE_TYPE_PRODUCT_IMAGE,
                'default_image_id' => $first->id,
            ]);
        }

        return $count;
    }

    /** Accept HTTPS FQDNs only; reject literal IPs, credentials, local hosts and custom ports. */
    private function safeImageHost(mixed $url): ?string
    {
        if (! is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }
        $parts = parse_url($url);
        if (! is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || isset($parts['user'], $parts['pass']) || isset($parts['user']) || isset($parts['port'])) {
            return null;
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (! preg_match('/^[a-z0-9-]+(?:\.[a-z0-9-]+)+$/', $host)
            || filter_var($host, FILTER_VALIDATE_IP) !== false
            || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')
            || str_ends_with($host, '.internal')) {
            return null;
        }

        return $host;
    }

    /** @return list<array<string,mixed>> */
    private function records(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
    }

    private function nonEmpty(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
