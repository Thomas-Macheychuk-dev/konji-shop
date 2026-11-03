<?php

use App\Enums\ComplaintChannel;
use App\Enums\ComplaintStatus;
use App\Mail\ComplaintDeadlineAlertMail;
use App\Models\Complaint;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Mail::fake();
    config()->set('legal.complaints.alert_email', 'reklamacje@example.test');
    $this->travelTo(Carbon::parse('2026-11-12 06:00:00'));
});

function alertTestComplaint(string $receivedAt, array $attributes = []): Complaint
{
    return Complaint::query()->create([
        'number' => Complaint::generateNumber(),
        'customer_name' => 'Klient',
        'channel' => ComplaintChannel::EMAIL,
        'received_at' => $receivedAt,
        'product_description' => 'Orteza',
        'description' => 'Wada.',
        'status' => ComplaintStatus::RECEIVED,
        ...$attributes,
    ]);
}

it('emails unanswered complaints from day 10 onwards, including overdue ones', function (): void {
    $dayTen = alertTestComplaint('2026-11-02 18:00:00');
    $overdue = alertTestComplaint('2026-10-20 09:00:00');
    $dayNine = alertTestComplaint('2026-11-03 09:00:00');
    $answered = alertTestComplaint('2026-10-20 09:00:00', [
        'status' => ComplaintStatus::ACCEPTED,
        'responded_at' => '2026-10-22 09:00:00',
        'response_channel' => ComplaintChannel::EMAIL,
        'response_summary' => 'Uznana.',
    ]);

    $this->artisan('complaints:send-deadline-alerts')
        ->expectsOutputToContain('2 complaint(s)')
        ->assertSuccessful();

    Mail::assertSent(ComplaintDeadlineAlertMail::class, function (ComplaintDeadlineAlertMail $mail) use ($dayTen, $overdue, $dayNine, $answered): bool {
        $ids = $mail->complaints->pluck('id')->all();

        return $mail->hasTo('reklamacje@example.test')
            && $ids === [$overdue->id, $dayTen->id]
            && ! in_array($dayNine->id, $ids, true)
            && ! in_array($answered->id, $ids, true);
    });
});

it('sends nothing when no complaint needs attention', function (): void {
    alertTestComplaint('2026-11-05 09:00:00');

    $this->artisan('complaints:send-deadline-alerts')
        ->expectsOutputToContain('No complaints need attention.')
        ->assertSuccessful();

    Mail::assertNothingSent();
});

it('renders the reminder email with links to each complaint', function (): void {
    $complaint = alertTestComplaint('2026-10-20 09:00:00');

    $mail = new ComplaintDeadlineAlertMail(Complaint::query()->get());

    $mail->assertSeeInHtml($complaint->number);
    $mail->assertSeeInHtml(route('admin.complaints.show', $complaint), false);
    $mail->assertSeeInHtml('Po terminie (9 dni)');
});

it('is scheduled daily', function (): void {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event): bool => str_contains((string) $event->command, 'complaints:send-deadline-alerts'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 6 * * *');
});
