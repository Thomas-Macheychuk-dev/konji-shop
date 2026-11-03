@php
    /** @var \App\Models\Complaint|null $complaint */
    $complaint = $complaint ?? null;
    $inputClasses = 'mt-1 block w-full rounded-lg border-zinc-300 text-sm';
    $value = fn (string $key, mixed $default = null) => old($key, $default);
    $dateTimeValue = fn (?\Carbon\CarbonInterface $date): ?string => $date?->format('Y-m-d\TH:i');
@endphp

@if ($errors->any())
    <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
        <ul class="list-disc space-y-1 pl-5">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm">
    <h2 class="text-lg font-semibold text-zinc-900">Zgłoszenie</h2>

    <div class="mt-5 grid gap-4 text-sm sm:grid-cols-2">
        <label class="block">
            <span class="text-zinc-700">Data i godzina otrzymania reklamacji</span>
            <input type="datetime-local" name="received_at" required value="{{ $value('received_at', $dateTimeValue($complaint?->received_at ?? now())) }}" class="{{ $inputClasses }}">
            <span class="mt-1 block text-xs text-zinc-500">Od tej daty liczy się 14 dni na odpowiedź.</span>
        </label>

        <label class="block">
            <span class="text-zinc-700">Sposób zgłoszenia</span>
            <select name="channel" required class="{{ $inputClasses }}">
                @foreach (\App\Enums\ComplaintChannel::cases() as $channel)
                    <option value="{{ $channel->value }}" @selected($value('channel', $complaint?->channel?->value ?? 'email') === $channel->value)>{{ $channel->label() }}</option>
                @endforeach
            </select>
        </label>

        <label class="block">
            <span class="text-zinc-700">Imię i nazwisko klienta</span>
            <input type="text" name="customer_name" required value="{{ $value('customer_name', $complaint?->customer_name) }}" class="{{ $inputClasses }}">
        </label>

        <label class="block">
            <span class="text-zinc-700">Numer zamówienia</span>
            <input type="text" name="order_number" value="{{ $value('order_number', $complaint?->order_number_snapshot) }}" class="{{ $inputClasses }}">
            <span class="mt-1 block text-xs text-zinc-500">Opcjonalnie. Brak numeru lub paragonu nie jest podstawą odmowy.</span>
        </label>

        <label class="block">
            <span class="text-zinc-700">E-mail klienta</span>
            <input type="email" name="customer_email" value="{{ $value('customer_email', $complaint?->customer_email) }}" class="{{ $inputClasses }}">
        </label>

        <label class="block">
            <span class="text-zinc-700">Telefon klienta</span>
            <input type="text" name="customer_phone" value="{{ $value('customer_phone', $complaint?->customer_phone) }}" class="{{ $inputClasses }}">
        </label>

        <label class="block sm:col-span-2">
            <span class="text-zinc-700">Towar</span>
            <input type="text" name="product_description" required maxlength="500" value="{{ $value('product_description', $complaint?->product_description) }}" class="{{ $inputClasses }}" placeholder="np. Orteza stawu kolanowego, rozmiar M">
        </label>

        <label class="block sm:col-span-2">
            <span class="text-zinc-700">Opis problemu</span>
            <textarea name="description" required rows="4" class="{{ $inputClasses }}">{{ $value('description', $complaint?->description) }}</textarea>
        </label>

        <label class="block">
            <span class="text-zinc-700">Żądanie klienta</span>
            <select name="customer_demand" class="{{ $inputClasses }}">
                <option value="">— nie wskazano —</option>
                @foreach (\App\Enums\ComplaintDemand::cases() as $demand)
                    <option value="{{ $demand->value }}" @selected($value('customer_demand', $complaint?->customer_demand?->value) === $demand->value)>{{ $demand->label() }}</option>
                @endforeach
            </select>
        </label>

        <label class="block sm:col-span-2">
            <span class="text-zinc-700">Notatka wewnętrzna</span>
            <textarea name="internal_note" rows="3" class="{{ $inputClasses }}">{{ $value('internal_note', $complaint?->internal_note) }}</textarea>
        </label>
    </div>
</div>

@if ($complaint)
    <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm">
        <h2 class="text-lg font-semibold text-zinc-900">Rozpatrzenie i odpowiedź</h2>
        <p class="mt-2 text-sm text-zinc-600">
            Odpowiedź musi trafić do klienta na papierze lub innym trwałym nośniku (e-mail, list, wydruk przekazany osobiście). Telefon nie wystarcza.
        </p>

        <div class="mt-5 grid gap-4 text-sm sm:grid-cols-2">
            <label class="block">
                <span class="text-zinc-700">Status</span>
                <select name="status" required class="{{ $inputClasses }}">
                    @foreach (\App\Enums\ComplaintStatus::cases() as $status)
                        <option value="{{ $status->value }}" @selected($value('status', $complaint->status->value) === $status->value)>{{ $status->label() }}</option>
                    @endforeach
                </select>
            </label>

            <label class="block">
                <span class="text-zinc-700">Data wysłania odpowiedzi</span>
                <input type="datetime-local" name="responded_at" value="{{ $value('responded_at', $dateTimeValue($complaint->responded_at)) }}" class="{{ $inputClasses }}">
            </label>

            <label class="block">
                <span class="text-zinc-700">Sposób przekazania odpowiedzi</span>
                <select name="response_channel" class="{{ $inputClasses }}">
                    <option value="">— wybierz —</option>
                    @foreach (\App\Enums\ComplaintChannel::durableMediumChannels() as $channel)
                        <option value="{{ $channel->value }}" @selected($value('response_channel', $complaint->response_channel?->value) === $channel->value)>{{ $channel->label() }}</option>
                    @endforeach
                </select>
            </label>

            <label class="block sm:col-span-2">
                <span class="text-zinc-700">Treść / streszczenie odpowiedzi</span>
                <textarea name="response_summary" rows="4" class="{{ $inputClasses }}">{{ $value('response_summary', $complaint->response_summary) }}</textarea>
            </label>
        </div>
    </div>
@endif
