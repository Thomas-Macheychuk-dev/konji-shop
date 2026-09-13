# Ortezka legacy SEO discovery (SEO-01)

`seo:legacy-discover` is the first, read-only boundary of the Ortezka URL migration.
It inventories the current legacy storefront before any redirect rules are created.

## Safety boundary

The command:

- performs no application database writes;
- creates no products, categories, pages, or redirects;
- changes no production runtime configuration;
- follows only the legacy storefront's own `ortezka.pl` / `www.ortezka.pl` URLs;
- records document/content-asset and operational URLs but does not fetch them;
- crawls query strings only when they consist exclusively of pagination keys (`page`, `p`), preventing faceted-navigation explosions;
- removes common tracking parameters before de-duplication;
- has hard URL and sitemap limits and reports when the URL cap has been reached, so a truncated crawl cannot be mistaken for complete evidence.

The only writes are the requested JSON/CSV evidence files and the append-only recovery checkpoint under `storage/app`.

## Authoritative first run

Run from the application container or a local environment that can reach the current live site:

```bash
php artisan seo:legacy-discover \
  --start-url=https://ortezka.pl/ \
  --max-urls=50000 \
  --max-sitemaps=100 \
  --request-delay-ms=250 \
  --attempts=3 \
  --timeout=20 \
  --checkpoint=scrapers/seo/ortezka/legacy-discovery.checkpoint.jsonl \
  --save=scrapers/seo/ortezka/legacy-inventory.json \
  --csv=scrapers/seo/ortezka/legacy-inventory.csv
```

Do **not** use `--insecure` for the authoritative run. TLS failures are migration evidence and should be fixed rather than suppressed.

## Recovery and bounded-memory export

SEO-01R1 avoids encoding the complete 50k-record inventory into one additional in-memory JSON string. The final JSON artifact is streamed one URL record at a time, and CSV is written before JSON so independently useful evidence survives an exporter problem.

The command also maintains an append-only JSONL checkpoint while crawling. If the process is interrupted after SEO-01R1 is installed, rerun the same command with `--resume` and the same `--checkpoint` path:

```bash
php artisan seo:legacy-discover \
  --start-url=https://ortezka.pl/ \
  --max-urls=50000 \
  --max-sitemaps=100 \
  --request-delay-ms=250 \
  --attempts=3 \
  --timeout=20 \
  --checkpoint=scrapers/seo/ortezka/legacy-discovery.checkpoint.jsonl \
  --resume \
  --save=scrapers/seo/ortezka/legacy-inventory.json \
  --csv=scrapers/seo/ortezka/legacy-inventory.csv
```

Checkpoint entries are URL-state snapshots, so a URL may appear multiple times; on resume the latest complete record for each URL wins. A truncated final JSONL line caused by a killed process is ignored. Starting without `--resume` truncates the checkpoint and performs a fresh crawl.

## Evidence captured per URL

The inventory includes, when available:

- normalized legacy URL and path;
- query string;
- page classification (`home`, `product`, `category`, `content`, `asset`, `operational`, `other`);
- numeric legacy ID for `-id-N`, `-cat-N`, and `/content/N-*` routes;
- observed HTTP status and redirect target;
- canonical URL;
- title and H1;
- robots meta value;
- product index/SKU/reference;
- manufacturer/brand;
- breadcrumb text;
- discovery source(s) and up to five referring pages;
- internal-link count;
- network error, if a request could not be completed.

The command also probes `robots.txt` for Sitemap directives and the conventional sitemap locations `/sitemap.xml`, `/1_index_sitemap.xml`, and `/sitemap_index.xml`.

## What SEO-01 does not prove

A public crawl cannot by itself prove that every historically valuable URL has been found. Before the redirect map is declared complete, SEO-03 must merge this inventory with the old PrestaShop database/export if available, Google Search Console exports, and production access logs. Those sources can reveal orphaned or historically indexed URLs that are no longer linked by the live storefront.


## Large sitemap reconciliation (SEO-01R2)

The live Ortezka child sitemap is multi-megabyte and contains more than 11,000 `<loc>` entries. Sitemap parsing therefore uses bounded offset scanning rather than a document-wide regular expression. This avoids PCRE/JIT limits, remains namespace-safe, and requires no additional PHP XML extension.

When an earlier crawl already has a recovery checkpoint, apply SEO-01R2 and rerun with `--resume`. Existing fetched URLs are retained; sitemap URLs absent from the checkpoint are admitted and only newly pending/failing crawlable URLs are fetched. Because the pre-R2 inventory already contains 48,694 URLs and the child sitemap contains 11,077 entries, use an evidence-based `--max-urls=65000` ceiling for this reconciliation run.

Acceptance evidence now includes `Sitemap entries parsed` and `URLs discovered from sitemap`. The authoritative run must report `URL cap reached: NO`.

## Bounded sitemap reconciliation (SEO-01R3)

SEO-01R3 adds `--sitemap-reconcile-only` for the post-discovery completeness pass. In this mode the crawler:

- loads the existing checkpoint;
- prunes non-sitemap query variants and image/media URLs from the active in-memory reconciliation set while preserving the append-only checkpoint as the audit trail;
- parses sitemap page `<loc>` entries while excluding `image:loc`, `video:loc`, and `news:loc` extension-namespace locations;
- never fetches image/media URLs;
- fetches newly admitted sitemap-backed page URLs as needed;
- extracts metadata from those pages but does not recursively discover additional HTML links.

This mode prevents a sitemap completeness check from becoming a second broad crawl and keeps the authoritative page-level migration inventory comfortably below the PHP memory and URL-cap boundaries.

Resume the existing checkpoint with:

```bash
php artisan seo:legacy-discover \
  --start-url=https://ortezka.pl/ \
  --max-urls=65000 \
  --max-sitemaps=100 \
  --request-delay-ms=250 \
  --attempts=3 \
  --timeout=20 \
  --checkpoint=scrapers/seo/ortezka/legacy-discovery.checkpoint.jsonl \
  --resume \
  --sitemap-reconcile-only \
  --save=scrapers/seo/ortezka/legacy-inventory.json \
  --csv=scrapers/seo/ortezka/legacy-inventory.csv
```

The summary reports the number of query variants and media URLs removed from the active reconciliation set. The final acceptance gate remains `URL cap reached: NO`.

## SEO-01R4: bounded checkpoint hydration

Large reconciliation checkpoints can contain tens of thousands of historical query and media records. In `--sitemap-reconcile-only` mode the command now prunes those records while streaming the JSONL checkpoint, before the retained URL records are hydrated into the crawler's in-memory inventory. Media records are excluded regardless of whether an earlier buggy sitemap parser tagged them as `sitemap` discoveries. Query variants are retained only when the sitemap itself explicitly references them.

Reconciliation HTML parsing is metadata-only: internal `<a href>` links are not materialized because this mode never follows them. The HTTP response wrapper is also released before DomCrawler builds its document tree. These changes keep the authoritative reconciliation within the normal 512 MiB PHP memory boundary without increasing `memory_limit`.

The summary reports checkpoint pruning counts and peak PHP memory so the memory boundary is visible in evidence.
