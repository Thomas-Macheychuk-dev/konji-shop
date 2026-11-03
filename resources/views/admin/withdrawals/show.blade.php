@extends('layouts.storefront')

@section('content')
    <div class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
        <div class="mb-8 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <a
                    href="{{ route('admin.withdrawals.index') }}"
                    class="text-sm font-medium text-zinc-500 hover:text-zinc-700"
                >
                    ← Wróć do odstąpień
                </a>

                <h1 class="mt-3 text-3xl font-bold tracking-tight text-zinc-900">
                    Odstąpienie {{ $withdrawalRequest->number }}
                </h1>

                <p class="mt-2 text-sm text-zinc-600">
                    Zgłoszono {{ $withdrawalRequest->submitted_at?->format('Y-m-d H:i') ?? '—' }}
                </p>
            </div>

            <span class="inline-flex self-start rounded-full px-3 py-1 text-sm font-semibold {{ $withdrawalRequest->status->badgeColorClasses() }}">
                {{ $withdrawalRequest->status->label() }}
            </span>
        </div>

        @if (session('success'))
            <div class="mb-6 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
                {{ session('success') }}
            </div>
        @endif

        @if (session('error'))
            <div class="mb-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                {{ session('error') }}
            </div>
        @endif

        <div class="grid gap-8 lg:grid-cols-3">
            <div class="space-y-8 lg:col-span-2">
                <div class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm">
                    <div class="border-b border-zinc-200 px-6 py-4">
                        <h2 class="text-lg font-semibold text-zinc-900">
                            Wybrane pozycje
                        </h2>
                    </div>

                    <div class="divide-y divide-zinc-200">
                        @foreach ($withdrawalRequest->items as $item)
                            <div class="px-6 py-5">
                                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                                    <div>
                                        <h3 class="font-semibold text-zinc-900">
                                            {{ $item->product_name_snapshot }}
                                        </h3>

                                        @if ($item->variant_name_snapshot)
                                            <p class="mt-1 text-sm text-zinc-600">
                                                {{ $item->variant_name_snapshot }}
                                            </p>
                                        @endif

                                        @if ($item->sku_snapshot)
                                            <p class="mt-2 text-sm text-zinc-500">
                                                SKU: {{ $item->sku_snapshot }}
                                            </p>
                                        @endif
                                    </div>

                                    <div class="text-sm sm:text-right">
                                        <p class="text-zinc-500">Żądana ilość</p>
                                        <p class="font-medium text-zinc-900">
                                            {{ $item->quantity_requested }} / {{ $item->quantity_ordered }}
                                        </p>

                                        <p class="mt-3 text-zinc-500">Kwota brutto</p>
                                        <p class="font-semibold text-zinc-900">
                                            {{ $item->lineGrossDecimal() }} {{ $withdrawalRequest->order?->currency ?? 'PLN' }}
                                        </p>

                                        @if ($item->value_reduction_amount > 0)
                                            <p class="mt-3 text-zinc-500">Do zwrotu po zmniejszeniu wartości</p>
                                            <p class="font-semibold text-zinc-900">
                                                {{ $item->refundableGrossDecimal() }} {{ $withdrawalRequest->order?->currency ?? 'PLN' }}
                                            </p>
                                        @endif
                                    </div>
                                </div>

                                @php
                                    $currency = $withdrawalRequest->order?->currency ?? 'PLN';
                                    $formatDecimal = fn (?int $amount): string => $amount === null ? '' : number_format($amount / 100, 2, ',', '');
                                    $isEditedItem = (string) old('item_id') === (string) $item->id;
                                    $sealValue = $isEditedItem ? old('inspection_hygienic_seal_broken') : $item->inspection_hygienic_seal_broken;
                                    $resellableValue = $isEditedItem ? old('inspection_resellable') : $item->inspection_resellable;
                                @endphp

                                @if ($item->soldWithHygienicSeal() || $item->soldAsCustomMade())
                                    <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                                        @if ($item->soldWithHygienicSeal())
                                            <p>Towar sprzedany z informacją o zabezpieczeniu higienicznym. Jeżeli zabezpieczenie zostało naruszone, sprawę kwalifikuj według § 10 Regulaminu (art. 38 ust. 1 pkt 5).</p>
                                        @endif
                                        @if ($item->soldAsCustomMade())
                                            <p @class(['mt-2' => $item->soldWithHygienicSeal()])>Towar sprzedany jako indywidualny / na specyfikację klienta (art. 38 ust. 1 pkt 3).</p>
                                        @endif
                                    </div>
                                @endif

                                <div class="mt-5 rounded-xl border border-zinc-200 bg-zinc-50 p-4">
                                    <div class="flex flex-col gap-1 sm:flex-row sm:items-baseline sm:justify-between">
                                        <h4 class="text-sm font-semibold text-zinc-900">Ocena stanu zwróconego Towaru</h4>
                                        <p class="text-xs text-zinc-500">
                                            @if ($item->inspected_at)
                                                Ostatnia ocena: {{ $item->inspected_at->format('Y-m-d H:i') }}@if ($item->inspectedBy), {{ $item->inspectedBy->name }}@endif
                                            @else
                                                Nie oceniono
                                            @endif
                                        </p>
                                    </div>

                                    <p class="mt-2 text-xs leading-5 text-zinc-500">
                                        Sam fakt używania nie wyklucza odstąpienia. Jeżeli wyjątek higieniczny nie ma zastosowania, oceń rzeczywiste zmniejszenie wartości (art. 34 ust. 4 ustawy o prawach konsumenta). Kwota zmniejszenia zostanie odjęta od zwrotu.
                                    </p>

                                    @if ($item->photos->isNotEmpty())
                                        <div class="mt-4 flex flex-wrap gap-3">
                                            @foreach ($item->photos as $photo)
                                                <a
                                                    href="{{ route('admin.withdrawals.photos.show', $photo) }}"
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    class="block overflow-hidden rounded-lg border border-zinc-200 bg-white"
                                                    title="{{ $photo->original_name }} · {{ $photo->created_at?->format('Y-m-d H:i') }}"
                                                >
                                                    <img
                                                        src="{{ route('admin.withdrawals.photos.show', $photo) }}"
                                                        alt="Zdjęcie zwróconego Towaru"
                                                        class="h-20 w-20 object-cover"
                                                        loading="lazy"
                                                    >
                                                </a>
                                            @endforeach
                                        </div>
                                    @endif

                                    @if ($withdrawalRequest->canBeInspected())
                                        <form
                                            method="POST"
                                            action="{{ route('admin.withdrawals.items.inspection.update', [$withdrawalRequest, $item]) }}"
                                            enctype="multipart/form-data"
                                            class="mt-4 grid gap-4 text-sm sm:grid-cols-2"
                                        >
                                            @csrf
                                            @method('PATCH')
                                            <input type="hidden" name="item_id" value="{{ $item->id }}">

                                            <label class="block">
                                                <span class="text-zinc-700">Stan Towaru</span>
                                                <select name="inspection_condition" class="mt-1.5 block w-full rounded-xl border border-zinc-300 bg-white px-3 py-2.5 text-sm text-zinc-900 outline-none transition focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100" required>
                                                    <option value="">— wybierz —</option>
                                                    @foreach (\App\Enums\WithdrawalItemCondition::cases() as $condition)
                                                        <option value="{{ $condition->value }}" @selected(($isEditedItem ? old('inspection_condition') : $item->inspection_condition?->value) === $condition->value)>
                                                            {{ $condition->label() }}
                                                        </option>
                                                    @endforeach
                                                </select>
                                            </label>

                                            <label class="block">
                                                <span class="text-zinc-700">Zabezpieczenie higieniczne</span>
                                                <select name="inspection_hygienic_seal_broken" class="mt-1.5 block w-full rounded-xl border border-zinc-300 bg-white px-3 py-2.5 text-sm text-zinc-900 outline-none transition focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100">
                                                    <option value="" @selected($sealValue === null || $sealValue === '')>Nie dotyczy / brak</option>
                                                    <option value="0" @selected($sealValue === false || $sealValue === '0')>Nienaruszone</option>
                                                    <option value="1" @selected($sealValue === true || $sealValue === '1')>Naruszone</option>
                                                </select>
                                            </label>

                                            <label class="flex items-center gap-2">
                                                <input type="hidden" name="inspection_signs_of_use" value="0">
                                                <input type="checkbox" name="inspection_signs_of_use" value="1" class="rounded border-zinc-300" @checked($isEditedItem ? old('inspection_signs_of_use') : $item->inspection_signs_of_use)>
                                                <span class="text-zinc-700">Ślady używania</span>
                                            </label>

                                            <label class="flex items-center gap-2">
                                                <input type="hidden" name="inspection_soiling" value="0">
                                                <input type="checkbox" name="inspection_soiling" value="1" class="rounded border-zinc-300" @checked($isEditedItem ? old('inspection_soiling') : $item->inspection_soiling)>
                                                <span class="text-zinc-700">Zabrudzenia / zanieczyszczenia biologiczne</span>
                                            </label>

                                            <label class="block sm:col-span-2">
                                                <span class="text-zinc-700">Brakujące elementy</span>
                                                <input type="text" name="inspection_missing_parts" value="{{ $isEditedItem ? old('inspection_missing_parts') : $item->inspection_missing_parts }}" class="mt-1.5 block w-full rounded-xl border border-zinc-300 bg-white px-3 py-2.5 text-sm text-zinc-900 outline-none transition focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100" placeholder="np. brak instrukcji, brak paska">
                                            </label>

                                            <label class="block">
                                                <span class="text-zinc-700">Możliwość ponownej sprzedaży</span>
                                                <select name="inspection_resellable" class="mt-1.5 block w-full rounded-xl border border-zinc-300 bg-white px-3 py-2.5 text-sm text-zinc-900 outline-none transition focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100">
                                                    <option value="" @selected($resellableValue === null || $resellableValue === '')>Nie oceniono</option>
                                                    <option value="1" @selected($resellableValue === true || $resellableValue === '1')>Tak</option>
                                                    <option value="0" @selected($resellableValue === false || $resellableValue === '0')>Nie</option>
                                                </select>
                                            </label>

                                            <label class="block">
                                                <span class="text-zinc-700">Wartość handlowa po zwrocie ({{ $currency }})</span>
                                                <input type="text" inputmode="decimal" name="inspection_commercial_value" value="{{ $isEditedItem ? old('inspection_commercial_value') : $formatDecimal($item->inspection_commercial_value_amount) }}" class="mt-1.5 block w-full rounded-xl border border-zinc-300 bg-white px-3 py-2.5 text-sm text-zinc-900 outline-none transition focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100">
                                            </label>

                                            <label class="block">
                                                <span class="text-zinc-700">Zmniejszenie wartości ({{ $currency }})</span>
                                                <input type="text" inputmode="decimal" name="value_reduction" value="{{ $isEditedItem ? old('value_reduction') : $formatDecimal((int) $item->value_reduction_amount) }}" class="mt-1.5 block w-full rounded-xl border border-zinc-300 bg-white px-3 py-2.5 text-sm text-zinc-900 outline-none transition focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100">
                                                <span class="mt-1 block text-xs text-zinc-500">Maks. {{ $item->lineGrossDecimal() }} {{ $currency }}. Odejmowane od zwrotu.</span>
                                            </label>

                                            <label class="block sm:col-span-2">
                                                <span class="text-zinc-700">Podstawa wyliczenia zmniejszenia wartości</span>
                                                <textarea name="value_reduction_basis" rows="3" class="mt-1.5 block w-full rounded-xl border border-zinc-300 bg-white px-3 py-2.5 text-sm text-zinc-900 outline-none transition focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100" placeholder="Wymagane, jeżeli zmniejszenie wartości jest większe od zera.">{{ $isEditedItem ? old('value_reduction_basis') : $item->value_reduction_basis }}</textarea>
                                            </label>

                                            <label class="block sm:col-span-2">
                                                <span class="text-zinc-700">Dodaj zdjęcia (JPG, PNG, WEBP, maks. 10 MB, do 10 naraz)</span>
                                                <input type="file" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple class="mt-1 block w-full text-sm">
                                            </label>

                                            @if ($isEditedItem && $errors->any())
                                                <ul class="space-y-1 text-sm text-red-600 sm:col-span-2">
                                                    @foreach ($errors->all() as $error)
                                                        <li>{{ $error }}</li>
                                                    @endforeach
                                                </ul>
                                            @endif

                                            <div class="sm:col-span-2">
                                                <button type="submit" class="inline-flex items-center justify-center rounded-xl bg-zinc-900 px-4 py-2 text-sm font-semibold text-white transition hover:bg-zinc-800">
                                                    Zapisz ocenę
                                                </button>
                                            </div>
                                        </form>
                                    @else
                                        <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                                            <div>
                                                <dt class="text-zinc-500">Stan Towaru</dt>
                                                <dd class="text-zinc-900">{{ $item->inspection_condition?->label() ?? '—' }}</dd>
                                            </div>
                                            <div>
                                                <dt class="text-zinc-500">Zabezpieczenie higieniczne</dt>
                                                <dd class="text-zinc-900">{{ $item->inspection_hygienic_seal_broken === null ? '—' : ($item->inspection_hygienic_seal_broken ? 'Naruszone' : 'Nienaruszone') }}</dd>
                                            </div>
                                            <div>
                                                <dt class="text-zinc-500">Ślady używania / zabrudzenia</dt>
                                                <dd class="text-zinc-900">{{ $item->inspection_signs_of_use ? 'Tak' : 'Nie' }} / {{ $item->inspection_soiling ? 'Tak' : 'Nie' }}</dd>
                                            </div>
                                            <div>
                                                <dt class="text-zinc-500">Brakujące elementy</dt>
                                                <dd class="text-zinc-900">{{ $item->inspection_missing_parts ?: '—' }}</dd>
                                            </div>
                                            <div>
                                                <dt class="text-zinc-500">Możliwość ponownej sprzedaży</dt>
                                                <dd class="text-zinc-900">{{ $item->inspection_resellable === null ? '—' : ($item->inspection_resellable ? 'Tak' : 'Nie') }}</dd>
                                            </div>
                                            <div>
                                                <dt class="text-zinc-500">Wartość handlowa po zwrocie</dt>
                                                <dd class="text-zinc-900">{{ $item->inspection_commercial_value_amount === null ? '—' : $formatDecimal($item->inspection_commercial_value_amount).' '.$currency }}</dd>
                                            </div>
                                            <div>
                                                <dt class="text-zinc-500">Zmniejszenie wartości</dt>
                                                <dd class="text-zinc-900">{{ $formatDecimal((int) $item->value_reduction_amount) }} {{ $currency }}</dd>
                                            </div>
                                            <div class="sm:col-span-2">
                                                <dt class="text-zinc-500">Podstawa wyliczenia</dt>
                                                <dd class="whitespace-pre-line text-zinc-900">{{ $item->value_reduction_basis ?: '—' }}</dd>
                                            </div>
                                        </dl>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm">
                    <h2 class="text-lg font-semibold text-zinc-900">
                        Oświadczenie klienta
                    </h2>

                    <dl class="mt-5 space-y-4 text-sm">
                        <div>
                            <dt class="text-zinc-500">Powód</dt>
                            <dd class="mt-1 whitespace-pre-line text-zinc-900">
                                {{ $withdrawalRequest->reason ?: '—' }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-zinc-500">Wiadomość</dt>
                            <dd class="mt-1 whitespace-pre-line text-zinc-900">
                                {{ $withdrawalRequest->customer_note ?: '—' }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-zinc-500">Notatka do zwrotu</dt>
                            <dd class="mt-1 whitespace-pre-line text-zinc-900">
                                {{ $withdrawalRequest->refund_note ?: '—' }}
                            </dd>
                        </div>
                    </dl>
                </div>
            </div>

            <div class="space-y-8">
                <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm">
                    <h2 class="text-lg font-semibold text-zinc-900">
                        Szczegóły
                    </h2>

                    <dl class="mt-5 space-y-3 text-sm">
                        <div>
                            <dt class="text-zinc-500">Zamówienie</dt>
                            <dd class="mt-1 font-medium text-zinc-900">
                                @if ($withdrawalRequest->order)
                                    <a
                                        href="{{ route('admin.orders.show', $withdrawalRequest->order) }}"
                                        class="hover:underline"
                                    >
                                        {{ $withdrawalRequest->order_number_snapshot }}
                                    </a>
                                @else
                                    {{ $withdrawalRequest->order_number_snapshot }}
                                @endif
                            </dd>
                        </div>

                        <div>
                            <dt class="text-zinc-500">Imię i nazwisko klienta</dt>
                            <dd class="mt-1 font-medium text-zinc-900">
                                {{ $withdrawalRequest->customer_name }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-zinc-500">E-mail klienta</dt>
                            <dd class="mt-1 break-all font-medium text-zinc-900">
                                {{ $withdrawalRequest->customer_email }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-zinc-500">Potwierdzono</dt>
                            <dd class="mt-1 font-medium text-zinc-900">
                                {{ $withdrawalRequest->acknowledged_at?->format('Y-m-d H:i') ?? '—' }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-zinc-500">Zwrócono środki</dt>
                            <dd class="mt-1 font-medium text-zinc-900">
                                {{ $withdrawalRequest->refunded_at?->format('Y-m-d H:i') ?? '—' }}
                            </dd>
                        </div>

                        @if ($withdrawalRequest->valueReductionAmount() > 0)
                            <div>
                                <dt class="text-zinc-500">Zmniejszenie wartości</dt>
                                <dd class="mt-1 font-medium text-zinc-900">
                                    {{ number_format($withdrawalRequest->valueReductionAmount() / 100, 2, '.', '') }} {{ $withdrawalRequest->order?->currency ?? 'PLN' }}
                                </dd>
                            </div>
                        @endif

                        <div>
                            <dt class="text-zinc-500">Kwota zwrotu</dt>
                            <dd class="mt-1 font-medium text-zinc-900">
                                {{ $withdrawalRequest->refundAmountDecimal() }} {{ $withdrawalRequest->order?->currency ?? 'PLN' }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-zinc-500">IP zgłoszenia</dt>
                            <dd class="mt-1 font-medium text-zinc-900">
                                {{ $withdrawalRequest->submission_ip ?: '—' }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-zinc-500">User agent</dt>
                            <dd class="mt-1 break-words font-medium text-zinc-900">
                                {{ $withdrawalRequest->submission_user_agent ?: '—' }}
                            </dd>
                        </div>
                    </dl>
                </div>
            </div>
        </div>
    </div>
@endsection
