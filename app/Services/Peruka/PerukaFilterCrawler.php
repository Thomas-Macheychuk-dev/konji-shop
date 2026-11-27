<?php

declare(strict_types=1);

namespace App\Services\Peruka;

use Closure;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Reads which products each peruka.pl filter value lists ("Długość fryzury: krótkie", "Kolor włosów: siwy"…).
 * The product pages do not name these properties; only the filtered category lists do.
 *
 * peruka.pl keeps the chosen filter in the session: a POST to /product/filter?category_id= stores it, then
 * /category/<slug>?filter=1 lists the matching products, one colour per model. So one value is fetched at a time
 * with one cookie jar, and the filter is cleared at the end. A value listing every product of a large category
 * means the filter did not take; it is reported as failed instead of tagging the whole category.
 */
final class PerukaFilterCrawler
{
    public const BASE_URL = 'https://www.peruka.pl';

    /** "Peruki" carries every wig filter; the other categories add their own (topper size, attachment…). */
    public const DEFAULT_CATEGORIES = ['peruki', 'toppery', 'treski', 'tupety'];

    /** "Peruki" lists about 750 models at 15 a page. */
    private const MAX_PAGES = 200;

    private int $delayMilliseconds = 500;

    private ?Closure $progress = null;

    private CookieJar $cookies;

    public function __construct(private readonly PerukaProductScraper $scraper)
    {
        $this->cookies = new CookieJar;
    }

    public function withDelay(int $milliseconds): self
    {
        $this->delayMilliseconds = max(0, $milliseconds);

        return $this;
    }

    public function withProgress(?Closure $progress): self
    {
        $this->progress = $progress;

        return $this;
    }

    /**
     * @param  list<int>  $filterIds  peruka.pl filter ids to read; others are listed with their values but not fetched
     * @return array{
     *     category_id: int,
     *     all: list<string>,
     *     filters: array<int, array{values: array<int, array{label: string|null, position: int, urls: list<string>|null}>}>,
     *     failed: array<string, string>,
     * }
     */
    public function crawlCategory(string $slug, array $filterIds): array
    {
        $failed = [];
        $form = $this->filterForm($this->get('/category/'.$slug));
        // A filter left in the session by a stopped run would narrow every list.
        $this->choose($form['category_id'], $slug, []);
        $all = $this->listing($slug, $failed);
        $filters = $form['filters'];

        foreach ($filters as $filterId => &$filter) {
            if (! in_array($filterId, $filterIds, true)) {
                continue;
            }

            foreach ($filter['values'] as $valueId => &$value) {
                $key = $slug.' '.$filterId.'/'.$valueId;
                $this->emit('  '.$key.' '.($value['label'] ?? '?'));

                try {
                    $this->choose($form['category_id'], $slug, [$filterId => $valueId]);
                    $urls = $this->listing($slug, $failed, filtered: true);
                } catch (RuntimeException $exception) {
                    $failed[$key] = $exception->getMessage();

                    continue;
                }

                if (count($all) > 30 && count($urls) >= count($all)) {
                    $failed[$key] = 'the filter did not narrow the list ('.count($urls).' of '.count($all).')';

                    continue;
                }

                $value['urls'] = $urls;
            }
            unset($value);
        }
        unset($filter);

        $this->choose($form['category_id'], $slug, []);

        return ['category_id' => $form['category_id'], 'all' => $all, 'filters' => $filters, 'failed' => $failed];
    }

    /**
     * The category's filter form: its category id and every filter with its values. Labels come from the
     * checkbox labels; picture filters (length, colour) have none.
     *
     * @return array{category_id: int, filters: array<int, array{values: array<int, array{label: string|null, position: int, urls: null}>}>}
     */
    public function filterForm(string $html): array
    {
        $form = (new Crawler($html))->filter('form[action*="filter?category_id="]');

        if ($form->count() === 0) {
            throw new RuntimeException('no filter form');
        }

        $form = $form->first();
        parse_str((string) parse_url((string) $form->attr('action'), PHP_URL_QUERY), $query);
        $categoryId = (int) ($query['category_id'] ?? 0);

        if ($categoryId <= 0) {
            throw new RuntimeException('no category id in the filter form');
        }

        $filters = [];
        $position = 0;

        $form->filter('input[name^="app_product_filter["]')->each(function (Crawler $input) use (&$filters, &$position): void {
            if (preg_match('/^app_product_filter\[(\d+)\]\[(\d+)\]$/', (string) $input->attr('name'), $match) !== 1) {
                return;
            }

            [$filterId, $valueId] = [(int) $match[1], (int) $match[2]];
            $label = null;

            foreach ($input->ancestors() as $ancestor) {
                if ($ancestor->nodeName === 'label') {
                    $label = trim(preg_replace('/\s+/u', ' ', $ancestor->textContent) ?? '');
                    break;
                }
            }

            $filters[$filterId] ??= ['values' => []];
            $filters[$filterId]['values'][$valueId] ??= ['label' => $label !== '' ? $label : null, 'position' => $position++, 'urls' => null];
        });

        return ['category_id' => $categoryId, 'filters' => $filters];
    }

    /**
     * Product URLs on a category list, following its pages.
     *
     * @param  array<string, string>  $failed
     * @return list<string>
     */
    public function listing(string $slug, array &$failed, bool $filtered = false): array
    {
        $first = '/category/'.$slug.($filtered ? '?filter=1' : '');
        $queue = [$first];
        $seen = [$first => true];
        $urls = [];

        while ($queue !== [] && count($seen) <= self::MAX_PAGES) {
            $page = array_shift($queue);
            $crawler = new Crawler($this->get($page));

            $crawler->filter('div.product a.product_name[href]')->each(function (Crawler $link) use (&$urls): void {
                $url = $this->scraper->normalizeProductUrl((string) $link->attr('href'), self::BASE_URL);

                if ($url !== null) {
                    $urls[$url] = true;
                }
            });

            $crawler->filter('.pagination a[href]')->each(function (Crawler $link) use (&$queue, &$seen, $slug, $filtered): void {
                $href = (string) $link->attr('href');
                $path = (string) parse_url($href, PHP_URL_PATH);

                if (! str_starts_with($path, '/category/'.$slug.'/')) {
                    return;
                }

                $next = $path.($filtered ? '?filter=1' : '');

                if (! isset($seen[$next])) {
                    $seen[$next] = true;
                    $queue[] = $next;
                }
            });
        }

        return array_keys($urls);
    }

    /**
     * The form posts back to the category page it came from, which peruka.pl reads from the Referer.
     *
     * @param  array<int, int>  $values  filter id => value id; empty clears the filter
     */
    private function choose(int $categoryId, string $slug, array $values): void
    {
        $form = ['type' => 'attr'];

        foreach ($values as $filterId => $valueId) {
            $form['app_product_filter['.$filterId.']['.$valueId.']'] = (string) $valueId;
        }

        $this->pause();
        $response = $this->http()
            ->withHeaders(['Referer' => self::BASE_URL.'/category/'.$slug])
            ->asForm()
            ->post(self::BASE_URL.'/product/filter?category_id='.$categoryId, $form);

        if ($response->failed()) {
            throw new RuntimeException('filter POST HTTP '.$response->status());
        }
    }

    private function get(string $path): string
    {
        $this->pause();
        $response = $this->http()->get(self::BASE_URL.$path);

        if ($response->failed()) {
            throw new RuntimeException('GET '.$path.' HTTP '.$response->status());
        }

        return $response->body();
    }

    private function http(): PendingRequest
    {
        return Http::connectTimeout(5)
            ->timeout(30)
            ->retry(2, 1000, throw: false)
            ->withOptions(['cookies' => $this->cookies])
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0 Safari/537.36',
                'Accept-Language' => 'pl-PL,pl;q=0.9,en;q=0.8',
            ]);
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
}
