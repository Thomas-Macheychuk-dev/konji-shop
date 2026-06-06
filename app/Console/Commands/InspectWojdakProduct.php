<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Wojdak\WojdakProductImporter;
use App\Services\Wojdak\WojdakProductNormalizer;
use App\Services\Wojdak\WojdakProductPayloadExtractor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

final class InspectWojdakProduct extends Command
{
    protected $signature = 'wojdak:inspect
        {url : Wojdak product URL, for example https://wojdak.pl/product/bluza-2002/}
        {--save-html : Save fetched HTML to storage/app/wojdak-inspect.html}
        {--import : Import product without asking for confirmation}
        {--no-dump : Do not print the extracted JSON payload}';

    protected $description = 'Inspect a single Wojdak product page and optionally import it as a draft product with size variants.';

    public function __construct(
        private readonly WojdakProductPayloadExtractor $extractor,
        private readonly WojdakProductNormalizer $normalizer,
        private readonly WojdakProductImporter $importer,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $url = (string) $this->argument('url');

        $this->info('Fetching: '.$url);

        $response = Http::timeout(30)
            ->withHeaders($this->headers())
            ->get($url);

        if (! $response->successful()) {
            $this->error('Request failed with status '.$response->status());

            return self::FAILURE;
        }

        $html = $response->body();
        $payload = $this->extractor->extract($html, $url);
        $normalized = $this->normalizer->normalize($payload);

        if ((bool) $this->option('save-html')) {
            file_put_contents(storage_path('app/wojdak-inspect.html'), $html);
            $this->info('Saved raw HTML to storage/app/wojdak-inspect.html');
        }

        $this->line('Downloaded HTML size: '.strlen($html).' bytes');
        $this->line('Generated variants: '.count($normalized['variants'] ?? []));

        foreach (($normalized['warnings'] ?? []) as $warning) {
            $this->warn((string) $warning);
        }

        if (! (bool) $this->option('no-dump')) {
            $this->newLine();
            $this->info('=== EXTRACTED DATA ===');
            $this->line(json_encode([
                'source_url' => $url,
                'payload' => $payload,
                'normalized' => $normalized,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        $shouldImport = (bool) $this->option('import')
            || $this->confirm('Import this product into database?', false);

        if ($shouldImport) {
            $product = $this->importer->import($normalized);

            $this->info('Imported product ID: '.$product->id);
            $this->info('Imported variants: '.$product->variants->count());
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0 Safari/537.36',
            'Accept-Language' => 'pl-PL,pl;q=0.9,en;q=0.8',
        ];
    }
}
