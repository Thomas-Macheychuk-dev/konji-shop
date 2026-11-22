<?php

declare(strict_types=1);

namespace App\Services\Peruka;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Peruka.pl sells each colour of a wig as its own page. The "Wybierz kolor" strip (#product-colors) links the
 * other colours of the model, labelled with their colour codes; the page's own colour follows "#" in its title
 * ("… CALATA # 15/23/6R").
 */
final class PerukaColourLinks
{
    public function __construct(private readonly PerukaProductScraper $scraper) {}

    /**
     * @return array{label: string|null, siblings: array<string, string|null>}
     */
    public function parse(string $html, string $url): array
    {
        $crawler = new Crawler($html, $url);
        $self = $this->scraper->normalizeProductUrl($url) ?? $url;
        $siblings = [];

        $crawler->filter('#product-colors a[href]')->each(function (Crawler $node) use (&$siblings, $url): void {
            $href = $this->scraper->normalizeProductUrl((string) $node->attr('href'), $url);

            if ($href === null) {
                return;
            }

            $label = $this->clean($node->text(''));
            $siblings[$href] = $label ?? $siblings[$href] ?? null;
        });

        unset($siblings[$self]);

        return [
            'label' => $this->ownLabel($crawler),
            'siblings' => $siblings,
        ];
    }

    public function fetch(string $url): string
    {
        $response = Http::connectTimeout(5)
            ->timeout(15)
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0 Safari/537.36',
                'Accept-Language' => 'pl-PL,pl;q=0.9,en;q=0.8',
            ])
            ->get($url);

        if (! $response->successful()) {
            throw new RuntimeException('HTTP '.$response->status());
        }

        return $response->body();
    }

    private function ownLabel(Crawler $crawler): ?string
    {
        foreach (['h1', 'title'] as $selector) {
            $node = $crawler->filter($selector)->first();
            $text = $node->count() > 0 ? $node->text('') : '';

            if (str_contains($text, '#')) {
                return $this->clean(preg_replace('/\|.*$/u', '', substr($text, strrpos($text, '#') + 1)) ?? '');
            }
        }

        return null;
    }

    private function clean(string $text): ?string
    {
        $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');

        return $text !== '' ? $text : null;
    }
}
