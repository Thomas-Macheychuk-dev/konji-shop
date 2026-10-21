<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Currency;
use App\Enums\ProductStatus;
use App\Enums\ProductVariantStatus;
use App\Enums\StockStatus;
use App\Enums\VatRate;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductAttributeValueImage;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;
use ZipArchive;

class ImportHerdegenReleaseCommand extends Command
{
    protected $signature = 'herdegen:import-release
        {--archive= : Absolute path to the verified HERDEGEN-18D4 ZIP}
        {--write-drafts : Import media and DB records as unpublished drafts}
        {--acknowledge-production-backup : Confirm production DB recovery point and S3 rollback plan exist}';

    protected $description = 'HERDEGEN-18E3: validated archive-to-S3 and draft-only DB import; never activates products.';

    private const ARCHIVE_SHA256 = 'ce1258c94cd7a973d753761f6259225cdb9978aadc0cd575c9003d33814e755c';

    private const MANIFEST_SHA256 = '902af36ab95d5dd6416e891f7b780b2e7222741c7854f3ec070af1eecb456cf2';

    private const CATEGORY_MAP = [
        'sprzet-pomocniczy-i-sanitarny-sprzet-sanitarny-lawki-nawannowe' => 'lawki-nawannowe',
    ];

    public function handle(): int
    {
        $zip = new ZipArchive();
        try {
            $archive = (string) $this->option('archive');
            $this->check($archive !== '' && is_file($archive) && is_readable($archive), 'Missing archive');
            $write = (bool) $this->option('write-drafts');
            $this->check(! $write || (bool) $this->option('acknowledge-production-backup'), 'Backup acknowledgement required');
            $this->check(hash_file('sha256', $archive) === self::ARCHIVE_SHA256, 'Archive SHA-256 mismatch');
            $this->check($zip->open($archive) === true, 'Cannot open ZIP');
            $this->check($zip->numFiles === 65, 'ZIP must have exactly 65 entries');
            $manifestFiles = [];
            $mediaEntries = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                $this->check(is_string($name) && ! str_contains($name, '..') && ! str_starts_with($name, '/') && ! str_contains($name, '\\'), 'Unsafe ZIP entry');
                if (str_ends_with($name, '.json')) {
                    $manifestFiles[] = $name;
                } else {
                    $this->check(str_starts_with($name, 'public/products/herdegen/'), 'Unexpected ZIP member');
                    $mediaEntries[$name] = true;
                }
            }
            $this->check(count($manifestFiles) === 1, 'Expected one JSON manifest');
            $raw = $zip->getFromName($manifestFiles[0]);
            $this->check(is_string($raw) && hash('sha256', $raw) === self::MANIFEST_SHA256, 'Manifest checksum mismatch');
            $data = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
            $this->check(is_array($data) && ($data['schema_version'] ?? null) === 1
                && ($data['source'] ?? null) === 'herdegen'
                && count($data['products'] ?? []) === 46 && count($data['media'] ?? []) === 64, 'Unexpected manifest identity/counts');
            $this->check(count($mediaEntries) === 64, 'Unexpected media member count');
            $this->check(count(array_unique(array_keys($mediaEntries))) === 64, 'Duplicate media entries');
            $paths = [];
            foreach ($data['media'] as $media) {
                $path = $media['path'] ?? null;
                $this->check(is_string($path) && preg_match('~^products/herdegen/[a-f0-9]{32}/[A-Za-z0-9_./-]+$~D', $path) === 1 && ! str_contains($path, '..'), 'Invalid media path');
                $this->check(! isset($paths[$path]) && isset($mediaEntries['public/'.$path]), 'Missing/duplicated archive media');
                $bytes = $zip->getFromName('public/'.$path);
                $this->check(is_string($bytes) && strlen($bytes) === $media['size']
                    && hash('sha256', $bytes) === $media['sha256'], 'Bad media checksum: '.$path);
                $paths[$path] = $media;
            }
            $productIds = [];
            $allSkus = [];
            $allExtVariants = [];
            $allCategories = [];
            $countVariants = 0;
            $expectedAttributes = [];
            $expectedValues = [];
            foreach ($data['products'] as $product) {
                $external = $product['external_id'] ?? null;
                $this->check(is_string($external) && preg_match('/^[a-f0-9]{32}$/D', $external) === 1 && ! isset($productIds[$external]), 'Invalid/duplicate product');
                $productIds[$external] = true;
                $this->check(filled($product['name'] ?? null) && filled($product['slug'] ?? null), 'Missing product name/slug');
                $categories = $product['categories'] ?? [];
                $this->check(is_array($categories) && count($categories) > 0 && count(array_filter($categories, fn ($x) => ($x['is_primary'] ?? null) === true)) === 1, 'Invalid primary category');
                foreach ($categories as $cat) {
                    $slug = $cat['slug'] ?? '';
                    $allCategories[self::CATEGORY_MAP[$slug] ?? $slug] = true;
                }
                foreach (array_merge($product['images'] ?? [], $product['attribute_value_images'] ?? []) as $image) {
                    $this->check(isset($paths[$image['path'] ?? '']) && ($image['sha256'] ?? null) === $paths[$image['path']]['sha256'], 'Image metadata/media mismatch');
                }
                $optionKeys = [];
                foreach ($product['variants'] ?? [] as $variant) {
                    $countVariants++;
                    $sku = $variant['sku'] ?? null;
                    $ext = $variant['external_variant_id'] ?? null;
                    $this->check(is_string($sku) && $sku !== '' && ! isset($allSkus[$sku]) && is_string($ext) && $ext !== '' && ! isset($allExtVariants[$ext]), 'Variant identity collision');
                    $allSkus[$sku] = true;
                    $allExtVariants[$ext] = true;
                    $this->check(($variant['currency'] ?? null) === 'PLN' && ($variant['vat_rate'] ?? null) == 8
                        && is_int($variant['price_gross_amount'] ?? null) && $variant['price_gross_amount'] > 0
                        && is_int($variant['price_net_amount'] ?? null) && $variant['price_net_amount'] > 0, 'Invalid price/tax');
                    foreach ($variant['option_values'] ?? [] as $option) {
                        $this->check(filled($option['attribute_slug'] ?? null) && filled($option['value_slug'] ?? null) && filled($option['value'] ?? null), 'Invalid option');
                        $attributeSlug = $option['attribute_slug'];
                        $valueSlug = $option['value_slug'];
                        $attributeName = $option['attribute_name'] ?? null;

                        $this->check(
                            is_string($attributeName) && $attributeName !== '',
                            'Missing attribute name'
                        );

                        $this->check(
                            ! isset($expectedAttributes[$attributeSlug])
                                || $expectedAttributes[$attributeSlug] === $attributeName,
                            'Conflicting exported attribute: '.$attributeSlug
                        );

                        $expectedAttributes[$attributeSlug] = $attributeName;

                        $optionKey = $attributeSlug.'|'.$valueSlug;

                        $this->check(
                            ! isset($expectedValues[$optionKey])
                                || $expectedValues[$optionKey] === $option['value'],
                            'Conflicting exported option: '.$optionKey
                        );

                        $expectedValues[$optionKey] = $option['value'];
                        $optionKeys[$optionKey] = $option;
                    }
                }
                foreach ($product['attribute_value_images'] ?? [] as $img) {
                    $this->check(isset($optionKeys[($img['attribute_slug'] ?? '').'|'.($img['value_slug'] ?? '')]), 'Orphan attribute image');
                }
            }
            $this->check($countVariants === 79, 'Expected exactly 79 variants');
            $this->check(
                count($expectedAttributes) === 22
                    && count($expectedValues) === 55,
                'Unexpected attribute inventory'
            );

            $existingAttributes = Attribute::query()
                ->whereIn('slug', array_keys($expectedAttributes))
                ->get()
                ->keyBy('slug');

            $existingAttributeValues = 0;

            foreach ($existingAttributes as $slug => $attribute) {
                $this->check(
                    $attribute->name === $expectedAttributes[$slug],
                    'Existing attribute name conflict: '.$slug
                );

                $values = AttributeValue::query()
                    ->where('attribute_id', $attribute->id)
                    ->get()
                    ->keyBy('slug');

                foreach ($expectedValues as $key => $expectedValue) {
                    [$expectedAttributeSlug, $expectedValueSlug] = explode('|', $key, 2);

                    if ($expectedAttributeSlug !== $slug) {
                        continue;
                    }

                    $existing = $values->get($expectedValueSlug);

                    if ($existing === null) {
                        continue;
                    }

                    $this->check(
                        $existing->value === $expectedValue,
                        'Existing attribute value conflict: '.$key
                    );

                    $existingAttributeValues++;
                }
            }

            $this->line('ATTRIBUTES_EXPECTED=22');
            $this->line('ATTRIBUTE_VALUES_EXPECTED=55');
            $this->line('ATTRIBUTES_EXISTING='.$existingAttributes->count());
            $this->line('ATTRIBUTE_VALUES_EXISTING='.$existingAttributeValues);
            $this->line('ATTRIBUTES_TO_CREATE='.(22 - $existingAttributes->count()));
            $this->line('ATTRIBUTE_VALUES_TO_CREATE='.(55 - $existingAttributeValues));
            $this->line('ATTRIBUTE_COMPATIBILITY=PASS');

            $categories = Category::query()->whereIn('slug', array_keys($allCategories))->get()->keyBy('slug');
            $this->check($categories->count() === count($allCategories) && $categories->every(fn ($c) => $c->deleted_at === null && $c->status?->value === 'active'), 'Production categories missing/inactive');
            $this->check(Product::withTrashed()->where('external_source', 'herdegen')->count() === 0, 'Herdegen products already exist; refusing overwrite');
            $this->check(ProductVariant::withTrashed()->whereIn('sku', array_keys($allSkus))->count() === 0, 'SKU collision');
            $this->check(ProductVariant::withTrashed()->whereIn('external_variant_id', array_keys($allExtVariants))->count() === 0, 'Variant external-ID collision');
            $slugs = array_column($data['products'], 'slug');
            $this->check(count(array_unique($slugs)) === 46 && ! Product::withTrashed()->whereIn('slug', $slugs)->exists(), 'Product slug collision');
            $disk = Storage::disk('public');
            $this->check((string) config('filesystems.disks.public.driver') === 's3' || ! $write, 'Production write requires public S3 disk');
            $this->line('MODE='.($write ? 'WRITE_DRAFTS' : 'DRY_RUN'));
            $this->line('PRODUCTS=46 VARIANTS=79 MEDIA=64 CATEGORIES='.count($allCategories));
            $this->line('ARCHIVE_SHA256='.hash_file('sha256', $archive));
            if (! $write) {
                $this->info('HERDEGEN18E3=PASS_DRY_RUN');
                $this->line('DATABASE_WRITES=0 RELEASE_GATE=HOLD');
                return self::SUCCESS;
            }
            // S3 has no DB transaction; upload immutable checksum-named objects before the DB transaction.
            // Rollback must remove only newly uploaded objects, after checking no references remain.
            $newUploads = [];
            try {
                foreach ($paths as $path => $media) {
                    $content = $zip->getFromName('public/'.$path);
                    $this->check(is_string($content), 'Archive image missing');
                    if ($disk->exists($path)) {
                        $existing = $disk->get($path);
                        $this->check(is_string($existing) && hash('sha256', $existing) === $media['sha256'], 'Existing S3 object collision: '.$path);
                        continue;
                    }
                    $this->check($disk->put($path, $content) === true, 'S3 upload failed: '.$path);
                    $newUploads[] = $path;
                    $stored = $disk->get($path);
                    $this->check(is_string($stored) && hash('sha256', $stored) === $media['sha256'], 'S3 verification failed: '.$path);
                }
                DB::transaction(function () use ($data, $categories, $paths): void {
                    foreach ($data['products'] as $p) {
                        $product = Product::query()->create([
                            'external_source' => 'herdegen', 'external_id' => $p['external_id'], 'name' => $p['name'],
                            'slug' => $p['slug'], 'short_description' => $p['short_description'] ?? null,
                            'description' => $p['description'] ?? null, 'seo_title' => $p['seo_title'] ?? null,
                            'seo_description' => $p['seo_description'] ?? null,
                            'external_parent_sku' => $p['external_parent_sku'] ?? null,
                            'has_hygienic_seal' => (bool) ($p['has_hygienic_seal'] ?? false),
                            'is_custom_made' => (bool) ($p['is_custom_made'] ?? false),
                            'show_compression_measurement_notice' => (bool) ($p['show_compression_measurement_notice'] ?? false),
                            'status' => ProductStatus::DRAFT, 'published_at' => null,
                        ]);
                        $catSync = [];
                        foreach ($p['categories'] as $cat) {
                            $slug = self::CATEGORY_MAP[$cat['slug']] ?? $cat['slug'];
                            $catSync[$categories[$slug]->id] = ['is_primary' => (bool) $cat['is_primary']];
                        }
                        $product->categories()->sync($catSync);
                        $optionIdMap = [];
                        foreach ($p['variants'] as $v) {
                            $variant = $product->variants()->create([
                                'external_variant_id' => $v['external_variant_id'], 'sku' => $v['sku'],
                                'price_gross_amount' => $v['price_gross_amount'], 'price_net_amount' => $v['price_net_amount'],
                                'vat_rate' => VatRate::from((int) $v['vat_rate']), 'currency' => Currency::PLN,
                                'is_default' => (bool) ($v['is_default'] ?? false),
                                'status' => ProductVariantStatus::DRAFT, 'stock_status' => StockStatus::OUT_OF_STOCK,
                            ]);
                            foreach ($v['option_values'] ?? [] as $o) {
                                $attribute = Attribute::query()->firstOrCreate(['slug' => $o['attribute_slug']], ['name' => $o['attribute_name']]);
                                $this->check($attribute->name === $o['attribute_name'], 'Attribute mismatch');
                                $value = AttributeValue::query()->firstOrCreate(
                                    ['attribute_id' => $attribute->id, 'slug' => $o['value_slug']], ['value' => $o['value']]);
                                $this->check($value->value === $o['value'], 'Attribute value mismatch');
                                $variant->attributeValues()->syncWithoutDetaching([$value->id]);
                                $product->attributeValues()->syncWithoutDetaching([$value->id]);
                                $optionIdMap[$o['attribute_slug'].'|'.$o['value_slug']] = $value->id;
                            }
                        }
                        foreach ($p['images'] ?? [] as $img) {
                            $product->images()->create(
                                array_replace($this->imageValues($img), [
                                    'file_size' => $paths[$img['path']]['size'],
                                ])
                            );
                        }
                        foreach ($p['attribute_value_images'] ?? [] as $img) {
                            $key = $img['attribute_slug'].'|'.$img['value_slug'];
                            $this->check(isset($optionIdMap[$key]), 'Missing option for image');
                            $product->attributeValueImages()->create(
                                array_replace($this->imageValues($img), [
                                    'file_size' => $paths[$img['path']]['size'],
                                    'attribute_value_id' => $optionIdMap[$key],
                                ])
                            );
                        }
                    }
                });
                $this->info('HERDEGEN18E3=PASS_DRAFT_IMPORT');
                $this->line('PRODUCTS=46 VARIANTS=79 MEDIA=64 RELEASE_GATE=HOLD');
                return self::SUCCESS;
            } catch (Throwable $e) {
                // Do NOT delete uploaded images automatically; later objects/DB may reference them.
                $this->error('New S3 object count before failure: '.count($newUploads));
                $this->error('Review S3 uploads and DB rollback before retry.');
                throw $e;
            }
        } catch (Throwable $e) {
            $this->error('HERDEGEN18E3=FAIL: '.$e->getMessage());
            return self::FAILURE;
        } finally {
            $zip->close();
        }
    }

    private function imageValues(array $image): array
    {
        return [
            'disk' => 'public', 'path' => $image['path'], 'sha256' => $image['sha256'],
            'mime_type' => $image['mime_type'] ?? null,
            'file_size' => $image['file_size'] ?? null,
            'alt_text' => $image['alt_text'] ?? null, 'title' => $image['title'] ?? null,
            'sort_order' => (int) ($image['sort_order'] ?? 0),
            'is_main' => (bool) ($image['is_main'] ?? false),
            'source_url' => $image['source_url'] ?? null,
        ];
    }

    private function check(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new RuntimeException($message);
        }
    }
}
