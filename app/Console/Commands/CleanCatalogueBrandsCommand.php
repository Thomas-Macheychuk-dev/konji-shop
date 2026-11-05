<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AttributeValue;
use App\Models\Product;
use App\Services\Products\ProductBrandAttribute;
use App\Services\Storefront\ProductListing;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Tidies the manufacturer ("Producent"/"Marka") values the storefront brand filter lists.
 *
 * - Strips legal forms and addresses: "Armedical Sp. z o.o." and a whole "Reha Fund Sp. z o.o. ul. …"
 *   imprint become "Armedical" and "Reha Fund".
 * - Merges values of one attribute that are the same brand once cleaned, moving their products to one value.
 * - Gives Reh4Mat-imported products without a manufacturer the brand from their title suffix (" | Reh4Mat").
 *
 * Every write is backed up so --rollback restores names and product links.
 */
final class CleanCatalogueBrandsCommand extends Command
{
    protected $signature = 'catalogue:clean-brands
        {--write : Apply the changes after writing a private backup. Otherwise preview only.}
        {--rollback= : Undo the changes recorded in a backup written by --write.}';

    protected $description = 'Preview, apply or roll back manufacturer name clean-up, merges and missing Reh4Mat brands.';

    // A legal form ends the brand name wherever it appears: "Löwenstein Medical Technology GmbH + Co. KG Kronsaalsweg 40".
    // A separator is required before it, and short forms must stand alone, so "Medico" or "Sanitas" keep their names.
    private const LEGAL_FORM = '/[\s,.]+(?:sp\.?\s*z\s*o\.?\s*o|sp\.?\s*[jk]\.|spółka\b|s\.?\s*p\.?\s*a\.?(?=[\s,.(]|$)|s\.?\s*r\.?\s*[lo]\.?(?=[\s,.(]|$)|s\.?\s*a\.?(?:\s*e\.?)?(?=[\s,.(]|$)|a\.\s*s\.|b\.?\s*v\.?(?=[\s,.(]|$)|gmbh|ag(?=[\s,.(]|$)|ltd|llc|inc\b|corp\b|pty\b|co\.)/iu';

    // Imprints, notified-body numbers and leaflet text that follow the name.
    private const IMPRINT_START = '/\s*(?:\bnumer jednostki notyfikowanej\b|\bul\.|\bulica\b|\bNIP\b|\bKRS\b|\btel\.|\be-mail\b|\bwww\.|\bpodmiot\b|\bupoważniony\b|\bimporter\b|\bdystrybutor\b|\bsposób użycia\b)/iu';

    // "TZMO S.A. (marka Seni)" or "Stella Pack S.A. marka Service": the brand shoppers know is the "marka".
    private const MARKA = '/\bmarka\s+([^()]+?)\s*(?:\)|\bnumer jednostki|$)/iu';

    public function handle(ProductBrandAttribute $brandAttribute): int
    {
        if (filled($this->option('rollback'))) {
            return $this->rollback((string) $this->option('rollback'));
        }

        $values = AttributeValue::query()
            ->whereHas('attribute', fn ($query) => $query->whereIn('slug', ProductListing::BRAND_ATTRIBUTE_SLUGS))
            ->withCount('products')
            ->orderBy('id')
            ->get();

        $plan = $this->plan($values);
        $missing = $this->missingReh4MatBrands();

        $this->line('=== CATALOGUE BRANDS ===');
        $this->line('BRAND_VALUES='.$values->count());
        $this->line('RENAMES='.count($plan['renames']));
        $this->line('MERGES='.count($plan['merges']).' (PRODUCT_LINKS_MOVED='.array_sum(array_column($plan['merges'], 'products')).')');
        $this->line('REH4MAT_WITHOUT_BRAND='.count($missing).' '.json_encode(array_count_values(array_column($missing, 'brand')), JSON_UNESCAPED_UNICODE));

        foreach ($plan['renames'] as $rename) {
            $this->line(sprintf('RENAME value=%d "%s" -> "%s" (%d products)', $rename['id'], Str::limit($rename['from'], 80), $rename['to'], $rename['products']));
        }

        foreach ($plan['merges'] as $merge) {
            $this->line(sprintf('MERGE value=%d "%s" -> value=%d "%s" (%d products)', $merge['from_id'], Str::limit($merge['from'], 60), $merge['to_id'], $merge['to'], $merge['products']));
        }

        if (! $this->option('write')) {
            $this->line('MODE=READ_ONLY');

            return self::SUCCESS;
        }

        if ($plan['renames'] === [] && $plan['merges'] === [] && $missing === []) {
            $this->info('NOTHING_TO_WRITE');

            return self::SUCCESS;
        }

        $backup = ['renames' => [], 'moved_links' => [], 'attached' => []];

        DB::transaction(function () use ($plan, $missing, $brandAttribute, &$backup): void {
            // Merges first: a rename may take the slug a merged-away value held.
            foreach ($plan['merges'] as $merge) {
                foreach (DB::table('product_attribute_value')->where('attribute_value_id', $merge['from_id'])->pluck('product_id') as $productId) {
                    DB::table('product_attribute_value')->where('attribute_value_id', $merge['from_id'])->where('product_id', $productId)->delete();

                    $alreadyLinked = DB::table('product_attribute_value')
                        ->where('attribute_value_id', $merge['to_id'])
                        ->where('product_id', $productId)
                        ->exists();

                    if (! $alreadyLinked) {
                        DB::table('product_attribute_value')->insert([
                            'product_id' => $productId,
                            'attribute_value_id' => $merge['to_id'],
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }

                    $backup['moved_links'][] = ['product_id' => (int) $productId, 'from' => $merge['from_id'], 'to' => $merge['to_id'], 'to_was_linked' => $alreadyLinked];
                }

                // The merged-away value keeps its row with a free slug, so the canonical one can take the clean slug.
                $mergedAway = AttributeValue::query()->findOrFail($merge['from_id']);
                $backup['renames'][] = ['id' => $mergedAway->id, 'value' => $mergedAway->value, 'slug' => $mergedAway->slug];
                $mergedAway->update(['slug' => Str::limit($mergedAway->slug, 150, '').'-merged-'.$mergedAway->id]);
            }

            foreach ($plan['renames'] as $rename) {
                $value = AttributeValue::query()->findOrFail($rename['id']);
                $backup['renames'][] = ['id' => $value->id, 'value' => $value->value, 'slug' => $value->slug];
                $value->update(['value' => $rename['to'], 'slug' => $rename['slug']]);
            }

            foreach ($missing as $row) {
                $product = Product::query()->find($row['product_id']);

                if ($product !== null && $brandAttribute->attach($product, $row['brand'])) {
                    $valueId = $product->attributeValues()
                        ->whereHas('attribute', fn ($query) => $query->whereIn('slug', ProductListing::BRAND_ATTRIBUTE_SLUGS))
                        ->value('attribute_values.id');
                    $backup['attached'][] = ['product_id' => $product->id, 'value_id' => (int) $valueId];
                }
            }
        });

        $backupPath = 'catalogue-repairs/clean-brands-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4)).'.json';
        Storage::disk('local')->put($backupPath, json_encode([
            'command' => $this->getName(),
            'created_at' => now()->toIso8601String(),
        ] + $backup, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        $this->info('BACKUP='.$backupPath);
        $this->info('RENAMED='.count($plan['renames']).' MERGED='.count($plan['merges']).' LINKS_MOVED='.count($backup['moved_links']).' BRANDS_ATTACHED='.count($backup['attached']));
        $this->info('CATALOGUE_BRANDS_WRITE=PASS');

        return self::SUCCESS;
    }

    /**
     * The brand name without legal form or imprint: "Reha Fund Sp. z o.o. ul. Staniewicka 14 …" -> "Reha Fund".
     */
    public static function cleanName(string $name): string
    {
        $original = trim((string) preg_replace('/\s+/u', ' ', $name));

        if (preg_match(self::MARKA, $original, $marka) === 1) {
            return trim($marka[1]);
        }

        $name = (string) preg_replace('/^(?:osoba odpowiedzialna|producent|wytwórca)\s*:\s*/iu', '', $original);
        $name = preg_split(self::IMPRINT_START, $name, 2)[0] ?? $name;
        // After a comma, text holding a number is an address ("Foo Ltd, No. 4-2 Leqiang Road");
        // other commas belong to the name ("Becton, Dickinson and Company").
        $name = (string) preg_replace('/,(?=[^,]*\d).*$/u', '', $name);
        $name = preg_split(self::LEGAL_FORM, $name, 2)[0] ?? $name;
        $name = (string) preg_replace('/^[\s,.\-–]+|[\s,.\-–]+$/u', '', $name);

        return $name !== '' ? $name : $original;
    }

    /**
     * @param  Collection<int, AttributeValue>  $values
     * @return array{renames: list<array{id: int, from: string, to: string, slug: string, products: int}>, merges: list<array{from_id: int, from: string, to_id: int, to: string, products: int}>}
     */
    private function plan(Collection $values): array
    {
        $renames = [];
        $merges = [];

        $groups = $values->groupBy(fn (AttributeValue $value): string => $value->attribute_id.'|'.Str::slug(self::cleanName((string) $value->value)));

        foreach ($groups as $group) {
            /** @var AttributeValue $keep */
            $keep = $group->sortByDesc('products_count')->first();
            $clean = self::cleanName((string) $keep->value);
            $slug = Str::slug($clean) ?: $keep->slug;

            foreach ($group as $value) {
                // An empty duplicate only matters when it holds the slug the kept value takes.
                if ($value->is($keep) || ($value->products_count === 0 && $value->slug !== $slug)) {
                    continue;
                }

                $merges[] = ['from_id' => $value->id, 'from' => (string) $value->value, 'to_id' => $keep->id, 'to' => $clean, 'products' => (int) $value->products_count];
            }

            if ($keep->products_count > 0 && ($clean !== $keep->value || $slug !== $keep->slug)) {
                $renames[] = ['id' => $keep->id, 'from' => (string) $keep->value, 'to' => $clean, 'slug' => $slug, 'products' => (int) $keep->products_count];
            }
        }

        return ['renames' => $renames, 'merges' => $merges];
    }

    /**
     * Reh4Mat-imported products with no manufacturer, with the brand their title ends with.
     *
     * @return list<array{product_id: int, brand: string}>
     */
    private function missingReh4MatBrands(): array
    {
        return Product::query()
            ->where('external_source', 'reh4mat')
            ->whereDoesntHave('attributeValues', fn ($query) => $query->whereHas(
                'attribute',
                fn ($attribute) => $attribute->whereIn('slug', ProductListing::BRAND_ATTRIBUTE_SLUGS),
            ))
            ->orderBy('id')
            ->get(['id', 'seo_title'])
            ->map(function (Product $product): array {
                preg_match('/\s\|\s([^|]{2,40})$/u', (string) $product->seo_title, $match);

                return ['product_id' => $product->id, 'brand' => trim($match[1] ?? '') ?: 'Reh4Mat'];
            })
            ->all();
    }

    private function rollback(string $backupPath): int
    {
        if (! Storage::disk('local')->exists($backupPath)) {
            $this->error('BACKUP_NOT_FOUND='.$backupPath);

            return self::FAILURE;
        }

        $backup = json_decode(Storage::disk('local')->get($backupPath), true, flags: JSON_THROW_ON_ERROR);

        DB::transaction(function () use ($backup): void {
            foreach ($backup['attached'] as $row) {
                DB::table('product_attribute_value')->where('product_id', $row['product_id'])->where('attribute_value_id', $row['value_id'])->delete();
            }

            // Restore names in reverse so a slug freed by one restore is free for the next.
            foreach (array_reverse($backup['renames']) as $row) {
                AttributeValue::query()->whereKey($row['id'])->update(['value' => $row['value'], 'slug' => $row['slug']]);
            }

            foreach ($backup['moved_links'] as $row) {
                if (! $row['to_was_linked']) {
                    DB::table('product_attribute_value')->where('product_id', $row['product_id'])->where('attribute_value_id', $row['to'])->delete();
                }

                DB::table('product_attribute_value')->insertOrIgnore([
                    'product_id' => $row['product_id'],
                    'attribute_value_id' => $row['from'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        $this->info('RESTORED_NAMES='.count($backup['renames']).' RESTORED_LINKS='.count($backup['moved_links']).' DETACHED='.count($backup['attached']));
        $this->info('CATALOGUE_BRANDS_ROLLBACK=PASS');

        return self::SUCCESS;
    }
}
