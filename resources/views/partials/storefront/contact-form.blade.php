{{-- Rendered by the contact page itself, not by the editable page text, which the admin editor renders too. --}}
@php
    $contactField = 'block w-full rounded-xl border bg-white px-4 py-3 text-sm text-zinc-900 shadow-sm outline-none transition focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100';
@endphp
<section id="formularz" class="mt-4 scroll-mt-32 rounded-xl p-5 ring-1 ring-zinc-200">
    <h2 class="text-sm font-semibold text-zinc-900">Napisz do nas</h2>

    @if (session('contact_message_sent'))
        <div class="mt-3 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800" role="status">
            <p class="font-semibold">Dziękujemy, wiadomość została wysłana.</p>
            <p class="mt-1">Odpowiemy na podany adres e-mail najszybciej, jak to możliwe.</p>
        </div>
    @else
        @if ($errors->any())
            <div class="mt-3 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert">
                Sprawdź zaznaczone pola i spróbuj ponownie.
            </div>
        @endif

        <form method="POST" action="{{ route('contact.message') }}" class="mt-4 grid gap-4 sm:grid-cols-2">
            @csrf
            <div class="hidden" aria-hidden="true">
                <label for="contact-website">Nie wypełniaj tego pola</label>
                <input id="contact-website" type="text" name="website" tabindex="-1" autocomplete="off">
            </div>

            @foreach ([
                ['name', 'Imię i nazwisko', 'text', 'name', true],
                ['email', 'Adres e-mail', 'email', 'email', true],
                ['phone', 'Telefon (opcjonalnie)', 'tel', 'tel', false],
                ['order_number', 'Numer zamówienia (opcjonalnie)', 'text', 'off', false],
            ] as [$key, $label, $type, $autocomplete, $required])
                <div>
                    <label for="contact-{{ $key }}" class="mb-2 block text-sm font-medium text-zinc-700">{{ $label }}</label>
                    <input id="contact-{{ $key }}" type="{{ $type }}" name="{{ $key }}" value="{{ old($key) }}" autocomplete="{{ $autocomplete }}" @required($required)
                        class="{{ $contactField }} @error($key) border-red-300 @else border-zinc-300 @enderror">
                    @error($key)
                        <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p>
                    @enderror
                </div>
            @endforeach

            <div class="sm:col-span-2">
                <label for="contact-message" class="mb-2 block text-sm font-medium text-zinc-700">Wiadomość</label>
                <textarea id="contact-message" name="message" rows="5" required minlength="10" maxlength="3000"
                    class="{{ $contactField }} @error('message') border-red-300 @else border-zinc-300 @enderror">{{ old('message') }}</textarea>
                @error('message')
                    <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p>
                @enderror
            </div>

            <div class="flex flex-col gap-3 sm:col-span-2 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-xs leading-5 text-zinc-500">
                    Dane z formularza wykorzystamy wyłącznie do odpowiedzi na wiadomość.
                    Szczegóły w <a href="{{ route('legal.privacy') }}" class="font-medium text-zinc-900 underline underline-offset-4">polityce prywatności</a>.
                </p>
                <button type="submit" class="shrink-0 rounded-xl bg-[#155fa8] px-6 py-3 text-sm font-bold text-white transition hover:bg-[#0b3b70]">
                    Wyślij wiadomość
                </button>
            </div>
        </form>
    @endif
</section>
