# Storefront cache baseline

The production MVP uses the existing Redis container on the application host for storefront cache/session/queue workloads. This is intentionally **not** Amazon ElastiCache: it avoids another fixed AWS service cost while traffic is small.

Recommended production values:

```env
STOREFRONT_CACHE_ENABLED=true
STOREFRONT_CACHE_STORE=redis
STOREFRONT_PRODUCT_PAGE_CACHE_TTL=3600
STOREFRONT_CATEGORY_SIDEBAR_CACHE_TTL=3600
STOREFRONT_HOME_PAGE_CACHE_TTL=300
STOREFRONT_CATEGORY_PAGE_CACHE_TTL=600
STOREFRONT_SHOP_CONFIGURATION_CACHE_TTL=300
```

Cached data is limited to anonymous, rebuildable presentation data. Cart, checkout, payment, refund, order, live mutation, and admin responses are not cached by this layer.

Catalogue/category/model writes bump versioned namespaces immediately. TTLs remain as a safety bound for relationship/pivot changes that may not emit a model event. Search result pages remain uncached and `noindex, follow`; this cache layer therefore does not create alternate crawlable URLs or user-specific HTML.

If the application later runs on multiple EC2/ECS hosts, move the shared cache to an appropriate managed/distributed store only when traffic and availability requirements justify that cost.
