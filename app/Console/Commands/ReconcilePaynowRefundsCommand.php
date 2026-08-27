<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\PaymentRefundStatus;
use App\Models\PaymentRefund;
use App\Services\Withdrawals\ProcessWithdrawalRefundService;
use Illuminate\Console\Command;
use Throwable;

final class ReconcilePaynowRefundsCommand extends Command
{
    protected $signature = 'paynow:reconcile-refunds {--limit=50 : Maximum number of open refunds to reconcile}';

    protected $description = 'Reconcile open Paynow refunds and finalize locally only after provider success.';

    public function handle(ProcessWithdrawalRefundService $refundService): int
    {
        $limit = max(1, min(200, (int) $this->option('limit')));

        $refunds = PaymentRefund::query()
            ->where('provider', 'paynow')
            ->where(function ($query): void {
                $query->whereIn('status', [
                    PaymentRefundStatus::REQUESTED->value,
                    PaymentRefundStatus::NEW->value,
                    PaymentRefundStatus::PENDING->value,
                ])->orWhere(function ($query): void {
                    $query
                        ->where('status', PaymentRefundStatus::SUCCESSFUL->value)
                        ->whereNull('completed_at');
                });
            })
            ->orderBy('id')
            ->limit($limit)
            ->get();

        if ($refunds->isEmpty()) {
            $this->info('No open Paynow refunds to reconcile.');

            return self::SUCCESS;
        }

        $successful = 0;
        $pending = 0;
        $errors = 0;

        foreach ($refunds as $refund) {
            try {
                $result = $refundService->reconcile($refund);

                if ($result->isCompleted()) {
                    $successful++;
                    $this->line("Refund #{$result->id}: successful.");
                } else {
                    $pending++;
                    $this->line("Refund #{$result->id}: {$result->status->value}.");
                }
            } catch (Throwable $exception) {
                $errors++;
                report($exception);
                $this->warn("Refund #{$refund->id}: {$exception->getMessage()}");
            }
        }

        $this->info("Reconciled {$refunds->count()} refund(s): {$successful} completed, {$pending} pending, {$errors} error(s).");

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
