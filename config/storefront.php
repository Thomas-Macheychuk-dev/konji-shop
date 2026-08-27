<?php

return [
    'cache' => [
        'enabled' => (bool) env('STOREFRONT_CACHE_ENABLED', true),
        'store' => env('STOREFRONT_CACHE_STORE'),
        'product_pages_ttl' => (int) env('STOREFRONT_PRODUCT_PAGE_CACHE_TTL', 3600),
        'category_sidebar_ttl' => (int) env('STOREFRONT_CATEGORY_SIDEBAR_CACHE_TTL', 3600),
        'home_page_ttl' => (int) env('STOREFRONT_HOME_PAGE_CACHE_TTL', 300),
        'category_pages_ttl' => (int) env('STOREFRONT_CATEGORY_PAGE_CACHE_TTL', 600),
        'shop_configuration_ttl' => (int) env('STOREFRONT_SHOP_CONFIGURATION_CACHE_TTL', 300),
    ],
];
