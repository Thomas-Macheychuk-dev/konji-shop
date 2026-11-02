<?php

declare(strict_types=1);

namespace App\Services\Security;

final class VerifiedCrawlerVerifier
{
    public function __construct(
        private readonly CrawlerRangeRepository $ranges,
        private readonly IpRangeMatcher $matcher,
    ) {}

    /**
     * The configured crawler whose User-Agent this is, e.g. "google" or "bing".
     */
    public function crawlerFor(string $userAgent): ?string
    {
        foreach ((array) config('traffic_protection.verified_crawlers', []) as $crawler => $definition) {
            $pattern = (string) ($definition['user_agent_pattern'] ?? '');

            if ($pattern !== '' && preg_match($pattern, $userAgent) === 1) {
                return (string) $crawler;
            }
        }

        return null;
    }

    public function isVerified(string $crawler, string $ip): bool
    {
        foreach ($this->ranges->prefixes($crawler) as $prefix) {
            if ($this->matcher->contains($ip, $prefix)) {
                return true;
            }
        }

        return false;
    }

    public function hasRanges(string $crawler): bool
    {
        return $this->ranges->hasRanges($crawler);
    }
}
