<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Neoxmed\NeoxmedPricedMapBuilder;
use App\Services\Neoxmed\NeoxmedProductImporter;
use Illuminate\Console\Command;
use JsonException;
use RuntimeException;
use Throwable;

/** Read-only by default; --write can only consume a replay-verified priced map. */
final class ImportNeoxmedProductsCommand extends Command
{
    protected $signature = 'neoxmed:import-products
        {--from=scrapers/neoxmed/priced-map.json : Frozen, commercially approved priced map under storage/app.}
        {--import-map=scrapers/neoxmed/import-map.json : Exact structural map underlying --from.}
        {--approvals=scrapers/neoxmed/commercial-approvals.json : Exact signed-off commercial approval input underlying --from.}
        {--write : Explicitly persist products, variants and category/attribute links. Otherwise dry-run only.}
        {--limit= : Optional positive maximum product count for a controlled batch.}
        {--offset=0 : Number of products to skip (non-negative).}
        {--no-images : With --write, skip media downloads; products remain unpublished drafts.}
        {--show-products : Print selected product identities.}';

    protected $description = 'Revalidate the approved NeoxMed priced map and optionally import non-purchasable draft products.';

    public function __construct(
        private readonly NeoxmedPricedMapBuilder $builder,
        private readonly NeoxmedProductImporter $importer,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            return $this->executeImport();
        } catch (Throwable $e) {
            $this->error('NeoxMed import refused: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    private function executeImport(): int
    {
        $mapRaw = $this->readJson((string) $this->option('import-map'));
        $approvalsRaw = $this->readJson((string) $this->option('approvals'));
        $pricedRaw = $this->readJson((string) $this->option('from'));
        $structural = $mapRaw['decoded'];
        $approvals = $approvalsRaw['decoded'];
        $priced = $pricedRaw['decoded'];

        // Recompute the entire priced map rather than trusting a mutable ready flag.
        $replayed = $this->builder->build(
            $structural,
            hash('sha256', $mapRaw['raw']),
            $approvals,
            hash('sha256', $approvalsRaw['raw']),
        );
        if ($replayed !== $priced) {
            throw new RuntimeException('Priced map differs from a fresh replay of the exact structural map and approval file. Regenerate it using neoxmed:priced-map.');
        }
        $this->line('NeoxMed priced-map replay: MATCH');
        $this->line('Approval SHA-256: '.hash('sha256', $approvalsRaw['raw']));
        $this->line('Ready for database write: '.(($replayed['ready_for_database_write'] ?? false) ? 'YES' : 'NO'));
        $this->line('Mapped products: '.count($replayed['products'] ?? []));
        $this->line('Unapproved prices: '.($replayed['summary']['products_without_price'] ?? '?'));
        $this->line('Unapproved VAT: '.($replayed['summary']['products_without_vat'] ?? '?'));
        $this->line('Missing required media: '.($replayed['summary']['required_media_missing'] ?? '?'));

        if (($replayed['ready_for_database_write'] ?? false) !== true) {
            foreach (array_slice($replayed['errors'] ?? [], 0, 10) as $error) {
                $this->error((string) $error);
            }
            foreach (array_slice($replayed['blocking_review_items'] ?? [], 0, 10) as $item) {
                $this->warn((string) $item);
            }
            $this->warn('No database writes or image downloads performed. Complete commercial approvals first.');

            return self::FAILURE;
        }

        $check = $this->importer->preflight($replayed);
        $this->line('Fresh database collision/category audit: '.($check['safe'] ? 'PASS' : 'FAIL'));
        foreach ($check['errors'] as $error) {
            $this->error((string) $error);
        }
        if (! $check['safe']) {
            return self::FAILURE;
        }

        $offset = filter_var($this->option('offset'), FILTER_VALIDATE_INT);
        if ($offset === false || $offset < 0) {
            throw new RuntimeException('--offset must be a non-negative integer.');
        }
        $rawLimit = $this->option('limit');
        $limit = ($rawLimit === null || $rawLimit === '') ? null : filter_var($rawLimit, FILTER_VALIDATE_INT);
        if ($limit === false || ($limit !== null && $limit < 1)) {
            throw new RuntimeException('--limit must be a positive integer.');
        }
        $selected = array_slice($replayed['products'], $offset, $limit);
        if ($selected === []) {
            throw new RuntimeException('No products selected by the offset/limit.');
        }
        $this->line('Selected products: '.count($selected));
        if ((bool) $this->option('show-products')) {
            foreach ($selected as $mapped) {
                $this->line('- '.$mapped['product']['external_id'].' | '.$mapped['product']['name']);
            }
        }
        if (! (bool) $this->option('write')) {
            $this->info('PASS: DRY RUN ONLY. Database writes: NO. Images downloaded: NO.');

            return self::SUCCESS;
        }

        // Builder treats missing audit metadata as review-only; importer requires it for real writes.
        foreach (['approval_reference', 'approved_by', 'approved_at'] as $field) {
            if (! is_string($approvals[$field] ?? null) || trim($approvals[$field]) === '') {
                throw new RuntimeException('Required commercial sign-off metadata is blank: '.$field);
            }
        }
        $this->warn('Explicit --write received. Products and variants will stay draft / out_of_stock.');
        $result = $this->importer->import(
            $replayed,
            ! (bool) $this->option('no-images'),
            $limit,
            $offset,
        );
        $this->info(sprintf(
            'NeoxMed persisted: selected=%d, created=%d, updated=%d, gallery_images_synced=%d.',
            $result['selected'], $result['created'], $result['updated'], $result['images'],
        ));

        return self::SUCCESS;
    }

    /** @return array{raw:string,decoded:array<string,mixed>} */
    private function readJson(string $relative): array
    {
        $relative = trim($relative);
        if ($relative === '' || str_starts_with($relative, '/')
            || str_contains($relative, '\\') || preg_match('~(^|/)\.\.(/|$)~', $relative)) {
            throw new RuntimeException('Only relative storage/app JSON paths are accepted.');
        }
        $path = storage_path('app/'.$relative);
        if (! is_file($path)) {
            throw new RuntimeException('Required NeoxMed file does not exist: '.$path);
        }
        $raw = file_get_contents($path);
        if (! is_string($raw)) {
            throw new RuntimeException('Unable to read NeoxMed input: '.$path);
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('Invalid JSON in '.$path.': '.$e->getMessage(), 0, $e);
        }
        if (! is_array($decoded)) {
            throw new RuntimeException('NeoxMed JSON root must be an object: '.$path);
        }

        return ['raw' => $raw, 'decoded' => $decoded];
    }
}
