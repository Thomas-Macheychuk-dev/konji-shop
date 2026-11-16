<?php

declare(strict_types=1);

namespace App\Services\Corradomed;

use Symfony\Component\DomCrawler\Crawler;

/**
 * Parses one corradomed.pl page. The site has no shop: each page shows a product family in one finish
 * (e.g. "UP - Uchwyty łazienkowe proste - malowane proszkowo fi 25 mm") with a table of codes (UP - 2 …
 * UP - 20) that the supplier price list prices. Pages are ISO-8859-2; pass the raw body.
 */
final class CorradomedPageScraper
{
    public const HOST = 'corradomed.pl';

    /**
     * Top-level sections. Every product page sits below one of them as "<section>?do=<key>".
     *
     * @var list<string>
     */
    public const SECTIONS = ['uchwyty', 'krzesla', 'siedzi', 'drazki', 'lustra', 'medycz'];

    /**
     * @return array{page: string, url: string, links: list<string>, product: array<string, mixed>|null}
     */
    public function parse(string $body, string $page): array
    {
        $html = $this->utf8($body);
        $crawler = new Crawler;
        $crawler->addHtmlContent($html, 'UTF-8');

        $content = $crawler->filter('td.tab3');

        return [
            'page' => $page,
            'url' => 'https://'.self::HOST.'/'.$page,
            'links' => $this->links($crawler),
            'product' => $content->count() > 0 ? $this->product($crawler, $content->first(), $page) : null,
        ];
    }

    /**
     * Normalises a corradomed.pl href to a page key ("uchwyty?do=proste01"), or null for anything else.
     */
    public static function pageKey(string $href): ?string
    {
        $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $parts = parse_url($href);

        if ($parts === false) {
            return null;
        }

        $host = preg_replace('/^www\./', '', mb_strtolower((string) ($parts['host'] ?? self::HOST)));
        $path = trim((string) ($parts['path'] ?? ''), '/');

        if ($host !== self::HOST || ! in_array($path, self::SECTIONS, true)) {
            return null;
        }

        parse_str((string) ($parts['query'] ?? ''), $query);
        $do = is_string($query['do'] ?? null) ? trim($query['do']) : '';

        if ($do === '') {
            return $path;
        }

        return preg_match('/^[a-z0-9_]+$/i', $do) === 1 ? $path.'?do='.$do : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function product(Crawler $page, Crawler $content, string $pageKey): ?array
    {
        // Section pages repeat their first sub-page; only "?do=" pages are products. A page without a code
        // table is still returned (the price list can name its codes); landing pages simply have no prices.
        if (! str_contains($pageKey, '?do=')) {
            return null;
        }

        $rows = $this->specificationRows($content);

        $section = $this->activeMenuLabel($page, '#cssmenu');
        $subsection = $this->activeMenuLabel($page, '#cssmenu2');
        [$title, $subtitle] = $this->title($content);
        $subtitle ??= $this->finishFromDocumentTitle($page);

        // Category landing pages that double as product pages (medical furniture) carry a generic h1.
        if ($title === null || ($section !== null && mb_strtolower($title) === mb_strtolower($section))) {
            $title = $subsection ?? $title;
        }

        return [
            'source' => 'corradomed',
            'page' => $pageKey,
            'source_url' => 'https://'.self::HOST.'/'.$pageKey,
            'title' => $title,
            'subtitle' => $subtitle,
            'section' => $section,
            'subsection' => $subsection,
            'codes' => $rows['codes'],
            'specification' => $rows['specification'],
            'notes' => $this->notes($content),
            'description_paragraphs' => $this->paragraphs($content),
            'upholstery_colours' => $this->upholsteryColours($content),
            'images' => $this->images($content),
        ];
    }

    /**
     * Rows of the dimension tables (first header cell "Oznaczenie …"). Colour swatch tables are skipped.
     *
     * @return array{codes: list<string>, specification: list<array{code: string, values: array<string, string>}>}
     */
    private function specificationRows(Crawler $content): array
    {
        $codes = [];
        $specification = [];

        $content->filter('table.wyroby2')->each(function (Crawler $table) use (&$codes, &$specification): void {
            $rows = $table->filter('tr');

            if ($rows->count() < 2) {
                return;
            }

            $headers = $rows->first()->filter('td, th')->each(fn (Crawler $cell): string => $this->text($cell->text('')));

            if (! str_starts_with(mb_strtolower(str_replace(' ', '', $headers[0] ?? '')), 'oznaczenie')) {
                return;
            }

            $rows->slice(1)->each(function (Crawler $row) use ($headers, &$codes, &$specification): void {
                $cells = $row->filter('td, th')->each(fn (Crawler $cell): string => $this->text($cell->text('')));
                $code = $cells[0] ?? '';

                // Notes spanning the table ("Informacja: …") and repeated header rows are not codes.
                if ($code === '' || count($cells) < 2 || str_starts_with(mb_strtolower(str_replace(' ', '', $code)), 'oznaczenie')
                    || mb_strlen($code) > 60) {
                    return;
                }

                $values = [];

                foreach (array_slice($cells, 1) as $index => $value) {
                    $label = $headers[$index + 1] ?? null;

                    if ($label !== null && $label !== '' && $value !== '') {
                        $values[$label] = $value;
                    }
                }

                if (! in_array($code, $codes, true)) {
                    $codes[] = $code;
                    $specification[] = ['code' => $code, 'values' => $values];
                }
            });
        });

        return ['codes' => $codes, 'specification' => $specification];
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private function title(Crawler $content): array
    {
        $h1 = $content->filter('h1');

        if ($h1->count() === 0) {
            return [null, null];
        }

        $node = $h1->first();
        $subtitle = $node->filter('span')->count() > 0 ? $this->text($node->filter('span')->first()->text('')) : null;
        $full = $this->text($node->text(''));
        $title = $subtitle !== null && str_ends_with($full, $subtitle) ? mb_substr($full, 0, mb_strlen($full) - mb_strlen($subtitle)) : $full;

        $clean = fn (?string $value): ?string => ($value = trim(preg_replace('/\s*\|\s*Corradomed\s*$/iu', '', (string) $value) ?? '', " \t-–<")) === '' ? null : $value;

        return [$clean($title), $clean($subtitle)];
    }

    /**
     * Some headings leave out the finish that the <title> names ("Uchwyty umywalkowe stałe - malowane
     * proszkowo fi 25 mm - Corradomed").
     */
    private function finishFromDocumentTitle(Crawler $page): ?string
    {
        $node = $page->filter('title');
        $parts = $node->count() > 0 ? array_map('trim', explode(' - ', $this->text($node->first()->text('')))) : [];

        foreach (array_slice($parts, 1) as $part) {
            if (preg_match('/malowan|stal nierdzewna|inox/iu', $part) === 1) {
                return $part;
            }
        }

        return null;
    }

    private function activeMenuLabel(Crawler $page, string $menu): ?string
    {
        $node = $page->filter($menu.' a.activ');

        return $node->count() > 0 ? ($this->text($node->first()->text('')) ?: null) : null;
    }

    /**
     * @return list<string>
     */
    private function notes(Crawler $content): array
    {
        $notes = [];

        $content->filter('div > strong, table.wyroby2 td')->each(function (Crawler $node) use (&$notes): void {
            $text = $this->text($node->text(''));

            if (preg_match('/^Informacja:\s*(.+)$/iu', $text, $m) === 1) {
                $notes[$m[1]] = true;
            }
        });

        return array_keys($notes);
    }

    /**
     * Descriptive copy on the page (only the medical furniture pages have any), without cross-links.
     *
     * @return list<array{type: string, text: string}>
     */
    private function paragraphs(Crawler $content): array
    {
        $blocks = [];

        $content->filter('h2, h3, p')->each(function (Crawler $node) use (&$blocks): void {
            $text = $this->text($node->text(''));

            if ($text === '' || preg_match('/^Sprawdź także\b/iu', $text) === 1 || str_contains(mb_strtolower($text), 'kliknij')) {
                return;
            }

            $blocks[] = ['type' => $node->nodeName() === 'p' ? 'p' : 'h2', 'text' => $text];
        });

        return $blocks;
    }

    /**
     * Upholstery colours from the swatch table ("1004 - łososiowy"), when the page has one.
     *
     * @return list<string>
     */
    private function upholsteryColours(Crawler $content): array
    {
        $colours = [];

        $content->filter('table.wyroby2 td')->each(function (Crawler $cell) use (&$colours): void {
            $text = $this->text($cell->text(''));

            if (preg_match('/^(\d{4})\s*-\s*(\p{L}[\p{L} .]*)$/u', $text, $m) === 1) {
                $colours[$m[1]] = $m[1].' '.trim($m[2]);
            }
        });

        return array_values($colours);
    }

    /**
     * Product photos and drawings: the enlarged version an image links to, else the image itself. Logos,
     * colour swatches and accessory thumbnails are skipped.
     *
     * @return list<string>
     */
    private function images(Crawler $content): array
    {
        $urls = [];

        $content->filter('img')->each(function (Crawler $img) use (&$urls): void {
            $src = (string) $img->attr('src');
            $link = $img->closest('a');
            $href = $link !== null ? (string) $link->attr('href') : '';
            $candidate = preg_match('/\.(jpe?g|png|webp)$/i', $href) === 1 ? $href : $src;
            $url = $this->imageUrl($candidate);

            if ($url !== null) {
                $urls[$url] = true;
            }
        });

        return array_keys($urls);
    }

    private function imageUrl(string $path): ?string
    {
        $path = trim(html_entity_decode($path, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($path === '' || str_starts_with($path, 'data:')) {
            return null;
        }

        $parts = parse_url($path);
        $host = preg_replace('/^www\./', '', mb_strtolower((string) ($parts['host'] ?? self::HOST)));
        $relative = ltrim((string) ($parts['path'] ?? ''), '/');

        if ($host !== self::HOST || $relative === '' || preg_match('#^(rys/|.*wzornik|.*zawieszka|.*copyright|.*logo)#iu', $relative) === 1) {
            return null;
        }

        return 'https://'.self::HOST.'/'.$relative;
    }

    /**
     * @return list<string>
     */
    private function links(Crawler $crawler): array
    {
        $links = [];

        $crawler->filter('a[href]')->each(function (Crawler $node) use (&$links): void {
            $key = self::pageKey((string) $node->attr('href'));

            if ($key !== null) {
                $links[$key] = true;
            }
        });

        return array_keys($links);
    }

    private function utf8(string $body): string
    {
        if (! mb_check_encoding($body, 'UTF-8')) {
            $body = mb_convert_encoding($body, 'UTF-8', 'ISO-8859-2');
        }

        // The pages declare iso-8859-2; the body is UTF-8 now, so the declaration must not be honoured.
        return preg_replace('/charset\s*=\s*["\']?iso-8859-2/i', 'charset=utf-8', $body) ?? $body;
    }

    private function text(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = str_replace("\xc2\xa0", ' ', $value);

        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }
}
