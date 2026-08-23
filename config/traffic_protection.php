<?php

declare(strict_types=1);

$defaultHostname = parse_url((string) env('APP_URL', ''), PHP_URL_HOST);

return [
    'enabled' => (bool) env('TRAFFIC_PROTECTION_ENABLED', false),

    'human_cookie' => [
        'name' => env('TRAFFIC_HUMAN_COOKIE_NAME', 'konji_human_verified'),
        'lifetime_minutes' => (int) env('TRAFFIC_HUMAN_COOKIE_TTL_MINUTES', 1440),
    ],

    'turnstile' => [
        'site_key' => env('TURNSTILE_SITE_KEY'),
        'secret_key' => env('TURNSTILE_SECRET_KEY'),
        'siteverify_url' => env(
            'TURNSTILE_SITEVERIFY_URL',
            'https://challenges.cloudflare.com/turnstile/v0/siteverify',
        ),
        'action' => env('TURNSTILE_ACTION', 'human-traffic'),
        'allowed_hostnames' => array_values(array_filter(array_map(
            static fn (string $hostname): string => trim($hostname),
            explode(',', (string) env(
                'TURNSTILE_ALLOWED_HOSTNAMES',
                is_string($defaultHostname) ? $defaultHostname : '',
            )),
        ))),
        'timeout_seconds' => (int) env('TURNSTILE_TIMEOUT_SECONDS', 10),
        'attempts' => (int) env('TURNSTILE_ATTEMPTS', 2),
    ],

    'google' => [
        'ranges_file' => storage_path('app/security/google-crawler-ranges.json'),
        'sources' => [
            'https://developers.google.com/static/crawling/ipranges/common-crawlers.json',
            'https://developers.google.com/static/crawling/ipranges/special-crawlers.json',
            'https://developers.google.com/static/crawling/ipranges/user-triggered-fetchers.json',
            'https://developers.google.com/static/crawling/ipranges/user-triggered-fetchers-google.json',
            'https://developers.google.com/static/crawling/ipranges/user-triggered-agents.json',
        ],
        'user_agent_pattern' => '/(?:Googlebot|GoogleOther|Google-InspectionTool|Storebot-Google|Google-Site-Verification|Google-Read-Aloud|GoogleProducer|FeedFetcher-Google|AdsBot-Google|Mediapartners-Google|APIs-Google|GoogleMessages|Google-Pinpoint|Google-Agent)/i',
        'refresh_timeout_seconds' => (int) env('GOOGLE_CRAWLER_RANGES_TIMEOUT_SECONDS', 20),
        'refresh_attempts' => (int) env('GOOGLE_CRAWLER_RANGES_ATTEMPTS', 3),
    ],

    'exempt_paths' => [
        'human-check',
        'human-check/*',
        'payments/paynow/notifications',
        'robots.txt',
        'up',
    ],

    'blocked_user_agent_fragments' => [
        'ahrefsbot',
        'amazonbot',
        'anthropic-ai',
        'applebot',
        'baiduspider',
        'bingbot',
        'bytespider',
        'ccbot',
        'chatgpt-user',
        'claudebot',
        'cohere-ai',
        'curl/',
        'dataforseobot',
        'duckduckbot',
        'facebookexternalhit',
        'gptbot',
        'headlesschrome',
        'httpclient',
        'mj12bot',
        'petalbot',
        'python-requests',
        'scrapy',
        'semrushbot',
        'serpstatbot',
        'slurp',
        'sogou',
        'wget/',
        'yandexbot',
    ],
];
