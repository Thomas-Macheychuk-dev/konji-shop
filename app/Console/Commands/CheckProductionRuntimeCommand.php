<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Operations\ProductionRuntimeReadiness;
use Illuminate\Console\Command;

final class CheckProductionRuntimeCommand extends Command
{
    protected $signature = 'shop:check-runtime
        {--json : Output result as JSON}';

    protected $description = 'Check that the Laravel/PHP production runtime is optimized for the MVP deployment.';

    public function __construct(
        private readonly ProductionRuntimeReadiness $readiness,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $runtime = $this->readiness->snapshot();
        $items = $this->readiness->evaluate($runtime);
        $ready = $this->readiness->isReady($items);

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode([
                'ready' => $ready,
                'runtime' => $runtime,
                'items' => $items,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $ready ? self::SUCCESS : self::FAILURE;
        }

        $this->table(
            ['Check', 'Status', 'Value', 'Message'],
            array_map(
                static fn (array $item): array => [
                    $item['name'],
                    $item['status'],
                    is_bool($item['value'])
                        ? ($item['value'] ? 'true' : 'false')
                        : (string) $item['value'],
                    $item['message'],
                ],
                $items,
            ),
        );

        if ($ready) {
            $this->info('Production runtime baseline is ready.');

            return self::SUCCESS;
        }

        $this->error('Production runtime baseline is NOT ready.');

        return self::FAILURE;
    }
}
