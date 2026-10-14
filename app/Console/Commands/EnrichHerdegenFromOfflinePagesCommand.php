<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ProductStatus;
use App\Models\Product;
use App\Services\Herdegen\HerdegenOfflineProductFacts;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class EnrichHerdegenFromOfflinePagesCommand extends Command
{
    protected $signature = 'herdegen:enrich-offline-pages
        {--from=scrapers/herdegen/analysis/listing-snapshot.json}
        {--limit=46}
        {--write-drafts : Explicitly update draft product descriptions using structured facts}';

    protected $description = 'Enrich existing Herdegen drafts using saved HTML pages and structured specifications only.';

    public function __construct(private readonly HerdegenOfflineProductFacts $extractor)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $path = (string) $this->option('from');
        $limit = (string) $this->option('limit');
        if (!preg_match('~^scrapers/herdegen/analysis/[A-Za-z0-9_-]+\.json$~D', $path)
            || !ctype_digit($limit) || (int) $limit < 1 || (int) $limit > 46) {
            $this->error('HERDEGEN05=FAIL: invalid path or limit.');
            return self::FAILURE;
        }
        try {
            $disk = Storage::disk('local');
            $snapshot = json_decode($disk->get($path), true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($snapshot) || ($snapshot['source'] ?? null) !== 'herdegen'
                || ($snapshot['analysis_type'] ?? null) !== 'herdegen_offline_listing_snapshot'
                || !is_array($snapshot['products'] ?? null) || !array_is_list($snapshot['products'])
                || ($snapshot['summary']['products'] ?? null) !== count($snapshot['products'])
                || ($snapshot['summary']['commercially_approved'] ?? null) !== 0) {
                throw new RuntimeException('Invalid Herdegen snapshot.');
            }
            $selected = array_slice($snapshot['products'], 0, (int) $limit);
            $seen = [];
            $plan = [];
            $missing = 0;
            $noSpecs = 0;
            foreach ($selected as $item) {
                $url = $item['source_url'] ?? null;
                $id = $item['external_id'] ?? null;
                if (!is_array($item) || !is_string($url)
                    || !preg_match('~^https://www\.orteo\.pl/[A-Za-z0-9_-]+$~D', $url)
                    || !is_string($id) || $id !== substr(hash('sha256', $url), 0, 32)
                    || isset($seen[$id]) || ($item['brand'] ?? null) !== 'Herdegen'
                    || ($item['commercially_approved'] ?? null) !== false
                    || ($item['variant_candidates'] ?? null) !== []) {
                    throw new RuntimeException('Invalid or duplicated Herdegen source record.');
                }
                $seen[$id] = true;
                $product = Product::withTrashed()->where('external_source', 'herdegen')->where('external_id', $id)->first();
                if ($product === null || $product->trashed() || $product->status !== ProductStatus::DRAFT
                    || $product->published_at !== null || $product->variants()->exists()) {
                    throw new RuntimeException('Product is not a safe unpublished draft: '.$id);
                }
                $htmlPath = 'scrapers/herdegen/pages/'.$id.'.html';
                if (!$disk->exists($htmlPath)) {
                    $missing++;
                    continue;
                }
                $html = $disk->get($htmlPath);
                if (strlen($html) > 2500000) {
                    throw new RuntimeException('HTML page too large: '.$id);
                }
                $facts = $this->extractor->extract($html);
                $authorizedDescription = $this->extractor->extractDescription($html);
                if ($facts === [] && $authorizedDescription === null) {
                    $noSpecs++;
                    continue;
                }
                $name = trim(strip_tags((string) $product->name));
                $lines = [];
                foreach ($facts as $key => $value) {
                    $lines[] = '<li><strong>'.e($key).':</strong> '.e($value).'</li>';
                }
                $description = $authorizedDescription ?? '<p>'.e('Herdegen — '.$name.'.').'</p>';
                if ($facts !== []) {
                    $description .= '<h3>Parametry produktu (do weryfikacji)</h3><ul>'.implode('', $lines).'</ul>';
                }
                // Only replace the known HERDEGEN-04 placeholder or an empty description.
                $placeholder = '<p>'.e('Herdegen — '.$name.'.').'</p>';
                if ($product->description !== null && $product->description !== '' && $product->description !== $placeholder) {
                    continue;
                }
                $plan[] = ['id' => $id, 'description' => $description, 'placeholder' => $placeholder];
            }
            $updated = 0;
            if ((bool) $this->option('write-drafts')) {
                DB::transaction(function () use ($plan, &$updated): void {
                    foreach ($plan as $row) {
                        $product = Product::query()->where('external_source', 'herdegen')
                            ->where('external_id', $row['id'])->lockForUpdate()->first();
                        if ($product === null || $product->status !== ProductStatus::DRAFT
                            || $product->published_at !== null || $product->variants()->exists()) {
                            throw new RuntimeException('Draft changed during update.');
                        }
                        if (!in_array($product->description, [null, '', $row['placeholder']], true)) {
                            continue;
                        }
                        $product->description = $row['description'];
                        $product->save();
                        $updated++;
                    }
                });
            }
            $this->line('SELECTED='.count($selected));
            $this->line('MODE='.((bool) $this->option('write-drafts') ? 'WRITE_DRAFTS' : 'DRY_RUN'));
            $this->line('MISSING_SAVED_PAGES='.$missing);
            $this->line('PAGES_WITHOUT_STRUCTURED_SPECS='.$noSpecs);
            $this->line('ELIGIBLE_FOR_UPDATE='.count($plan));
            $this->line('UPDATED='.$updated);
            $this->line('PRICES_IMPORTED=0');
            $this->line('IMAGES_DOWNLOADED=0');
            $this->line('VARIANTS_CREATED=0');
            $this->info('HERDEGEN05=PASS');
            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('HERDEGEN05=FAIL: '.$e->getMessage());
            return self::FAILURE;
        }
    }
}
