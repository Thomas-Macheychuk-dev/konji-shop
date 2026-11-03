<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ComplaintChannel;
use App\Enums\ComplaintDemand;
use App\Enums\ComplaintStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Complaint extends Model
{
    public const DEADLINE_ANSWERED = 'answered';

    public const DEADLINE_OVERDUE = 'overdue';

    public const DEADLINE_DUE_SOON = 'due_soon';

    public const DEADLINE_OPEN = 'open';

    protected $fillable = [
        'number',
        'order_id',
        'order_number_snapshot',
        'customer_name',
        'customer_email',
        'customer_phone',
        'channel',
        'received_at',
        'product_description',
        'description',
        'customer_demand',
        'status',
        'responded_at',
        'response_channel',
        'response_summary',
        'internal_note',
        'created_by_user_id',
    ];

    protected static function booted(): void
    {
        // The consumer must get an answer within 14 days of receipt, or the complaint counts as accepted.
        static::saving(function (Complaint $complaint): void {
            if ($complaint->received_at !== null) {
                $complaint->response_due_on = $complaint->received_at
                    ->copy()
                    ->startOfDay()
                    ->addDays(self::responseDays());
            }
        });
    }

    protected function casts(): array
    {
        return [
            'channel' => ComplaintChannel::class,
            'customer_demand' => ComplaintDemand::class,
            'status' => ComplaintStatus::class,
            'response_channel' => ComplaintChannel::class,
            'received_at' => 'datetime',
            'response_due_on' => 'date',
            'responded_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * @param  Builder<Complaint>  $query
     */
    public function scopeAwaitingResponse(Builder $query): void
    {
        $query->whereNull('responded_at');
    }

    /**
     * Open complaints that have reached the internal alert day (by default day 10 of 14).
     *
     * @param  Builder<Complaint>  $query
     */
    public function scopeNeedsAttention(Builder $query, ?CarbonInterface $today = null): void
    {
        $today = ($today ?? now())->copy()->startOfDay();
        $daysBeforeDeadline = self::responseDays() - self::alertAfterDays();

        $query
            ->whereNull('responded_at')
            ->whereDate('response_due_on', '<=', $today->addDays($daysBeforeDeadline)->toDateString());
    }

    public function isAnswered(): bool
    {
        return $this->responded_at !== null;
    }

    public function alertOn(): CarbonInterface
    {
        return $this->response_due_on
            ->copy()
            ->subDays(self::responseDays() - self::alertAfterDays());
    }

    public function daysUntilDeadline(?CarbonInterface $today = null): int
    {
        $today = ($today ?? now())->copy()->startOfDay();

        return (int) $today->diffInDays($this->response_due_on->copy()->startOfDay(), false);
    }

    public function deadlineState(?CarbonInterface $today = null): string
    {
        if ($this->isAnswered()) {
            return self::DEADLINE_ANSWERED;
        }

        $daysLeft = $this->daysUntilDeadline($today);

        if ($daysLeft < 0) {
            return self::DEADLINE_OVERDUE;
        }

        if ($daysLeft <= self::responseDays() - self::alertAfterDays()) {
            return self::DEADLINE_DUE_SOON;
        }

        return self::DEADLINE_OPEN;
    }

    public function deadlineLabel(?CarbonInterface $today = null): string
    {
        $daysLeft = $this->daysUntilDeadline($today);

        return match ($this->deadlineState($today)) {
            self::DEADLINE_ANSWERED => 'Odpowiedziano '.$this->responded_at?->format('Y-m-d'),
            self::DEADLINE_OVERDUE => 'Po terminie ('.self::days(abs($daysLeft)).')',
            default => match (true) {
                $daysLeft === 0 => 'Termin dziś',
                $daysLeft === 1 => 'Został 1 dzień',
                self::isPolishFewForm($daysLeft) => "Zostały {$daysLeft} dni",
                default => "Zostało {$daysLeft} dni",
            },
        };
    }

    public function deadlineBadgeClasses(?CarbonInterface $today = null): string
    {
        return match ($this->deadlineState($today)) {
            self::DEADLINE_ANSWERED => 'bg-green-100 text-green-800',
            self::DEADLINE_OVERDUE => 'bg-red-100 text-red-800',
            self::DEADLINE_DUE_SOON => 'bg-amber-100 text-amber-800',
            default => 'bg-zinc-100 text-zinc-700',
        };
    }

    public static function responseDays(): int
    {
        return (int) config('legal.complaints.response_days', 14);
    }

    public static function alertAfterDays(): int
    {
        return (int) config('legal.complaints.alert_after_days', 10);
    }

    private static function days(int $count): string
    {
        return $count === 1 ? '1 dzień' : "{$count} dni";
    }

    private static function isPolishFewForm(int $count): bool
    {
        return in_array($count % 10, [2, 3, 4], true) && ! in_array($count % 100, [12, 13, 14], true);
    }

    public static function generateNumber(): string
    {
        do {
            $number = 'RK-'.now()->format('YmdHis').'-'.random_int(1000, 9999);
        } while (self::query()->where('number', $number)->exists());

        return $number;
    }
}
