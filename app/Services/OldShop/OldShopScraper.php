<?php

declare(strict_types=1);

namespace App\Services\OldShop;

use Closure;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\DomCrawler\Crawler;
use Throwable;

/**
 * Reads products from the old ortezka.pl PrestaShop shop, for brands the new shop does not carry yet (owner's
 * feedback, 16 November 2026: "add all Thuasne products and keep our prices").
 *
 * A product page's #product-details[data-product] holds the name, reference, brand, default category, VAT
 * rate, description and images. Each size/colour combination's price comes from PrestaShop's "refresh" request
 * (the one the page sends when a customer picks an option); its product_url fragment ("#/36-kolor-bezowy/
 * 157-rozmiar-2") names the options it really sold, so combinations the shop never had are dropped.
 */
final class OldShopScraper
{
    public const HOST = 'ortezka.pl';

    public const BASE_URL = 'https://ortezka.pl';

    private const MAX_COMBINATIONS = 200;

    private ?Closure $progress = null;

    private int $delayMilliseconds = 1500;

    private CookieJar $cookies;

    /** @var list<int> */
    private array $backoffSeconds = [60, 120, 300, 600, 900];

    /** @var Closure(int): void */
    private Closure $sleeper;

    public function __construct()
    {
        $this->cookies = new CookieJar;
        $this->sleeper = fn (int $seconds) => sleep($seconds);
    }

    public function withProgressCallback(?Closure $progress): self
    {
        $this->progress = $progress;

        return $this;
    }

    public function withRequestDelayMilliseconds(int $milliseconds): self
    {
        $this->delayMilliseconds = max(0, $milliseconds);

        return $this;
    }

    /**
     * Product URLs (without the combination fragment) from the shop search, page by page until a page adds none.
     *
     * @return list<string>
     */
    public function searchProductUrls(string $query, int $maxPages = 50): array
    {
        $urls = [];

        for ($page = 1; $page <= $maxPages; $page++) {
            $html = $this->get(self::BASE_URL.'/module/iqitsearch/searchiqit?'.http_build_query(['s' => $query, 'page' => $page]));
            $found = $this->productUrlsFromListing($html);
            $new = array_diff($found, $urls);
            $this->emit(sprintf('Search "%s" page %d: %d products', $query, $page, count($new)));

            if ($new === []) {
                break;
            }

            array_push($urls, ...$new);
        }

        return array_values($urls);
    }

    /**
     * @return list<string>
     */
    public function productUrlsFromListing(string $html): array
    {
        $urls = [];

        (new Crawler($html, self::BASE_URL))->filter('.product-miniature a[href]')->each(function (Crawler $link) use (&$urls): void {
            $url = $this->productUrl((string) $link->attr('href'));

            if ($url !== null) {
                $urls[$url] = true;
            }
        });

        return array_keys($urls);
    }

    /**
     * A product URL without its combination: "…-24572-id-4309#/5-rozmiar-l" → "…-id-4309" form kept as linked,
     * fragment and query dropped. Null for anything that is not a product page of the old shop.
     */
    public function productUrl(string $href): ?string
    {
        $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $parts = parse_url(str_starts_with($href, '/') ? self::BASE_URL.$href : $href);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');

        if (! in_array($host, [self::HOST, 'www.'.self::HOST], true) || preg_match('#-id-\d+$#', $path) !== 1) {
            return null;
        }

        return self::BASE_URL.$path;
    }

    /**
     * @return array<string, mixed>
     */
    public function scrape(string $url): array
    {
        $html = $this->get($url);
        $page = $this->parseProductPage($html, $url);
        $page['combinations'] = $this->combinations($page, $this->staticToken($html));

        return $page;
    }

    /**
     * Everything but the combination prices, from the product page.
     *
     * @return array<string, mixed>
     */
    public function parseProductPage(string $html, string $url): array
    {
        $crawler = new Crawler($html, $url);
        $node = $crawler->filter('#product-details[data-product]')->first();

        if ($node->count() === 0) {
            throw new RuntimeException('No product data on '.$url);
        }

        $data = json_decode((string) $node->attr('data-product'), true);

        if (! is_array($data) || ! isset($data['id_product'])) {
            throw new RuntimeException('Unreadable product data on '.$url);
        }

        $images = [];

        foreach ((array) ($data['images'] ?? []) as $image) {
            $src = $image['bySize']['thickbox_default']['url'] ?? $image['large']['url'] ?? null;

            if (is_string($src) && $src !== '') {
                $images[] = $src;
            }
        }

        return [
            'source' => 'ortezka-old',
            'source_url' => $url,
            'id_product' => (int) $data['id_product'],
            'name' => $this->text($data['name'] ?? null),
            'reference' => $this->text($data['reference'] ?? null),
            'ean13' => $this->text($data['ean13'] ?? null),
            'brand' => $this->brand($crawler),
            'category' => $this->text($data['category_name'] ?? null),
            'vat_rate' => (int) round((float) ($data['rate'] ?? 23)),
            'price_gross' => isset($data['price_amount']) ? (float) $data['price_amount'] : null,
            'description_html' => $this->html($data['description'] ?? null),
            'short_description_html' => $this->html($data['description_short'] ?? null),
            'images' => array_values(array_unique($images)),
            'groups' => $this->groups($crawler),
        ];
    }

    /**
     * Option groups: label ("ROZMIAR") and options (attribute id => label).
     *
     * @return list<array{id: string, label: string, options: array<string, string>}>
     */
    private function groups(Crawler $crawler): array
    {
        $groups = [];

        $crawler->filter('.product-variants .product-variants-item')->each(function (Crawler $item) use (&$groups): void {
            $label = $this->text($item->filter('.form-control-label, .control-label')->first()->count() > 0
                ? $item->filter('.form-control-label, .control-label')->first()->text('')
                : null);
            $options = [];
            $name = null;

            $item->filter('select[name^="group["] option')->each(function (Crawler $option) use (&$options, &$name): void {
                $name ??= $option->closest('select')?->attr('name');
                $options[(string) $option->attr('value')] = $this->text($option->text('')) ?? (string) $option->attr('value');
            });

            $item->filter('input[name^="group["]')->each(function (Crawler $input) use (&$options, &$name): void {
                $name ??= $input->attr('name');
                $text = $this->text($input->attr('title'))
                    ?? $this->text($input->closest('label')?->text(''))
                    ?? (string) $input->attr('value');
                $options[(string) $input->attr('value')] = $text;
            });

            if ($name !== null && preg_match('/^group\[(\d+)\]$/', $name, $match) === 1 && $options !== []) {
                $groups[] = ['id' => $match[1], 'label' => $label ?? 'Wariant', 'options' => $options];
            }
        });

        return $groups;
    }

    /**
     * Every combination the shop sells, with its id and gross price. A product without options has one.
     *
     * @param  array<string, mixed>  $page
     * @return list<array{id_product_attribute: int|null, options: array<string, string>, price_gross: float|null}>
     */
    private function combinations(array $page, ?string $token): array
    {
        $groups = $page['groups'];

        if ($groups === []) {
            return [['id_product_attribute' => null, 'options' => [], 'price_gross' => $page['price_gross']]];
        }

        $candidates = [[]];

        foreach ($groups as $group) {
            $next = [];

            foreach ($candidates as $candidate) {
                foreach (array_keys($group['options']) as $value) {
                    $next[] = $candidate + [$group['id'] => (string) $value];
                }
            }

            $candidates = array_slice($next, 0, self::MAX_COMBINATIONS);
        }

        $combinations = [];

        foreach ($candidates as $candidate) {
            try {
                $response = $this->refresh($page['id_product'], $candidate, $token);
            } catch (Throwable $exception) {
                $this->emit('  combination skipped: '.$exception->getMessage());

                continue;
            }

            $id = (int) ($response['id_product_attribute'] ?? 0);

            // The shop answers a combination it does not sell with another one; its URL names the real options.
            if ($id === 0 || isset($combinations[$id]) || ! $this->urlNamesOptions((string) ($response['product_url'] ?? ''), $candidate)) {
                continue;
            }

            $options = [];

            foreach ($groups as $group) {
                $options[$group['label']] = $group['options'][$candidate[$group['id']]];
            }

            $combinations[$id] = [
                'id_product_attribute' => $id,
                'options' => $options,
                'price_gross' => $this->price((string) ($response['product_prices'] ?? '')),
            ];
        }

        return array_values($combinations);
    }

    /**
     * @param  array<string, string>  $candidate  group id => attribute id
     */
    public function urlNamesOptions(string $url, array $candidate): bool
    {
        $fragment = (string) parse_url($url, PHP_URL_FRAGMENT);
        preg_match_all('#(?:^|/)(\d+)-#', $fragment, $matches);
        $named = $matches[1];
        sort($named);
        $wanted = array_values($candidate);
        sort($wanted);

        return $named === $wanted;
    }

    public function price(string $pricesHtml): ?float
    {
        if (preg_match('/current-price-value"\s+content="([\d.]+)"/', $pricesHtml, $match) === 1) {
            return (float) $match[1];
        }

        if (preg_match('/(\d[\d\s\x{00a0}]*,\d{2})\s*zł/u', $pricesHtml, $match) === 1) {
            return (float) str_replace([' ', "\u{00a0}", ','], ['', '', '.'], $match[1]);
        }

        return null;
    }

    /**
     * @param  array<string, string>  $candidate
     * @return array<string, mixed>
     */
    private function refresh(int $productId, array $candidate, ?string $token): array
    {
        $body = ['ajax' => '1', 'action' => 'refresh', 'quantity_wanted' => '1'];

        foreach ($candidate as $group => $value) {
            $body['group['.$group.']'] = $value;
        }

        $response = $this->send(fn () => Http::withOptions(['cookies' => $this->cookies])
            ->withHeaders($this->headers() + ['X-Requested-With' => 'XMLHttpRequest'])
            ->asForm()
            ->timeout(20)
            ->post(self::BASE_URL.'/index.php?'.http_build_query(['controller' => 'product', 'token' => $token, 'id_product' => $productId]), $body));

        if (! $response->successful() || ! is_array($response->json())) {
            throw new RuntimeException('refresh HTTP '.$response->status());
        }

        return $response->json();
    }

    private function staticToken(string $html): ?string
    {
        return preg_match('/"static_token"\s*:\s*"([a-f0-9]+)"/', $html, $match) === 1 ? $match[1] : null;
    }

    private function brand(Crawler $crawler): ?string
    {
        $brand = null;

        $crawler->filter('script[type="application/ld+json"]')->each(function (Crawler $script) use (&$brand): void {
            $data = json_decode($script->text(''), true);

            if (is_array($data) && ($data['@type'] ?? null) === 'Product') {
                $brand ??= $this->text($data['brand']['name'] ?? null);
            }
        });

        return $brand;
    }

    private function get(string $url): string
    {
        $response = $this->send(fn () => Http::withOptions(['cookies' => $this->cookies])->withHeaders($this->headers())->timeout(20)->get($url));

        if (! $response->successful()) {
            throw new RuntimeException('HTTP '.$response->status().' '.$url);
        }

        return $response->body();
    }

    /**
     * The old shop answers 403/429 when asked too fast and lets the address back in after a while: wait and
     * retry, longer each time.
     *
     * @param  Closure(): Response  $request
     */
    private function send(Closure $request): Response
    {
        $attempt = 0;

        while (true) {
            $this->pause();
            $response = $request();

            if (! in_array($response->status(), [403, 429], true) || $attempt >= count($this->backoffSeconds)) {
                return $response;
            }

            $wait = $this->backoffSeconds[$attempt++];
            $this->emit(sprintf('  HTTP %d, waiting %d s before retrying', $response->status(), $wait));
            ($this->sleeper)($wait);
        }
    }

    /**
     * @param  list<int>  $seconds
     * @param  (Closure(int): void)|null  $sleeper
     */
    public function withBackoff(array $seconds, ?Closure $sleeper = null): self
    {
        $this->backoffSeconds = $seconds;
        $this->sleeper = $sleeper ?? fn (int $seconds) => sleep($seconds);

        return $this;
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0 Safari/537.36',
            'Accept-Language' => 'pl-PL,pl;q=0.9',
        ];
    }

    private function pause(): void
    {
        if ($this->delayMilliseconds > 0) {
            usleep($this->delayMilliseconds * 1000);
        }
    }

    private function emit(string $message): void
    {
        if ($this->progress !== null) {
            ($this->progress)($message);
        }
    }

    private function html(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return trim(strip_tags($value)) === '' ? null : $value;
    }

    private function text(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim(preg_replace('/\s+/u', ' ', html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');

        return $value === '' ? null : $value;
    }
}
