<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Sigvaris\SigvarisProductDataCrawler;
use Illuminate\Console\Command;
use JsonException;
use RuntimeException;

final class CrawlSigvarisProductDataCommand extends Command
{
    protected $signature = 'sigvaris:crawl-product-data
        {--from=scrapers/sigvaris/product-links.json : Product-link JSON under storage/app.}
        {--url=* : Explicit product URL(s).}
        {--limit= : Maximum product URLs.}
        {--offset=0 : Product URL offset.}
        {--timeout=20 : HTTP request timeout.}
        {--attempts=3 : Maximum request attempts.}
        {--retry-delay-ms=1500 : Retry delay.}
        {--request-delay-ms=500 : Request delay.}
        {--insecure : Disable TLS verification.}
        {--no-progress : Hide progress.}
        {--json : Print JSON.}
        {--save= : Save JSON under storage/app.}
        {--show-failures : Print failed product URLs.}';

    protected $description = 'Scrape sklep-sigvaris.com PrestaShop product details into JSON without database writes.';

    public function __construct(private readonly SigvarisProductDataCrawler $crawler) { parent::__construct(); }

    public function handle(): int
    {
        $json=(bool)$this->option('json');
        $this->crawler
            ->withTlsVerification(!(bool)$this->option('insecure'))
            ->withTimeout($this->intOption('timeout',20,1))
            ->withMaxAttempts($this->intOption('attempts',3,1),$this->intOption('retry-delay-ms',1500,0))
            ->withRequestDelayMilliseconds($this->intOption('request-delay-ms',500,0));
        if(!$json&&!(bool)$this->option('no-progress'))$this->crawler->withProgressCallback(fn(string $m):null=>$this->line($m));

        $urls = array_values(array_filter(array_map(
            static fn (mixed $u): string => trim((string) $u),
            $this->option('url'),
        )));
        $result=$urls!==[]
            ? $this->crawler->crawlProductUrls($urls,$this->limit(),max(0,(int)$this->option('offset')))
            : $this->crawler->crawlFromProductLinkDiscovery($this->loadJson((string)$this->option('from')),$this->limit(),max(0,(int)$this->option('offset')));

        if($json)$this->line($this->encode($result));
        else {
            $this->info('Sigvaris product-data crawl');
            $this->line('Source product URLs: '.($result['source_product_url_count']??0));
            $this->line('Selected product URLs: '.($result['selected_product_url_count']??0));
            $this->line('Scraped products: '.($result['product_count']??0));
            $this->line('Warnings: '.count($result['warnings']??[]));
            $this->line('Failed URLs: '.count($result['failed_urls']??[]));
            foreach($result['products']??[] as $product){$this->line('- '.($product['name']??'Unnamed').' | ID='.($product['external_product_id']??'?').' | combination='.($product['default_combination_id']??'none').' | gross='.($product['price_gross_amount']??'?').' PLN | images='.count($product['images']??[]).' | attributes='.count($product['attributes']??[]));}
        }
        if((bool)$this->option('show-failures'))foreach($result['failed_urls']??[] as $url=>$reason)$this->warn($url.' - '.$reason);
        $this->save($result,$json);
        return ($result['product_count']??0)>0?self::SUCCESS:self::FAILURE;
    }

    private function intOption(string $name,int $default,int $min):int{$v=$this->option($name);return is_numeric($v)?max($min,(int)$v):$default;}
    private function limit():?int{$v=$this->option('limit');return $v===null||$v===''?null:max(1,(int)$v);}
    /** @return array<string,mixed> */ private function loadJson(string $relative):array{$path=storage_path('app/'.ltrim(trim($relative),'/'));if(!is_file($path))throw new JsonException('Sigvaris product-link JSON does not exist: '.$path);$decoded=json_decode((string)file_get_contents($path),true,512,JSON_THROW_ON_ERROR);if(!is_array($decoded))throw new JsonException('Sigvaris product-link JSON must contain an object.');return $decoded;}
    /** @param array<string,mixed> $result */ private function save(array $result,bool $quiet):void{$relative=trim((string)($this->option('save')??''));if($relative==='')return;$path=storage_path('app/'.ltrim($relative,'/'));if(!is_dir(dirname($path))&&!mkdir(dirname($path),0755,true)&&!is_dir(dirname($path)))throw new RuntimeException('Unable to create Sigvaris scraper directory.');if(file_put_contents($path,$this->encode($result).PHP_EOL)===false)throw new RuntimeException('Unable to save Sigvaris product data.');if(!$quiet)$this->info('Saved product data to storage/app/'.ltrim($relative,'/'));}
    /** @param array<string,mixed> $data */ private function encode(array $data):string{$json=json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION);if(!is_string($json))throw new RuntimeException('Unable to encode Sigvaris JSON.');return $json;}
}
