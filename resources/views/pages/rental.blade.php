@extends('layouts.storefront')

@php
    $telHref = fn (string $phone): string => 'tel:'.preg_replace('/[^0-9+]/', '', $phone);
@endphp

@section('content')
    <section class="bg-white">
        <div class="mx-auto max-w-[1480px] px-4 pt-8 sm:px-6 lg:px-8 lg:pt-12">
            <nav class="text-sm text-slate-500" aria-label="Okruszki">
                <a href="{{ route('home') }}" class="transition hover:text-[#155fa8]">Strona główna</a>
                <span class="mx-2" aria-hidden="true">/</span>
                <span class="text-slate-700">{{ $page->title }}</span>
            </nav>

            <div class="relative mt-6 overflow-hidden rounded-[32px] bg-gradient-to-br from-[#0b3b70] via-[#155fa8] to-[#1674c4] px-6 py-10 text-white shadow-[0_24px_80px_rgba(11,59,112,0.22)] sm:px-10 sm:py-14">
                <div class="pointer-events-none absolute -right-24 -top-24 h-72 w-72 rounded-full border-[54px] border-white/5"></div>
                {{-- On small screens the enquiry panel follows the intro directly; the extra offer note comes after it. --}}
                <div class="relative grid gap-6 lg:grid-cols-[1.35fr_1fr] lg:gap-x-12 lg:gap-y-4">
                    <div class="lg:self-end">
                        <p class="text-xs font-bold uppercase tracking-[0.18em] text-blue-100">Poznań i okolice</p>
                        <h1 class="mt-3 text-3xl font-extrabold tracking-tight sm:text-5xl">{{ $page->title }}</h1>
                        <div class="mt-5 text-base leading-7 text-blue-50 sm:text-lg [&_a]:underline">
                            {!! $page->intro !!}
                        </div>
                    </div>

                    <div class="rounded-2xl bg-white p-6 text-slate-900 shadow-xl sm:p-7 lg:col-start-2 lg:row-span-2 lg:row-start-1 lg:self-center">
                        <h2 class="text-xl font-extrabold tracking-tight">Zapytaj o dostępność</h2>
                        <p class="mt-2 text-sm leading-6 text-slate-600">
                            Zadzwoń i powiedz, jakiego sprzętu potrzebujesz i na jak długo. Potwierdzimy dostępność, warunki wynajmu i termin dostawy.
                        </p>

                        @if ($phones !== [])
                            <div class="mt-5 flex flex-col gap-2.5">
                                @foreach ($phones as $phone)
                                    <a href="{{ $telHref($phone) }}" class="inline-flex items-center justify-center gap-2 rounded-xl {{ $loop->first ? 'bg-[#155fa8] text-white hover:bg-[#0b3b70]' : 'border border-slate-200 text-slate-800 hover:border-blue-200 hover:text-[#155fa8]' }} px-5 py-3 text-base font-bold transition">
                                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 5.5A2.5 2.5 0 0 1 5.5 3h1.6a1 1 0 0 1 .95.68l1.2 3.6a1 1 0 0 1-.5 1.2l-1.6.8a12 12 0 0 0 5.6 5.6l.8-1.6a1 1 0 0 1 1.2-.5l3.6 1.2a1 1 0 0 1 .68.95v1.6A2.5 2.5 0 0 1 18.5 21C10 21 3 14 3 5.5Z" />
                                        </svg>
                                        {{ $phone }}
                                    </a>
                                @endforeach
                            </div>
                        @endif

                        <a href="#zapytanie" class="mt-2.5 inline-flex w-full items-center justify-center gap-2 rounded-xl border border-slate-200 px-5 py-3 text-base font-bold text-slate-800 transition hover:border-blue-200 hover:text-[#155fa8]">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16v12H4z M4 7l8 6 8-6" />
                            </svg>
                            Napisz do nas
                        </a>

                        <div class="mt-5 flex flex-wrap items-center justify-between gap-2 border-t border-slate-100 pt-4 text-sm">
                            @if ($address)
                                <span class="font-semibold text-slate-700">{{ $address }}</span>
                            @endif
                            <a href="{{ route('legal.contact') }}" class="font-semibold text-[#155fa8] transition hover:text-[#0b3b70]">Inne formy kontaktu</a>
                        </div>
                    </div>

                    <div class="text-sm leading-6 text-blue-100 lg:col-start-1 lg:row-start-2 lg:self-start [&_a]:underline">
                        {!! $page->content !!}
                    </div>
                </div>
            </div>

            @if ($groups->count() > 1)
                <nav class="mt-6 flex flex-wrap items-center gap-2" aria-label="Rodzaje sprzętu">
                    <span class="mr-1 text-sm font-semibold text-slate-500">Przejdź do:</span>
                    @foreach ($groups as $group)
                        <a href="#{{ $group['key'] }}" class="rounded-xl border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 transition hover:border-blue-200 hover:bg-blue-50 hover:text-[#155fa8]">
                            {{ $group['heading'] }}
                        </a>
                    @endforeach
                </nav>
            @endif

            <ul class="mt-6 grid gap-4 sm:grid-cols-3">
                @foreach ([
                    ['Dostawa pod wskazany adres', 'Przywozimy sprzęt, wnosimy go do pomieszczenia i przygotowujemy do użycia.'],
                    ['Wynajem na dowolny okres', 'Po zakończeniu wynajmu możesz przedłużyć umowę albo oddać sprzęt.'],
                    ['Zwrot lub wymiana', 'Jeśli sprzęt nie spełni oczekiwań, przyjmiemy go z powrotem bez dodatkowych kosztów lub wymienimy na inny.'],
                ] as [$heading, $text])
                    <li class="rounded-2xl border border-slate-200 bg-slate-50/60 p-5">
                        <p class="font-bold text-slate-900">{{ $heading }}</p>
                        <p class="mt-1.5 text-sm leading-6 text-slate-600">{{ $text }}</p>
                    </li>
                @endforeach
            </ul>

            @php
                $field = 'block w-full rounded-xl border bg-white px-4 py-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-[#155fa8] focus:ring-4 focus:ring-blue-100';
            @endphp
            @php
                $enquirySent = (bool) session('rental_enquiry_sent');
                // Collapsed until asked for; open straight away after a send, on errors, or from an item's "Zapytaj" link.
                $enquiryOpen = $enquirySent || $errors->any() || $selectedItems !== [];
            @endphp
            {{-- Native <details>: opens and closes without JavaScript; the script below only opens it for #zapytanie links. --}}
            <details
                id="zapytanie"
                @if ($enquiryOpen) open @endif
                class="group mt-10 scroll-mt-32 lg:scroll-mt-44 rounded-[28px] border border-slate-200 bg-white p-6 shadow-sm sm:p-10"
            >
                <summary class="flex cursor-pointer list-none flex-col gap-5 lg:flex-row lg:items-center lg:justify-between lg:gap-10 [&::-webkit-details-marker]:hidden">
                    <div class="max-w-3xl">
                        <h2 class="text-2xl font-extrabold tracking-tight text-slate-950">Zapytaj e-mailem</h2>
                        <p class="mt-3 text-sm leading-6 text-slate-600">
                            Wolisz napisać niż dzwonić? Opisz, jakiego sprzętu potrzebujesz, na jak długo i dokąd go dostarczyć.
                            Odpowiemy na podany adres e-mail, a jeśli zostawisz numer telefonu, możemy też oddzwonić.
                        </p>
                    </div>

                    @unless ($enquirySent)
                        <span class="inline-flex shrink-0 items-center justify-center gap-2 rounded-xl bg-[#155fa8] px-6 py-3 text-sm font-bold text-white transition hover:bg-[#0b3b70]">
                            <span class="group-open:hidden">Napisz wiadomość</span>
                            <span class="hidden group-open:inline">Zwiń formularz</span>
                            <svg class="h-4 w-4 transition group-open:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6" />
                            </svg>
                        </span>
                    @endunless
                </summary>

                <div id="zapytanie-formularz" class="mt-8 border-t border-slate-100 pt-8">
                        @if ($enquirySent)
                            <div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-5 py-4 text-sm text-emerald-800" role="status">
                                <p class="font-bold">Dziękujemy, zapytanie zostało wysłane.</p>
                                <p class="mt-1">Odpowiemy najszybciej, jak to możliwe. W pilnych sprawach zadzwoń: {{ $phones[0] ?? '' }}.</p>
                            </div>
                        @else
                            @if ($errors->any())
                                <div class="mb-5 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert">
                                    Sprawdź zaznaczone pola i spróbuj ponownie.
                                </div>
                            @endif

                            <form method="POST" action="{{ route('rental.enquiry') }}" class="grid gap-5 sm:grid-cols-2">
                                @csrf
                                <div class="hidden" aria-hidden="true">
                                    <label for="website">Nie wypełniaj tego pola</label>
                                    <input id="website" type="text" name="website" tabindex="-1" autocomplete="off">
                                </div>

                                @foreach ([
                                    ['name', 'Imię i nazwisko', 'text', 'name', true],
                                    ['email', 'Adres e-mail', 'email', 'email', true],
                                    ['phone', 'Telefon (opcjonalnie)', 'tel', 'tel', false],
                                    ['city', 'Miejscowość dostawy (opcjonalnie)', 'text', 'address-level2', false],
                                ] as [$key, $label, $type, $autocomplete, $required])
                                    <div>
                                        <label for="enquiry-{{ $key }}" class="mb-2 block text-sm font-medium text-slate-700">{{ $label }}</label>
                                        <input id="enquiry-{{ $key }}" type="{{ $type }}" name="{{ $key }}" value="{{ old($key) }}" autocomplete="{{ $autocomplete }}" @required($required)
                                            class="{{ $field }} @error($key) border-red-300 @else border-slate-300 @enderror">
                                        @error($key)
                                            <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p>
                                        @enderror
                                    </div>
                                @endforeach

                                <div>
                                    <label for="enquiry-items" class="mb-2 block text-sm font-medium text-slate-700">Sprzęt (opcjonalnie)</label>
                                    <select id="enquiry-items" name="items[]" class="{{ $field }} @error('items.*') border-red-300 @else border-slate-300 @enderror">
                                        <option value="">Wybierz z listy</option>
                                        @foreach ($groups as $group)
                                            <optgroup label="{{ $group['heading'] }}">
                                                @foreach ($group['items'] as $item)
                                                    <option value="{{ $item->slug }}" @selected(in_array($item->slug, $selectedItems, true))>{{ $item->name }}</option>
                                                @endforeach
                                            </optgroup>
                                        @endforeach
                                    </select>
                                    @error('items.*')
                                        <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p>
                                    @enderror
                                </div>

                                <div>
                                    <label for="enquiry-period" class="mb-2 block text-sm font-medium text-slate-700">Okres wynajmu (opcjonalnie)</label>
                                    <input id="enquiry-period" type="text" name="period" value="{{ old('period') }}" placeholder="np. 3 miesiące, od 15 listopada"
                                        class="{{ $field }} @error('period') border-red-300 @else border-slate-300 @enderror">
                                    @error('period')
                                        <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p>
                                    @enderror
                                </div>

                                <div class="sm:col-span-2">
                                    <label for="enquiry-message" class="mb-2 block text-sm font-medium text-slate-700">Wiadomość</label>
                                    <textarea id="enquiry-message" name="message" rows="5" required minlength="10" maxlength="2000"
                                        class="{{ $field }} @error('message') border-red-300 @else border-slate-300 @enderror">{{ old('message') }}</textarea>
                                    @error('message')
                                        <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p>
                                    @enderror
                                </div>

                                <div class="flex flex-col gap-4 sm:col-span-2 sm:flex-row sm:items-center sm:justify-between">
                                    <p class="text-xs leading-5 text-slate-500">
                                        Dane z formularza wykorzystamy wyłącznie do odpowiedzi na zapytanie.
                                        Szczegóły w <a href="{{ route('legal.privacy') }}" class="font-semibold text-[#155fa8] hover:text-[#0b3b70]">polityce prywatności</a>.
                                    </p>
                                    <button type="submit" class="shrink-0 rounded-xl bg-[#155fa8] px-6 py-3 text-sm font-bold text-white transition hover:bg-[#0b3b70]">
                                        Wyślij zapytanie
                                    </button>
                                </div>
                            </form>
                        @endif
                </div>
            </details>
            <script>
                (() => {
                    const openEnquiry = () => {
                        if (location.hash === '#zapytanie') {
                            document.getElementById('zapytanie').open = true;
                        }
                    };
                    openEnquiry();
                    window.addEventListener('hashchange', openEnquiry);
                    // Clicking a #zapytanie link again leaves the hash unchanged, so open it on click too.
                    document.querySelectorAll('a[href="#zapytanie"]').forEach((link) => {
                        link.addEventListener('click', () => { document.getElementById('zapytanie').open = true; });
                    });
                })();
            </script>
        </div>
    </section>

    <div class="bg-white">
        <div class="mx-auto max-w-[1480px] px-4 pb-16 sm:px-6 lg:px-8">
            @foreach ($groups as $group)
                <section id="{{ $group['key'] }}" class="scroll-mt-32 lg:scroll-mt-44 pt-12">
                    <h2 class="text-2xl font-extrabold tracking-tight text-slate-950">{{ $group['heading'] }}</h2>

                    <div class="mt-6 grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                        @foreach ($group['items'] as $item)
                            <article class="flex flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm transition hover:border-blue-200">
                                <a href="{{ $item->url() }}" class="relative block aspect-[4/3] bg-white" tabindex="-1" aria-hidden="true">
                                    @if ($item->imageUrl())
                                        <img src="{{ $item->imageUrl() }}" alt="{{ $item->name }}" class="absolute inset-0 h-full w-full object-contain p-4" loading="lazy" decoding="async">
                                    @endif
                                </a>
                                <div class="flex flex-1 flex-col border-t border-slate-100 p-5">
                                    <h3 class="font-bold leading-snug text-slate-900">
                                        <a href="{{ $item->url() }}" class="transition hover:text-[#155fa8]">{{ $item->name }}</a>
                                    </h3>
                                    @if ($item->details)
                                        <p class="mt-2 text-sm leading-6 text-slate-600">{{ $item->details }}</p>
                                    @endif
                                    @if ($item->nfz_purchase)
                                        <p class="mt-3 inline-flex w-fit rounded-lg bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700">Możliwy zakup z dofinansowaniem NFZ</p>
                                    @endif

                                    <dl class="mt-auto grid grid-cols-2 gap-3 pt-5">
                                        <div class="rounded-xl bg-slate-50 px-3 py-2.5">
                                            <dt class="text-xs font-medium text-slate-500">Kaucja zwrotna</dt>
                                            <dd class="mt-0.5 font-bold {{ $item->deposit_min === null ? 'text-emerald-700' : 'text-slate-900' }}">{{ $item->depositLabel() }}</dd>
                                        </div>
                                        <div class="rounded-xl bg-blue-50 px-3 py-2.5">
                                            <dt class="text-xs font-medium text-slate-500">Opłata miesięczna</dt>
                                            <dd class="mt-0.5 font-bold text-[#155fa8]">{{ $item->monthlyLabel() }}</dd>
                                        </div>
                                    </dl>
                                    <div class="mt-4 flex items-center justify-between gap-3 text-sm font-semibold">
                                        <a href="{{ $item->url() }}" class="text-slate-700 transition hover:text-[#155fa8]">Szczegóły</a>
                                        <a href="{{ route('rental.index', ['sprzet' => $item->slug]) }}#zapytanie" rel="nofollow" class="text-[#155fa8] transition hover:text-[#0b3b70]">
                                            Zapytaj o ten sprzęt
                                        </a>
                                    </div>
                                </div>
                            </article>
                        @endforeach
                    </div>
                </section>
            @endforeach
        </div>
    </div>
@endsection
