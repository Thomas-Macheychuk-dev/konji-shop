<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Shop\ShopReadinessCheck;
use Illuminate\Console\Command;

final class CheckShopConfigurationCommand extends Command
{
    protected $signature = 'shop:check
        {--json : Output result as JSON}';

    protected $description = 'Check whether the shop configuration is ready for production use.';

    public function __construct(
        private readonly ShopReadinessCheck $readinessCheck,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $summary = $this->readinessCheck->summary();

        if ((bool) $this->option('json')) {
            $this->line(json_encode(
                $summary,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ));

            return $summary['ready'] ? self::SUCCESS : self::FAILURE;
        }

        $this->info('Shop production readiness check');
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
                $summary['items'],
            ),
        );

        $this->newLine();

        if ($summary['ready']) {
            $this->info('Shop configuration is ready for production.');

            return self::SUCCESS;
        }

        $this->error('Shop configuration is NOT ready for production.');

        return self::FAILURE;
    }
}
