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

final class ImportHerdegenDraftsCommand extends Command
{
    protected $signature = 'herdegen:import
        {--from=scrapers/herdegen/analysis/listing-snapshot.json}
        {--limit=5}
        {--write-drafts : Explicitly create unpublished, non-orderable product records}';

    protected $description = 'Review or import Herdegen listing metadata as draft-only records, without variants, prices or images.';

    public function handle(): int
    {
        $path = (string) $this->option('from');
        if (!preg_match('~^scrapers/herdegen/(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_-]+\.json$~D', $path)) {
            $this->error('HERDEGEN_IMPORT=FAIL: invalid private JSON path.');
            return self::FAILURE;
        }
        $limitRaw = (string) $this->option('limit');
        if (!ctype_digit($limitRaw) || (int) $limitRaw < 1) {
            $this->error('HERDEGEN_IMPORT=FAIL: --limit must be a positive integer.');
            return self::FAILURE;
        }
        $write = (bool) $this->option('write-drafts');
        try {
            $raw = Storage::disk('local')->get($path);
            $report = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($report) || ($report['analysis_type'] ?? null) !== 'herdegen_offline_listing_snapshot'
                || ($report['source'] ?? null) !== 'herdegen' || !is_array($report['products'] ?? null)
                || ($report['summary']['products'] ?? null) !== count($report['products'])
                || ($report['summary']['commercially_approved'] ?? null) !== 0) {
                throw new RuntimeException('Invalid or commercially modified Herdegen evidence report.');
            }
            $seen = [];
            $selected = array_slice($report['products'], 0, (int) $limitRaw);
            foreach ($selected as $item) {
                if (!is_array($item) || !is_string($item['source_url'] ?? null)
                    || !preg_match('~^https://www\.orteo\.pl/[A-Za-z0-9_-]+$~D', $item['source_url'])
                    || !is_string($item['name'] ?? null) || trim($item['name']) === ''
                    || ($item['brand'] ?? null) !== 'Herdegen'
                    || ($item['external_id'] ?? null) !== substr(hash('sha256', $item['source_url']), 0, 32)
                    || ($item['commercially_approved'] ?? null) !== false
                    || ($item['variant_candidates'] ?? null) !== []
                    || isset($seen[$item['external_id']])) {
                    throw new RuntimeException('Invalid, duplicated or commercially unsafe product metadata.');
                }
                $seen[$item['external_id']] = true;
            }
            $created = 0;
            $alreadyPresent = 0;
            if ($write) {
                DB::transaction(function () use ($selected, &$created, &$alreadyPresent): void {
                    foreach ($selected as $item) {
                        $existing = Product::withTrashed()->where('external_source', 'herdegen')
                            ->where('external_id', $item['external_id'])->first();
                        if ($existing !== null) {
                            // Never overwrite or reactivate products; do not touch their variants.
                            $alreadyPresent++;
                            continue;
                        }
                        $baseSlug = Str::slug($item['name']);
                        $slug = Str::limit('herdegen-'.$baseSlug, 135, '');
                        $slug .= '-'.substr($item['external_id'], 0, 12);
                        if (Product::withTrashed()->where('slug', $slug)->exists()) {
                            throw new RuntimeException('Slug collision: '.$slug);
                        }
                        Product::query()->create([
                            'name' => $item['name'],
                            'slug' => $slug,
                            'status' => ProductStatus::DRAFT,
                            'published_at' => null,
                            'external_source' => 'herdegen',
                            'external_id' => $item['external_id'],
                            'external_parent_sku' => null,
                            'short_description' => null,
                            'description' => null,
                        ]);
                        $created++;
                    }
                });
            }
            $this->line('SELECTED='.count($selected));
            $this->line('MODE='.($write ? 'CREATE_DRAFTS' : 'DRY_RUN'));
            $this->line('CREATED='.$created);
            $this->line('ALREADY_PRESENT='.$alreadyPresent);
            $this->line('VARIANTS_CREATED=0');
            $this->line('PRICES_IMPORTED=0');
            $this->line('IMAGES_DOWNLOADED=0');
            $this->line('DATABASE_WRITES='.($write ? $created : 0));
            $this->info('HERDEGEN_IMPORT=PASS');
            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('HERDEGEN_IMPORT=FAIL: '.$e->getMessage());
            return self::FAILURE;
        }
    }
}
