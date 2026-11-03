<?php

use App\Enums\WithdrawalItemCondition;
use App\Enums\WithdrawalStatus;
use App\Models\User;
use App\Models\WithdrawalRequestItemPhoto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('shows the inspection form on the admin withdrawal detail page', function (): void {
    $admin = User::factory()->create(['is_admin' => true]);

    [$withdrawalRequest] = adminWithdrawalTestRequest();
    $item = $withdrawalRequest->items->sole();

    $this
        ->actingAs($admin)
        ->get(route('admin.withdrawals.show', $withdrawalRequest))
        ->assertOk()
        ->assertSee('Ocena stanu zwróconego Towaru')
        ->assertSee('Nie oceniono')
        ->assertSee('Ślady używania')
        ->assertSee('Zabrudzenia / zanieczyszczenia biologiczne')
        ->assertSee('Podstawa wyliczenia zmniejszenia wartości')
        ->assertSee(route('admin.withdrawals.items.inspection.update', [$withdrawalRequest, $item]), false);
});

it('saves the inspection with photos and deducts the value reduction from the refund', function (): void {
    Storage::fake('local');

    $admin = User::factory()->create(['is_admin' => true]);

    [$withdrawalRequest] = adminWithdrawalTestRequest();
    $item = $withdrawalRequest->items->sole();

    $this
        ->actingAs($admin)
        ->from(route('admin.withdrawals.show', $withdrawalRequest))
        ->patch(route('admin.withdrawals.items.inspection.update', [$withdrawalRequest, $item]), [
            'item_id' => $item->id,
            'inspection_condition' => WithdrawalItemCondition::USED->value,
            'inspection_hygienic_seal_broken' => '',
            'inspection_signs_of_use' => '1',
            'inspection_soiling' => '0',
            'inspection_missing_parts' => 'Brak instrukcji',
            'inspection_resellable' => '1',
            'inspection_commercial_value' => '93,00',
            'value_reduction' => '30,00',
            'value_reduction_basis' => 'Wyraźne ślady noszenia; wartość handlowa obniżona o 30 zł.',
            'photos' => [UploadedFile::fake()->image('front.jpg')],
        ])
        ->assertRedirect(route('admin.withdrawals.show', $withdrawalRequest))
        ->assertSessionHas('success');

    $item->refresh();

    expect($item)
        ->inspection_condition->toBe(WithdrawalItemCondition::USED)
        ->inspection_hygienic_seal_broken->toBeNull()
        ->inspection_signs_of_use->toBeTrue()
        ->inspection_soiling->toBeFalse()
        ->inspection_missing_parts->toBe('Brak instrukcji')
        ->inspection_resellable->toBeTrue()
        ->inspection_commercial_value_amount->toBe(9300)
        ->value_reduction_amount->toBe(3000)
        ->inspected_by_user_id->toBe($admin->id)
        ->inspected_at->not->toBeNull();

    expect($withdrawalRequest->refresh()->load('items')->refundAmount())->toBe(9300);

    $photo = WithdrawalRequestItemPhoto::query()->sole();

    expect($photo)
        ->withdrawal_request_item_id->toBe($item->id)
        ->disk->toBe('local')
        ->original_name->toBe('front.jpg');

    Storage::disk('local')->assertExists($photo->path);

    $this
        ->actingAs($admin)
        ->get(route('admin.withdrawals.photos.show', $photo))
        ->assertOk();

    $this
        ->actingAs($admin)
        ->get(route('admin.withdrawals.show', $withdrawalRequest))
        ->assertSee('Do zwrotu po zmniejszeniu wartości')
        ->assertSee('93.00 PLN');
});

it('requires a basis when a value reduction is recorded', function (): void {
    $admin = User::factory()->create(['is_admin' => true]);

    [$withdrawalRequest] = adminWithdrawalTestRequest();
    $item = $withdrawalRequest->items->sole();

    $this
        ->actingAs($admin)
        ->patch(route('admin.withdrawals.items.inspection.update', [$withdrawalRequest, $item]), [
            'inspection_condition' => WithdrawalItemCondition::USED->value,
            'value_reduction' => '10',
            'value_reduction_basis' => '',
        ])
        ->assertSessionHasErrors('value_reduction_basis');

    expect($item->refresh()->value_reduction_amount)->toBe(0);
});

it('does not allow a value reduction above the line gross amount', function (): void {
    $admin = User::factory()->create(['is_admin' => true]);

    [$withdrawalRequest] = adminWithdrawalTestRequest();
    $item = $withdrawalRequest->items->sole();

    $this
        ->actingAs($admin)
        ->patch(route('admin.withdrawals.items.inspection.update', [$withdrawalRequest, $item]), [
            'inspection_condition' => WithdrawalItemCondition::DAMAGED->value,
            'value_reduction' => '123.01',
            'value_reduction_basis' => 'Całkowita utrata wartości.',
        ])
        ->assertSessionHasErrors('value_reduction');

    $this
        ->actingAs($admin)
        ->patch(route('admin.withdrawals.items.inspection.update', [$withdrawalRequest, $item]), [
            'inspection_condition' => WithdrawalItemCondition::DAMAGED->value,
            'value_reduction' => '123.00',
            'value_reduction_basis' => 'Całkowita utrata wartości handlowej.',
        ])
        ->assertSessionHasNoErrors();

    expect($item->refresh()->refundableGrossAmount())->toBe(0);
});

it('locks the inspection once the refund is pending', function (): void {
    $admin = User::factory()->create(['is_admin' => true]);

    [$withdrawalRequest] = adminWithdrawalTestRequest();
    $withdrawalRequest->update(['status' => WithdrawalStatus::REFUND_PENDING]);
    $item = $withdrawalRequest->items->sole();

    $this
        ->actingAs($admin)
        ->patch(route('admin.withdrawals.items.inspection.update', [$withdrawalRequest, $item]), [
            'inspection_condition' => WithdrawalItemCondition::USED->value,
            'value_reduction' => '50',
            'value_reduction_basis' => 'Ślady używania.',
        ])
        ->assertRedirect()
        ->assertSessionHas('error');

    expect($item->refresh())
        ->inspection_condition->toBeNull()
        ->value_reduction_amount->toBe(0);

    $this
        ->actingAs($admin)
        ->get(route('admin.withdrawals.show', $withdrawalRequest))
        ->assertOk()
        ->assertDontSee('Zapisz ocenę');
});

it('rejects an item that belongs to another withdrawal request', function (): void {
    $admin = User::factory()->create(['is_admin' => true]);

    [$withdrawalRequest] = adminWithdrawalTestRequest();
    [$otherWithdrawalRequest] = adminWithdrawalTestRequest();

    $this
        ->actingAs($admin)
        ->patch(route('admin.withdrawals.items.inspection.update', [$withdrawalRequest, $otherWithdrawalRequest->items->sole()]), [
            'inspection_condition' => WithdrawalItemCondition::USED->value,
        ])
        ->assertNotFound();
});

it('flags items sold with a hygienic seal', function (): void {
    $admin = User::factory()->create(['is_admin' => true]);

    [$withdrawalRequest, $orderItem] = adminWithdrawalTestRequest();
    $orderItem->update([
        'meta' => ['legal_disclosures' => ['has_hygienic_seal' => true, 'is_custom_made' => false]],
    ]);

    $this
        ->actingAs($admin)
        ->get(route('admin.withdrawals.show', $withdrawalRequest))
        ->assertOk()
        ->assertSee('Towar sprzedany z informacją o zabezpieczeniu higienicznym');
});

it('does not allow non-admin users to inspect items or view photos', function (): void {
    Storage::fake('local');

    $user = User::factory()->create(['is_admin' => false]);

    [$withdrawalRequest] = adminWithdrawalTestRequest();
    $item = $withdrawalRequest->items->sole();

    $photo = $item->photos()->create([
        'disk' => 'local',
        'path' => 'withdrawals/test.jpg',
    ]);

    $this
        ->actingAs($user)
        ->patch(route('admin.withdrawals.items.inspection.update', [$withdrawalRequest, $item]), [
            'inspection_condition' => WithdrawalItemCondition::USED->value,
        ])
        ->assertForbidden();

    $this
        ->actingAs($user)
        ->get(route('admin.withdrawals.photos.show', $photo))
        ->assertForbidden();
});
