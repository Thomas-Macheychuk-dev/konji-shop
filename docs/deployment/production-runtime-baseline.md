# Production runtime baseline

This baseline keeps the single-host production MVP fast without adding a managed cache or additional application hosts.

## PHP / FPM

The production image uses an immutable-code OPcache profile. `opcache.validate_timestamps=0` is safe because deployments replace the container image; code is never edited in-place. PHP-FPM uses `ondemand` with at most six request workers so low traffic does not reserve memory for idle PHP processes. Each worker is recycled after 500 requests and has a 256 MiB request memory ceiling.

The CLI OPcache is enabled because the queue worker and scheduler are long-lived PHP processes. One-off Artisan commands still exit normally.

## Laravel caches

Deployment owns Laravel bootstrap-cache generation explicitly. It clears stale optimized state and then builds the configuration, route, view and event caches with `config:cache`, `route:cache`, `view:cache` and `event:cache`. `shop:check-runtime --json` is a blocking deployment gate and verifies the expected production runtime after those caches are created.

## Redis

The MVP keeps Redis on the application host. Cache, queues and sessions therefore avoid RDS round trips without introducing an ElastiCache bill. Redis queue blocking is set to five seconds to reduce idle polling CPU. The Redis eviction policy is `volatile-lru`, protecting queue keys that do not have TTLs from cache eviction when memory pressure occurs.

## Queue and scheduler

The queue worker is deliberately bounded by memory, maximum job count and maximum runtime, causing routine process recycling. The scheduler uses `schedule:work` instead of booting Laravel from a shell loop every minute.

## Production tuning

Do not increase `pm.max_children` until production metrics show sustained worker saturation. Increasing workers before they are needed raises the memory requirement of the EC2 host without making low-traffic pages faster.

After deployment verify:

```bash
php artisan shop:check-runtime
```

Then monitor request latency, FPM saturation, container memory and RDS load before changing worker counts or adding AWS services.
