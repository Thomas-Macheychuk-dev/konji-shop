<?php

return [
    'cache' => [
        'enabled' => (bool) env('STOREFRONT_CACHE_ENABLED', true),
        'store' => env('STOREFRONT_CACHE_STORE'),
        'product_pages_ttl' => (int) env('STOREFRONT_PRODUCT_PAGE_CACHE_TTL', 86400),
        'category_sidebar_ttl' => (int) env('STOREFRONT_CATEGORY_SIDEBAR_CACHE_TTL', 86400),
    ],
];
