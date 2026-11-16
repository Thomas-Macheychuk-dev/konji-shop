<?php

declare(strict_types=1);

namespace App\Services\Corradomed;

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
use App\Models\Manufacturer;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Services\Images\RemoteImageImporter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Imports corradomed.pl pages (CorradomedPageScraper output) as draft products priced from the Corradomed
 * price list. One product per page (one finish, as on corradomed.pl and in the old shop); one variant per
 * code and colour the list prices. Codes without a list price are left out and reported. Re-running
 * updates prices and content in place and keeps the product status.
 */
final class CorradomedProductImporter
{
    public const SOURCE = 'corradomed';

    public const BRAND = 'Corradomed';

    public const ROOT_CATEGORY = ['slug' => 'corradomed', 'name' => 'Corradomed'];

    /**
     * GPSR manufacturer details from corradomed.pl (footer and contact page) and the price list letterhead.
     */
    public const MANUFACTURER = [
        'name' => 'Corradomed Sp. z o.o.',
        'address' => 'ul. Dobromyśl 5, 25-825 Kielce, Polska',
        'email' => 'biuro@corradomed.pl',
        'phone' => '+48 41 346 47 73',
        'website' => 'https://corradomed.pl',
    ];

    private const COLOUR_WHITE = 'biały (RAL 9003)';

    private const COLOUR_BLACK = 'czarny (RAL 9005)';

    private const SKU_SUFFIX = [
        'painted_25_white' => 'P25W', 'painted_25_black' => 'P25B', 'inox_25' => 'X25', 'inox_32' => 'X32',
        'masked_32_white' => 'M32W', 'masked_32_black' => 'M32B', 'painted_32_white' => 'P32W', 'painted_32_black' => 'P32B',
    ];

    private const MAX_DATABASE_STRING_LENGTH = 190;

    /**
     * @var list<string>
     */
    private array $warnings = [];

    public function __construct(
        private readonly RemoteImageImporter $remoteImageImporter,
    ) {}

    /**
     * What a page becomes: the product name, category and priced variants, plus the codes the list does
     * not price. Pure; used by the dry run and the import.
     *
     * @param  array<string, mixed>  $page
     * @return array{external_id: string, name: string, category: list<string>, variant_attribute: string|null, variants: list<array<string, mixed>>, unpriced_codes: list<string>, list_codes: list<string>}
     */
    public function plan(array $page, CorradomedPriceList $priceList): array
    {
        $pageKey = (string) ($page['page'] ?? '');

        if ($pageKey === '') {
            throw new InvalidArgumentException('Corradomed page data is missing its page key.');
        }

        $finish = $this->finish($page);
        $codes = $priceList->pageCodes($pageKey) ?? array_values(array_map('strval', (array) ($page['codes'] ?? [])));
        $variants = [];
        $unpriced = [];
        $listCodes = [];

        foreach ($codes as $code) {
            $item = $priceList->find($code);
            $found = false;

            foreach ($item === null ? [] : $finish['columns'] as $column => $label) {
                $net = $item['prices'][$column] ?? null;
                $vat = VatRate::tryFrom($item['vat_rate']);

                if ($net === null || $vat === null) {
                    continue;
                }

                $found = true;
                $model = $this->displayCode($code);
                $gross = CorradomedPriceList::grossMinorUnits($net, $vat);
                $variants[] = [
                    'external_variant_id' => $this->limitDatabaseString(self::SOURCE.'-'.$this->externalId($pageKey).'-'.CorradomedPriceList::normaliseCode($code).'-'.$column),
                    'sku' => $this->normaliseSku('CRD-'.CorradomedPriceList::normaliseCode($code).'-'.self::SKU_SUFFIX[$column]),
                    'model' => $model,
                    'option' => $label,
                    'list_code' => $item['code'],
                    'column' => $column,
                    'list_net' => $net,
                    'vat_rate' => $vat->value,
                    'price_gross_amount' => $gross,
                    'price_net_amount' => $vat->netFromGross($gross),
                    'made_to_order' => $item['made_to_order'],
                ];
            }

            // On pages without a finish in the heading, a model sold in one frame finish only gets no finish
            // label (a chrome-base stool is not "malowany proszkowo, biały").
            $codeVariants = array_keys(array_filter($variants, fn (array $variant): bool => $variant['model'] === $this->displayCode($code)));

            if ($finish['attribute'] === 'Wykończenie' && count($codeVariants) === 1) {
                $variants[$codeVariants[0]]['option'] = null;
            }

            if ($found) {
                $listCodes[] = $item['code'];
            } else {
                $unpriced[] = $code;
            }
        }

        return [
            'external_id' => $this->externalId($pageKey),
            'name' => $this->name($page, $priceList),
            'category' => array_values(array_filter([
                $this->stringOrNull($page['section'] ?? null),
                $this->stringOrNull($page['subsection'] ?? null),
            ])),
            'variant_attribute' => $finish['attribute'],
            'variants' => $variants,
            'unpriced_codes' => $unpriced,
            'list_codes' => array_values(array_unique($listCodes)),
        ];
    }

    /**
     * @param  array<string, mixed>  $page
     * @return array{product: Product, warnings: list<string>}
     */
    public function import(array $page, CorradomedPriceList $priceList, bool $importImages = true, ?int $imageLimit = 10): array
    {
        $this->warnings = [];
        $plan = $this->plan($page, $priceList);

        if ($plan['variants'] === []) {
            throw new InvalidArgumentException('No code on '.$page['page'].' has a list price.');
        }

        $product = DB::transaction(function () use ($page, $plan, $importImages, $imageLimit): Product {
            $product = $this->resolveProduct($page, $plan);

            $this->syncCategory($product, $plan['category']);
            $this->syncProductAttributes($product, $plan);
            $this->syncVariants($product, $plan);

            if ($importImages) {
                $this->syncImages($product, $page, $imageLimit);
            }

            return $product->fresh(['categories', 'images', 'variants.attributeValues.attribute']);
        });

        return ['product' => $product, 'warnings' => $this->warnings];
    }

    /**
     * Archives Corradomed products whose page is gone from the data. Returns their names.
     *
     * @param  list<string>  $keepExternalIds
     * @return list<string>
     */
    public function archiveMissing(array $keepExternalIds, bool $dryRun): array
    {
        $missing = Product::query()
            ->where('external_source', self::SOURCE)
            ->where('status', '!=', ProductStatus::ARCHIVED->value)
            ->whereNotIn('external_id', $keepExternalIds)
            ->orderBy('id')
            ->get(['id', 'name']);

        if (! $dryRun && $missing->isNotEmpty()) {
            DB::transaction(function () use ($missing): void {
                ProductVariant::query()->whereIn('product_id', $missing->pluck('id'))->update(['status' => ProductVariantStatus::ARCHIVED->value]);
                Product::query()->whereKey($missing->pluck('id'))->update(['status' => ProductStatus::ARCHIVED->value]);
            });
        }

        return $missing->map(fn (Product $product): string => '#'.$product->id.' '.$product->name)->all();
    }

    /**
     * Price columns a page sells, from the finish in its heading. Pages without a finish (medical furniture,
     * shower stool) offer the frame finishes the list prices.
     *
     * @param  array<string, mixed>  $page
     * @return array{attribute: string|null, columns: array<string, string|null>}
     */
    private function finish(array $page): array
    {
        $text = mb_strtolower(($page['title'] ?? '').' '.($page['subtitle'] ?? ''));
        $is32 = preg_match('/(ø|fi)\s*32/u', $text) === 1;

        if (str_contains($text, 'inox') || str_contains($text, 'stal nierdzewna')) {
            return ['attribute' => null, 'columns' => [$is32 ? 'inox_32' : 'inox_25' => null]];
        }

        if (str_contains($text, 'maskownic')) {
            return ['attribute' => 'Kolor', 'columns' => ['masked_32_white' => self::COLOUR_WHITE, 'masked_32_black' => self::COLOUR_BLACK]];
        }

        if (str_contains($text, 'malowan')) {
            return $is32
                ? ['attribute' => 'Kolor', 'columns' => ['painted_32_white' => self::COLOUR_WHITE, 'painted_32_black' => self::COLOUR_BLACK]]
                : ['attribute' => 'Kolor', 'columns' => ['painted_25_white' => self::COLOUR_WHITE, 'painted_25_black' => self::COLOUR_BLACK]];
        }

        return ['attribute' => 'Wykończenie', 'columns' => [
            'painted_25_white' => 'malowany proszkowo, '.self::COLOUR_WHITE,
            'painted_25_black' => 'malowany proszkowo, '.self::COLOUR_BLACK,
            'inox_25' => 'stal nierdzewna',
        ]];
    }

    /**
     * @param  array<string, mixed>  $page
     * @param  array<string, mixed>  $plan
     */
    private function resolveProduct(array $page, array $plan): Product
    {
        $product = Product::withTrashed()
            ->where('external_source', self::SOURCE)
            ->where('external_id', $plan['external_id'])
            ->first();

        $name = $this->limitDatabaseString($plan['name']);
        $attributes = [
            'name' => $name,
            'slug' => $product?->slug ?? $this->uniqueProductSlug(Str::slug($name) ?: 'corradomed-'.$plan['external_id'], null),
            'short_description' => '<p>'.e($this->summary($page, $plan)).'</p>',
            'description' => $this->descriptionHtml($page, $plan),
            'seo_title' => $name,
            'seo_description' => Str::limit($this->summary($page, $plan), 300, ''),
            'status' => $product?->status ?? ProductStatus::DRAFT,
            'external_source' => self::SOURCE,
            'external_id' => $plan['external_id'],
            'external_parent_sku' => $this->limitDatabaseString($this->normaliseSku('CRD-'.$plan['external_id'])),
        ];

        if ($product === null) {
            return Product::query()->create($attributes);
        }

        if ($product->trashed()) {
            $product->restore();
        }

        $product->update($attributes);

        return $product;
    }

    /**
     * @param  list<string>  $path
     */
    private function syncCategory(Product $product, array $path): void
    {
        $parent = $this->category(self::ROOT_CATEGORY['slug'], self::ROOT_CATEGORY['name'], null);
        $slugPath = self::ROOT_CATEGORY['slug'];

        foreach ($path as $name) {
            $slugPath .= '-'.Str::slug($name);
            $parent = $this->category($this->limitDatabaseString($slugPath), $name, $parent->id);
        }

        $product->categories()->sync([$parent->id => ['is_primary' => true]]);
    }

    private function category(string $slug, string $name, ?int $parentId): Category
    {
        $category = Category::withTrashed()->where('slug', $slug)->first();

        if ($category === null) {
            return Category::query()->create([
                'parent_id' => $parentId,
                'name' => $this->limitDatabaseString($name),
                'slug' => $slug,
                'status' => CategoryStatus::ACTIVE,
            ]);
        }

        if ($category->trashed()) {
            $category->restore();
        }

        return $category;
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function syncProductAttributes(Product $product, array $plan): void
    {
        $brand = $this->resolveAttributeValue('Producent', self::BRAND);

        if (! $brand->manufacturer()->exists()) {
            Manufacturer::query()->create(['attribute_value_id' => $brand->id] + self::MANUFACTURER);
        }

        $ids = [$brand->id => []];

        // 8% VAT on these goods applies to medical devices; the list rates every 8% item that way.
        if (collect($plan['variants'])->every(fn (array $variant): bool => $variant['vat_rate'] === VatRate::VAT_8->value)) {
            $ids[$this->resolveAttributeValue('Wyrób medyczny', 'Tak')->id] = [];
        }

        $product->attributeValues()->sync($ids);
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function syncVariants(Product $product, array $plan): void
    {
        $status = match ($product->status) {
            ProductStatus::ACTIVE => ProductVariantStatus::ACTIVE,
            ProductStatus::ARCHIVED => ProductVariantStatus::ARCHIVED,
            ProductStatus::DRAFT => ProductVariantStatus::DRAFT,
        };
        $syncedIds = [];

        foreach ($plan['variants'] as $index => $candidate) {
            $variant = ProductVariant::withTrashed()->where('external_variant_id', $candidate['external_variant_id'])->first();
            $vat = VatRate::from($candidate['vat_rate']);
            $attributes = [
                'product_id' => $product->id,
                'external_variant_id' => $candidate['external_variant_id'],
                'sku' => $this->uniqueSku($candidate['sku'], $variant?->id),
                'status' => $status,
                'price_net_amount' => $candidate['price_net_amount'],
                'price_gross_amount' => $candidate['price_gross_amount'],
                'currency' => Currency::PLN,
                'vat_rate' => $vat,
                'stock_status' => StockStatus::IN_STOCK,
                'is_default' => $index === 0,
            ];

            if ($variant === null) {
                $variant = ProductVariant::query()->create($attributes);
            } else {
                if ($variant->trashed()) {
                    $variant->restore();
                }

                $variant->update($attributes);
            }

            $valueIds = [$this->resolveAttributeValue('Model', $candidate['model'])->id];

            if ($plan['variant_attribute'] !== null && $candidate['option'] !== null) {
                $valueIds[] = $this->resolveAttributeValue($plan['variant_attribute'], $candidate['option'])->id;
            }

            $variant->attributeValues()->sync($valueIds);
            $syncedIds[] = $variant->id;
        }

        ProductVariant::query()
            ->where('product_id', $product->id)
            ->whereNotIn('id', $syncedIds)
            ->delete();
    }

    /**
     * @param  array<string, mixed>  $page
     */
    private function syncImages(Product $product, array $page, ?int $imageLimit): void
    {
        $rows = [];

        foreach (array_values(array_unique(array_map('strval', (array) ($page['images'] ?? [])))) as $url) {
            if ($imageLimit !== null && count($rows) >= $imageLimit) {
                break;
            }

            try {
                $imported = $this->remoteImageImporter->import(
                    $url,
                    'products/corradomed/'.$product->external_id.'/gallery',
                    'public',
                    [CorradomedPageScraper::HOST],
                );
            } catch (Throwable $exception) {
                $this->warnings[] = 'Image skipped for '.$product->external_id.': '.$url.' — '.$exception->getMessage();

                continue;
            }

            $rows[] = $imported;
        }

        if ($rows === []) {
            if (($page['images'] ?? []) !== []) {
                $this->warnings[] = 'No images were imported for '.$product->external_id.'; existing images were kept.';
            }

            return;
        }

        ProductImage::query()
            ->where('product_id', $product->id)
            ->whereNotIn('path', array_column($rows, 'path'))
            ->delete();

        $alt = $this->limitDatabaseString($product->name);

        foreach ($rows as $order => $row) {
            ProductImage::updateOrCreate(
                ['product_id' => $product->id, 'path' => $row['path']],
                [
                    'disk' => $row['disk'],
                    'source_url' => $row['source_url'],
                    'mime_type' => $row['mime_type'],
                    'file_size' => $row['file_size'],
                    'sha256' => $row['sha256'],
                    'alt_text' => $alt,
                    'title' => $alt,
                    'is_main' => $order === 0,
                    'sort_order' => $order,
                ],
            );
        }
    }

    /**
     * @param  array<string, mixed>  $page
     */
    private function name(array $page, CorradomedPriceList $priceList): string
    {
        $title = $priceList->pageName((string) $page['page'])
            ?? $this->stringOrNull($page['title'] ?? null)
            ?? (string) $page['page'];
        $subtitle = $this->subtitle($page);

        return self::BRAND.' '.($subtitle === null ? $title : $title.', '.$subtitle);
    }

    /**
     * The heading's finish line without "- różne rozmiary" (sizes are variants here).
     *
     * @param  array<string, mixed>  $page
     */
    private function subtitle(array $page): ?string
    {
        $subtitle = $this->stringOrNull($page['subtitle'] ?? null);

        if ($subtitle === null) {
            return null;
        }

        $subtitle = trim(preg_replace('/\s*-\s*różne rozmiary\s*$/iu', '', $subtitle) ?? $subtitle, ' -–');

        return $subtitle === '' ? null : $subtitle;
    }

    /**
     * @param  array<string, mixed>  $page
     * @param  array<string, mixed>  $plan
     */
    private function summary(array $page, array $plan): string
    {
        $models = array_values(array_unique(array_column($plan['variants'], 'model')));
        $summary = Str::after($plan['name'], self::BRAND.' ').'.';

        if (count($models) > 1) {
            $summary .= ' Dostępne modele: '.implode(', ', $models).'.';
        }

        $options = array_values(array_unique(array_filter(array_column($plan['variants'], 'option'))));

        if (count($options) > 1) {
            $summary .= ' '.($plan['variant_attribute'] ?? 'Wykończenie').': '.implode(', ', $options).'.';
        }

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $page
     * @param  array<string, mixed>  $plan
     */
    private function descriptionHtml(array $page, array $plan): string
    {
        $sections = ['<p>'.e($this->summary($page, $plan)).'</p>'];

        foreach ((array) ($page['description_paragraphs'] ?? []) as $block) {
            $text = is_array($block) ? $this->stringOrNull($block['text'] ?? null) : null;

            if ($text !== null) {
                $sections[] = ($block['type'] ?? 'p') === 'h2' ? '<h2>'.e($text).'</h2>' : '<p>'.e($text).'</p>';
            }
        }

        foreach ((array) ($page['notes'] ?? []) as $note) {
            $sections[] = '<p><strong>Informacja:</strong> '.e((string) $note).'</p>';
        }

        $colours = array_values(array_filter(array_map('strval', (array) ($page['upholstery_colours'] ?? []))));

        if ($colours !== []) {
            $sections[] = '<p><strong>Kolory tapicerki:</strong> '.e(implode(', ', $colours)).'. Kolor tapicerki prosimy podać w uwagach do zamówienia.</p>';
        }

        $madeToOrder = array_values(array_unique(array_column(array_filter($plan['variants'], fn (array $v): bool => $v['made_to_order']), 'model')));

        if ($madeToOrder !== []) {
            $sections[] = '<p><strong>Na zamówienie:</strong> '.e(implode(', ', $madeToOrder)).' wykonywane są na zamówienie, czas realizacji ok. 20–25 dni roboczych.</p>';
        }

        $table = $this->specificationTable($page, $plan);

        if ($table !== null) {
            $sections[] = $table;
        }

        return implode("\n", $sections);
    }

    /**
     * Dimension table of the models sold (corradomed.pl table, rows the list prices).
     *
     * @param  array<string, mixed>  $page
     * @param  array<string, mixed>  $plan
     */
    private function specificationTable(array $page, array $plan): ?string
    {
        $sold = array_flip(array_column($plan['variants'], 'model'));
        $rows = array_values(array_filter(
            (array) ($page['specification'] ?? []),
            fn ($row): bool => is_array($row) && isset($sold[$this->displayCode((string) ($row['code'] ?? ''))]) && ($row['values'] ?? []) !== [],
        ));

        if ($rows === []) {
            return null;
        }

        $headers = [];

        foreach ($rows as $row) {
            foreach (array_keys($row['values']) as $label) {
                $headers[$label] = true;
            }
        }

        $html = '<h2>Wymiary</h2><table><thead><tr><th>Model</th>';

        foreach (array_keys($headers) as $label) {
            $html .= '<th>'.e((string) $label).'</th>';
        }

        $html .= '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $html .= '<tr><td>'.e($this->displayCode((string) $row['code'])).'</td>';

            foreach (array_keys($headers) as $label) {
                $html .= '<td>'.e((string) ($row['values'][$label] ?? '')).'</td>';
            }

            $html .= '</tr>';
        }

        return $html.'</tbody></table>';
    }

    /**
     * Site code without the finish already named by the product ("UP - 2 INOX ø 25" → "UP-2").
     */
    private function displayCode(string $code): string
    {
        $code = preg_replace('/\s*ø\s*\d+|\s+INOX\b|\s+M(?=\s|$)|\s*\*/u', '', $code) ?? $code;
        $code = preg_replace('/\s*-\s*/u', '-', $code) ?? $code;

        return trim(preg_replace('/\s+/u', ' ', $code) ?? $code);
    }

    private function externalId(string $page): string
    {
        return str_replace('?do=', '-', $page);
    }

    private function resolveAttributeValue(string $attributeName, string $value): AttributeValue
    {
        $attribute = Attribute::query()->firstOrCreate(
            ['slug' => Str::slug($attributeName)],
            ['name' => $attributeName, 'external_attribute_id' => null, 'display_type' => AttributeDisplayType::SELECT],
        );

        return AttributeValue::query()->firstOrCreate(
            [
                'attribute_id' => $attribute->id,
                'slug' => Str::slug(str_replace(['/', '\\', ',', '.'], '-', $value)) ?: 'corradomed-'.substr(sha1($value), 0, 10),
            ],
            ['value' => $this->limitDatabaseString($value), 'external_option_id' => null, 'sort_order' => 0],
        );
    }

    private function uniqueProductSlug(string $baseSlug, ?int $currentProductId): string
    {
        $baseSlug = $this->limitDatabaseString($baseSlug);
        $candidate = $baseSlug;
        $suffix = 2;

        while (Product::withTrashed()
            ->where('slug', $candidate)
            ->when($currentProductId !== null, fn ($query) => $query->whereKeyNot($currentProductId))
            ->exists()) {
            $candidate = $this->limitDatabaseString($baseSlug.'-'.$suffix);
            $suffix++;
        }

        return $candidate;
    }

    private function uniqueSku(string $sku, ?int $currentVariantId): string
    {
        $sku = $this->limitDatabaseString($sku);
        $candidate = $sku;
        $suffix = 2;

        while (ProductVariant::withTrashed()
            ->where('sku', $candidate)
            ->when($currentVariantId !== null, fn ($query) => $query->whereKeyNot($currentVariantId))
            ->exists()) {
            $candidate = $this->limitDatabaseString($sku.'-'.$suffix);
            $suffix++;
        }

        return $candidate;
    }

    private function normaliseSku(string $sku): string
    {
        $sku = str_replace(['/', '\\', ','], '-', trim($sku));
        $sku = preg_replace('/[^A-Za-z0-9._-]+/', '-', $sku) ?? $sku;
        $sku = preg_replace('/-+/', '-', $sku) ?? $sku;

        return Str::upper(trim($sku, '-._'));
    }

    private function limitDatabaseString(string $value): string
    {
        $value = trim($value);

        if (mb_strlen($value) <= self::MAX_DATABASE_STRING_LENGTH) {
            return $value;
        }

        return rtrim(mb_substr($value, 0, self::MAX_DATABASE_STRING_LENGTH - 11), '-_ .').'_'.substr(sha1($value), 0, 10);
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
