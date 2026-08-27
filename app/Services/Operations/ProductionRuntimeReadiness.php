<?php

declare(strict_types=1);

namespace App\Services\Operations;

final class ProductionRuntimeReadiness
{
    /**
     * Evaluate the production runtime snapshot.
     *
     * @param  array<string, mixed>  $runtime
     * @return array<int, array{name: string, status: string, required: bool, value: mixed, message: string}>
     */
    public function evaluate(array $runtime): array
    {
        return [
            $this->check(
                'environment.production',
                ($runtime['environment'] ?? null) === 'production',
                true,
                $runtime['environment'] ?? null,
                'APP_ENV must be production.',
            ),
            $this->check(
                'environment.debug_disabled',
                ($runtime['debug'] ?? true) === false,
                true,
                $runtime['debug'] ?? null,
                'APP_DEBUG must be false.',
            ),
            $this->check(
                'laravel.config_cached',
                ($runtime['config_cached'] ?? false) === true,
                true,
                $runtime['config_cached'] ?? null,
                'Laravel configuration cache must be built.',
            ),
            $this->check(
                'laravel.routes_cached',
                ($runtime['routes_cached'] ?? false) === true,
                true,
                $runtime['routes_cached'] ?? null,
                'Laravel route cache must be built.',
            ),
            $this->check(
                'laravel.views_cached',
                (int) ($runtime['compiled_views'] ?? 0) > 0,
                true,
                $runtime['compiled_views'] ?? 0,
                'Blade views must be precompiled.',
            ),
            $this->check(
                'php.opcache_enabled',
                ($runtime['opcache_enabled'] ?? false) === true,
                true,
                $runtime['opcache_enabled'] ?? null,
                'OPcache must be enabled.',
            ),
            $this->check(
                'php.opcache_cli_enabled',
                ($runtime['opcache_cli_enabled'] ?? false) === true,
                true,
                $runtime['opcache_cli_enabled'] ?? null,
                'OPcache must be enabled for long-running queue/scheduler CLI processes.',
            ),
            $this->check(
                'php.opcache_timestamp_validation_disabled',
                ($runtime['opcache_validate_timestamps'] ?? true) === false,
                true,
                $runtime['opcache_validate_timestamps'] ?? null,
                'Immutable production containers should disable OPcache timestamp validation.',
            ),
            $this->check(
                'cache.redis',
                ($runtime['cache_store'] ?? null) === 'redis',
                true,
                $runtime['cache_store'] ?? null,
                'Production storefront cache should use the existing Redis container.',
            ),
            $this->check(
                'queue.redis',
                ($runtime['queue_connection'] ?? null) === 'redis',
                true,
                $runtime['queue_connection'] ?? null,
                'Production queues should use Redis instead of RDS polling.',
            ),
            $this->check(
                'session.redis',
                ($runtime['session_driver'] ?? null) === 'redis',
                true,
                $runtime['session_driver'] ?? null,
                'Production sessions should use Redis instead of RDS.',
            ),
            $this->check(
                'logging.stderr',
                ($runtime['log_channel'] ?? null) === 'stderr',
                true,
                $runtime['log_channel'] ?? null,
                'Container logs should use stderr rather than local log files.',
            ),
            $this->check(
                'logging.level',
                in_array((string) ($runtime['log_level'] ?? ''), [
                    'warning',
                    'error',
                    'critical',
                    'alert',
                    'emergency',
                ], true),
                true,
                $runtime['log_level'] ?? null,
                'Production logging should avoid debug/info ingestion volume.',
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return [
            'environment' => app()->environment(),
            'debug' => (bool) config('app.debug'),
            'config_cached' => app()->configurationIsCached(),
            'routes_cached' => app()->routesAreCached(),
            'compiled_views' => count(glob(storage_path('framework/views/*.php')) ?: []),
            'opcache_enabled' => $this->iniBool('opcache.enable'),
            'opcache_cli_enabled' => $this->iniBool('opcache.enable_cli'),
            'opcache_validate_timestamps' => $this->iniBool('opcache.validate_timestamps'),
            'cache_store' => (string) config('cache.default'),
            'queue_connection' => (string) config('queue.default'),
            'session_driver' => (string) config('session.driver'),
            'log_channel' => (string) config('logging.default'),
            'log_level' => strtolower((string) config('logging.channels.stderr.level')),
        ];
    }

    /**
     * @param  array<int, array{name: string, status: string, required: bool, value: mixed, message: string}>  $items
     */
    public function isReady(array $items): bool
    {
        foreach ($items as $item) {
            if ($item['required'] && $item['status'] !== 'PASS') {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{name: string, status: string, required: bool, value: mixed, message: string}
     */
    private function check(string $name, bool $passes, bool $required, mixed $value, string $message): array
    {
        return [
            'name' => $name,
            'status' => $passes ? 'PASS' : 'FAIL',
            'required' => $required,
            'value' => $value,
            'message' => $passes ? 'OK' : $message,
        ];
    }

    private function iniBool(string $key): bool
    {
        $value = ini_get($key);

        if ($value === false) {
            return false;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL);
    }
}
