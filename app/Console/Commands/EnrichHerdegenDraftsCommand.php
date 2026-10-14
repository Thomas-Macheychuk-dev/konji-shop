<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ProductStatus;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class EnrichHerdegenDraftsCommand extends Command
{
    protected $signature = 'herdegen:enrich-drafts
        {--from=scrapers/herdegen/analysis/listing-snapshot.json}
        {--limit=46}
        {--write-drafts : Fill empty draft descriptions and SEO fields only}';

    protected $description = 'Enrich existing unpublished Herdegen drafts using source-supported listing names; never import retailer descriptions, prices or images.';

    public function handle(): int
    {
        $path = (string) $this->option('from');
        $limit = (string) $this->option('limit');
        if (!preg_match('~^scrapers/herdegen/analysis/[A-Za-z0-9_-]+\.json$~D', $path)
            || !ctype_digit($limit) || (int) $limit < 1 || (int) $limit > 46) {
            $this->error('HERDEGEN_ENRICH=FAIL: invalid input path or limit.');
            return self::FAILURE;
        }
        $write = (bool) $this->option('write-drafts');
        try {
            $snapshot = json_decode(Storage::disk('local')->get($path), true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($snapshot) || ($snapshot['source'] ?? null) !== 'herdegen'
                || ($snapshot['analysis_type'] ?? null) !== 'herdegen_offline_listing_snapshot'
                || !is_array($snapshot['products'] ?? null) || !array_is_list($snapshot['products'])
                || ($snapshot['summary']['products'] ?? null) !== count($snapshot['products'])
                || ($snapshot['summary']['commercially_approved'] ?? null) !== 0) {
                throw new RuntimeException('Invalid source snapshot.');
            }
            $selected = array_slice($snapshot['products'], 0, (int) $limit);
            $ids = [];
            $plan = [];
            foreach ($selected as $item) {
                $url = $item['source_url'] ?? null;
                $id = $item['external_id'] ?? null;
                $name = $item['name'] ?? null;
                if (!is_array($item) || !is_string($url)
                    || !preg_match('~^https://www\.orteo\.pl/[A-Za-z0-9_-]+$~D', $url)
                    || !is_string($id) || $id !== substr(hash('sha256', $url), 0, 32)
                    || isset($ids[$id]) || ($item['brand'] ?? null) !== 'Herdegen'
                    || !is_string($name) || trim($name) === ''
                    || ($item['commercially_approved'] ?? null) !== false
                    || ($item['variant_candidates'] ?? null) !== []) {
                    throw new RuntimeException('Invalid or duplicate product metadata.');
                }
                $ids[$id] = true;
                $product = Product::withTrashed()->where('external_source', 'herdegen')->where('external_id', $id)->first();
                if ($product === null || $product->trashed() || $product->status !== ProductStatus::DRAFT
                    || $product->published_at !== null || $product->variants()->exists()) {
                    throw new RuntimeException('Product missing or not safely enrichable: '.$id);
                }
                // The listing name is the only human-readable product fact in this snapshot.
                // Never invent technical properties or use the retailer's copyrighted text.
                $cleanName = trim(preg_replace('/\s+/u', ' ', strip_tags($name)) ?? $name);
                $short = Str::limit('Herdegen — '.$cleanName.'.', 255, '');
                $description = '<p>'.e('Herdegen — '.$cleanName.'.').'</p>';
                $seoTitle = Str::limit($cleanName.' | Herdegen', 60, '');
                $seoDescription = Str::limit('Herdegen: '.$cleanName.'.', 155, '');
                $changes = [];
                foreach (['short_description' => $short, 'description' => $description,
                    'seo_title' => $seoTitle, 'seo_description' => $seoDescription] as $key => $value) {
                    if ($product->{$key} === null || $product->{$key} === '') {
                        $changes[$key] = $value;
                    }
                }
                $plan[] = ['id' => $id, 'changes' => $changes];
            }
            $updated = 0;
            $filled = 0;
            if ($write) {
                DB::transaction(function () use ($plan, &$updated, &$filled): void {
                    foreach ($plan as $entry) {
                        $product = Product::query()->where('external_source', 'herdegen')
                            ->where('external_id', $entry['id'])->lockForUpdate()->first();
                        if ($product === null || $product->status !== ProductStatus::DRAFT
                            || $product->published_at !== null || $product->variants()->exists()) {
                            throw new RuntimeException('Draft changed during enrichment.');
                        }
                        $changes = [];
                        foreach ($entry['changes'] as $key => $value) {
                            if ($product->{$key} === null || $product->{$key} === '') {
                                $changes[$key] = $value;
                            }
                        }
                        if ($changes !== []) {
                            $product->fill($changes)->save();
                            $updated++;
                            $filled += count($changes);
                        }
                    }
                });
            }
            $this->line('SELECTED='.count($plan));
            $this->line('MODE='.($write ? 'WRITE_DRAFTS' : 'DRY_RUN'));
            $this->line('ELIGIBLE_WITH_EMPTY_FIELDS='.count(array_filter($plan, static fn ($p) => $p['changes'] !== [])));
            $this->line('UPDATED='.$updated);
            $this->line('FIELDS_FILLED='.$filled);
            $this->line('RETAILER_PRICES_IMPORTED=0');
            $this->line('IMAGES_DOWNLOADED=0');
            $this->line('VARIANTS_CREATED=0');
            $this->info('HERDEGEN_ENRICH=PASS');
            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('HERDEGEN_ENRICH=FAIL: '.$e->getMessage());
            return self::FAILURE;
        }
    }
}
