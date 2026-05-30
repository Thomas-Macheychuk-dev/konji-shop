<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Delivery\Polkurier\PolkurierReadinessCheck;
use Illuminate\Console\Command;

final class CheckPolkurierConfigurationCommand extends Command
{
    protected $signature = 'polkurier:check
        {--json : Output result as JSON}';

    protected $description = 'Check whether Polkurier configuration is ready for production use.';

    public function __construct(
        private readonly PolkurierReadinessCheck $readinessCheck,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $items = $this->readinessCheck->items();
        $ready = $this->readinessCheck->isReady();

        if ((bool) $this->option('json')) {
            $this->line(json_encode([
                'ready' => $ready,
                'items' => $items,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return $ready ? self::SUCCESS : self::FAILURE;
        }

        $this->info('Polkurier configuration check');
        $this->newLine();

        $this->table(
            ['Category', 'Check', 'Status', 'Required', 'Message'],
            array_map(
                fn (array $item): array => [
                    $item['category'],
                    $item['name'],
                    $item['status'],
                    $item['required'] ? 'yes' : 'no',
                    $item['message'],
                ],
                $items,
            ),
        );

        $this->newLine();

        if ($ready) {
            $this->info('Polkurier is ready for production.');

            return self::SUCCESS;
        }

        $this->error('Polkurier is NOT ready for production.');

        return self::FAILURE;
    }
}
