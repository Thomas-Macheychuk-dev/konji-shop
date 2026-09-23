<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Delivery\Polkurier\PolkurierAvailableCarriersService;
use Illuminate\Console\Command;
use Throwable;

final class RefreshPolkurierAvailableCarriersCommand extends Command
{
    protected $signature = 'polkurier:refresh-carriers
        {--json : Output result as JSON}';

    protected $description = 'Refresh the cached Polkurier available-carriers catalogue.';

    public function __construct(
        private readonly PolkurierAvailableCarriersService $availableCarriersService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $carriers = $this->availableCarriersService->refresh();
            $configuredCarriers = $this->availableCarriersService->configuredCarrierSummaries();
        } catch (Throwable $exception) {
            if ((bool) $this->option('json')) {
                $this->line(json_encode([
                    'refreshed' => false,
                    'carrier_count' => 0,
                    'error' => $exception->getMessage(),
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

                return self::FAILURE;
            }

            $this->error(sprintf(
                'Failed to refresh Polkurier available carriers: %s',
                $exception->getMessage(),
            ));

            return self::FAILURE;
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode([
                'refreshed' => true,
                'carrier_count' => count($carriers),
                'configured_carriers' => $configuredCarriers,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Polkurier available carriers refreshed. Cached records: %d.',
            count($carriers),
        ));

        return self::SUCCESS;
    }
}
