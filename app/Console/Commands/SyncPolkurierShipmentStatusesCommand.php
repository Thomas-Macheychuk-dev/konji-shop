<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\DeliveryProvider;
use App\Enums\ShipmentStatus;
use App\Models\Shipment;
use App\Services\Delivery\Polkurier\SyncPolkurierShipmentStatusService;
use Illuminate\Console\Command;
use Throwable;

final class SyncPolkurierShipmentStatusesCommand extends Command
{
    protected $signature = 'polkurier:sync-shipments
        {--limit=50 : Maximum number of shipments to sync}
        {--shipment= : Sync one shipment by ID}
        {--dry-run : Show matching shipments without syncing}';

    protected $description = 'Refresh statuses for active Polkurier shipments.';

    public function __construct(
        private readonly SyncPolkurierShipmentStatusService $syncStatus,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $shipmentId = $this->option('shipment');
        $dryRun = (bool) $this->option('dry-run');

        $query = Shipment::query()
            ->with('order')
            ->where('provider', DeliveryProvider::POLKURIER)
            ->whereNotNull('provider_reference')
            ->whereIn('status', [
                ShipmentStatus::PENDING,
                ShipmentStatus::CREATED,
                ShipmentStatus::DISPATCHED,
                ShipmentStatus::IN_TRANSIT,
            ])
            ->orderBy('id');

        if ($shipmentId !== null && $shipmentId !== '') {
            $query->whereKey((int) $shipmentId);
        } else {
            $query->limit($limit);
        }

        $shipments = $query->get();

        if ($shipments->isEmpty()) {
            $this->info('No Polkurier shipments found for status sync.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->table(
                ['ID', 'Order', 'Reference', 'Status'],
                $shipments->map(fn (Shipment $shipment): array => [
                    $shipment->id,
                    $shipment->order?->number ?? '—',
                    $shipment->provider_reference,
                    $shipment->status->value,
                ])->all(),
            );

            $this->info('Dry run complete. No shipments were synced.');

            return self::SUCCESS;
        }

        $synced = 0;
        $failed = 0;

        foreach ($shipments as $shipment) {
            try {
                $beforeStatus = $shipment->status;

                $syncedShipment = $this->syncStatus->sync($shipment);

                $synced++;

                $this->line(sprintf(
                    'Shipment #%d synced: %s → %s',
                    $shipment->id,
                    $beforeStatus->value,
                    $syncedShipment->status->value,
                ));
            } catch (Throwable $exception) {
                $failed++;

                $this->error(sprintf(
                    'Shipment #%d failed: %s',
                    $shipment->id,
                    $exception->getMessage(),
                ));
            }
        }

        $this->info(sprintf(
            'Polkurier shipment sync finished. Synced: %d. Failed: %d.',
            $synced,
            $failed,
        ));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
