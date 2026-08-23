<?php

declare(strict_types=1);

namespace App\Services\Security;

final class GoogleCrawlerVerifier
{
    public function __construct(
        private readonly GoogleCrawlerRangeRepository $ranges,
        private readonly IpRangeMatcher $matcher,
    ) {}

    public function isGoogleUserAgent(string $userAgent): bool
    {
        $pattern = (string) config('traffic_protection.google.user_agent_pattern');

        return $pattern !== '' && preg_match($pattern, $userAgent) === 1;
    }

    public function isVerified(string $ip, string $userAgent): bool
    {
        if (! $this->isGoogleUserAgent($userAgent)) {
            return false;
        }

        foreach ($this->ranges->prefixes() as $prefix) {
            if ($this->matcher->contains($ip, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
