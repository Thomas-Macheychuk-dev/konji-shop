<?php

use App\Enums\ComplaintChannel;
use App\Enums\ComplaintStatus;
use App\Models\Complaint;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-11-03 09:00:00'));
});

it('registers a complaint and computes the 14-day response deadline', function (): void {
    $admin = User::factory()->create(['is_admin' => true]);
    $order = Order::factory()->create(['number' => 'ORD-COMPLAINT-1']);

    $response = $this
        ->actingAs($admin)
        ->post(route('admin.complaints.store'), [
            'received_at' => '2026-11-02T15:30',
            'channel' => ComplaintChannel::EMAIL->value,
            'customer_name' => 'Anna Kowalska',
            'customer_email' => 'anna@example.test',
            'order_number' => 'ORD-COMPLAINT-1',
            'product_description' => 'Orteza kolana, rozmiar M',
            'description' => 'Pęknięty zapięcie po tygodniu.',
            'customer_demand' => 'replacement',
        ]);

    $complaint = Complaint::query()->sole();

    $response
        ->assertRedirect(route('admin.complaints.show', $complaint))
        ->assertSessionHas('success');

    expect($complaint)
        ->number->toStartWith('RK-')
        ->status->toBe(ComplaintStatus::RECEIVED)
        ->order_id->toBe($order->id)
        ->order_number_snapshot->toBe('ORD-COMPLAINT-1')
        ->created_by_user_id->toBe($admin->id)
        ->responded_at->toBeNull();

    expect($complaint->received_at->format('Y-m-d H:i'))->toBe('2026-11-02 15:30');
    expect($complaint->response_due_on->toDateString())->toBe('2026-11-16');
    expect($complaint->alertOn()->toDateString())->toBe('2026-11-12');
});

it('keeps an unknown order number without linking an order', function (): void {
    $admin = User::factory()->create(['is_admin' => true]);

    $this
        ->actingAs($admin)
        ->post(route('admin.complaints.store'), complaintPayload(['order_number' => 'PARAGON-123']))
        ->assertSessionHasNoErrors();

    expect(Complaint::query()->sole())
        ->order_id->toBeNull()
        ->order_number_snapshot->toBe('PARAGON-123');
});

it('does not accept a receipt date in the future', function (): void {
    $admin = User::factory()->create(['is_admin' => true]);

    $this
        ->actingAs($admin)
        ->post(route('admin.complaints.store'), complaintPayload(['received_at' => '2026-11-04T10:00']))
        ->assertSessionHasErrors('received_at');

    expect(Complaint::query()->count())->toBe(0);
});

it('reports the deadline state across the 14 days', function (string $today, string $state, string $label): void {
    $complaint = complaintReceivedOn('2026-11-02 15:30:00');
    $day = Carbon::parse($today);

    expect($complaint->deadlineState($day))->toBe($state)
        ->and($complaint->deadlineLabel($day))->toBe($label);
})->with([
    'day 1' => ['2026-11-03', Complaint::DEADLINE_OPEN, 'Zostało 13 dni'],
    'day 9' => ['2026-11-11', Complaint::DEADLINE_OPEN, 'Zostało 5 dni'],
    'day 10' => ['2026-11-12', Complaint::DEADLINE_DUE_SOON, 'Zostały 4 dni'],
    'last day' => ['2026-11-16', Complaint::DEADLINE_DUE_SOON, 'Termin dziś'],
    'overdue' => ['2026-11-18', Complaint::DEADLINE_OVERDUE, 'Po terminie (2 dni)'],
]);

it('lists unanswered complaints by deadline and highlights those needing attention', function (): void {
    $admin = User::factory()->create(['is_admin' => true]);

    $overdue = complaintReceivedOn('2026-10-15 10:00:00', ['customer_name' => 'Klient Po Terminie']);
    $dueSoon = complaintReceivedOn('2026-10-23 10:00:00', ['customer_name' => 'Klient Dzień Dziesiąty']);
    $fresh = complaintReceivedOn('2026-11-02 10:00:00', ['customer_name' => 'Klient Świeży']);
    $answered = complaintReceivedOn('2026-10-20 10:00:00', [
        'customer_name' => 'Klient Z Odpowiedzią',
        'status' => ComplaintStatus::REJECTED,
        'responded_at' => '2026-10-25 12:00:00',
        'response_channel' => ComplaintChannel::EMAIL,
        'response_summary' => 'Uszkodzenie mechaniczne.',
    ]);

    $this
        ->actingAs($admin)
        ->get(route('admin.complaints.index'))
        ->assertOk()
        ->assertSee('Reklamacje')
        ->assertSee('2 reklamacje wymagają odpowiedzi')
        ->assertSeeInOrder([$overdue->number, $dueSoon->number, $fresh->number])
        ->assertSee('Po terminie (5 dni)')
        ->assertSee('Zostały 3 dni')
        ->assertDontSee($answered->number);

    $this
        ->actingAs($admin)
        ->get(route('admin.complaints.index', ['filter' => 'all']))
        ->assertOk()
        ->assertSee($answered->number)
        ->assertSee('Odpowiedziano 2026-10-25');
});

it('records the answer sent to the customer', function (): void {
    $admin = User::factory()->create(['is_admin' => true]);
    $complaint = complaintReceivedOn('2026-11-02 10:00:00');

    $this
        ->actingAs($admin)
        ->patch(route('admin.complaints.update', $complaint), complaintPayload([
            'received_at' => '2026-11-02T10:00',
            'status' => ComplaintStatus::ACCEPTED->value,
            'responded_at' => '2026-11-03T08:30',
            'response_channel' => ComplaintChannel::EMAIL->value,
            'response_summary' => 'Uznajemy reklamację, wymiana na nowy egzemplarz.',
        ]))
        ->assertRedirect(route('admin.complaints.show', $complaint))
        ->assertSessionHasNoErrors();

    expect($complaint->refresh())
        ->status->toBe(ComplaintStatus::ACCEPTED)
        ->response_channel->toBe(ComplaintChannel::EMAIL)
        ->isAnswered()->toBeTrue();

    expect(Complaint::query()->needsAttention(Carbon::parse('2026-11-20'))->count())->toBe(0);
});

it('requires a response date for a decision status and a decision status for a response date', function (): void {
    $admin = User::factory()->create(['is_admin' => true]);
    $complaint = complaintReceivedOn('2026-11-02 10:00:00');

    $this
        ->actingAs($admin)
        ->patch(route('admin.complaints.update', $complaint), complaintPayload([
            'status' => ComplaintStatus::REJECTED->value,
        ]))
        ->assertSessionHasErrors('responded_at');

    $this
        ->actingAs($admin)
        ->patch(route('admin.complaints.update', $complaint), complaintPayload([
            'status' => ComplaintStatus::IN_REVIEW->value,
            'responded_at' => '2026-11-03T08:00',
            'response_channel' => ComplaintChannel::EMAIL->value,
            'response_summary' => 'Odpowiedź.',
        ]))
        ->assertSessionHasErrors('status');

    expect($complaint->refresh()->status)->toBe(ComplaintStatus::RECEIVED);
});

it('does not accept a phone call as the response channel', function (): void {
    $admin = User::factory()->create(['is_admin' => true]);
    $complaint = complaintReceivedOn('2026-11-02 10:00:00');

    $this
        ->actingAs($admin)
        ->patch(route('admin.complaints.update', $complaint), complaintPayload([
            'status' => ComplaintStatus::ACCEPTED->value,
            'responded_at' => '2026-11-03T08:00',
            'response_channel' => ComplaintChannel::PHONE->value,
            'response_summary' => 'Uznana telefonicznie.',
        ]))
        ->assertSessionHasErrors('response_channel');
});

it('shows the complaint detail page with its deadlines', function (): void {
    $admin = User::factory()->create(['is_admin' => true]);
    $complaint = complaintReceivedOn('2026-10-15 10:00:00');

    $this
        ->actingAs($admin)
        ->get(route('admin.complaints.show', $complaint))
        ->assertOk()
        ->assertSee('Reklamacja '.$complaint->number)
        ->assertSee('Termin odpowiedzi minął 2026-10-29')
        ->assertSee('Przypomnienie wewnętrzne')
        ->assertSee('2026-10-25')
        ->assertSee('Rozpatrzenie i odpowiedź');
});

it('does not allow non-admin users to use the complaints register', function (): void {
    $user = User::factory()->create(['is_admin' => false]);
    $complaint = complaintReceivedOn('2026-11-02 10:00:00');

    $this->actingAs($user)->get(route('admin.complaints.index'))->assertForbidden();
    $this->actingAs($user)->get(route('admin.complaints.show', $complaint))->assertForbidden();
    $this->actingAs($user)->post(route('admin.complaints.store'), complaintPayload())->assertForbidden();
});

it('links the complaints register in the admin navigation', function (): void {
    $admin = User::factory()->create(['is_admin' => true]);

    $this
        ->actingAs($admin)
        ->get(route('home'))
        ->assertOk()
        ->assertSee(route('admin.complaints.index'), false);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function complaintPayload(array $overrides = []): array
{
    return [
        'received_at' => '2026-11-02T10:00',
        'channel' => ComplaintChannel::EMAIL->value,
        'customer_name' => 'Jan Nowak',
        'customer_email' => 'jan@example.test',
        'product_description' => 'Pończochy uciskowe, klasa II',
        'description' => 'Pruje się szew.',
        'status' => ComplaintStatus::RECEIVED->value,
        ...$overrides,
    ];
}

/**
 * @param  array<string, mixed>  $attributes
 */
function complaintReceivedOn(string $receivedAt, array $attributes = []): Complaint
{
    return Complaint::query()->create([
        'number' => Complaint::generateNumber(),
        'customer_name' => 'Jan Nowak',
        'channel' => ComplaintChannel::EMAIL,
        'received_at' => $receivedAt,
        'product_description' => 'Pończochy uciskowe, klasa II',
        'description' => 'Pruje się szew.',
        'status' => ComplaintStatus::RECEIVED,
        ...$attributes,
    ]);
}
