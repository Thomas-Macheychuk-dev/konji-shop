<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\WithdrawalRequest;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class WithdrawalRequestSubmitted
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly WithdrawalRequest $withdrawalRequest,
    ) {}
}
