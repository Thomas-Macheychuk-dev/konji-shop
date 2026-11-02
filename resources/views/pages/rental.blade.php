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
                <span class="text-slate-700">Wypożyczalnia sprzętu medycznego</span>
            </nav>

            <div class="relative mt-6 overflow-hidden rounded-[32px] bg-gradient-to-br from-[#0b3b70] via-[#155fa8] to-[#1674c4] px-6 py-10 text-white shadow-[0_24px_80px_rgba(11,59,112,0.22)] sm:px-10 sm:py-14">
                <div class="pointer-events-none absolute -right-24 -top-24 h-72 w-72 rounded-full border-[54px] border-white/5"></div>
                <div class="relative max-w-3xl">
                    <p class="text-xs font-bold uppercase tracking-[0.18em] text-blue-100">Poznań i okolice</p>
                    <h1 class="mt-3 text-3xl font-extrabold tracking-tight sm:text-5xl">Wypożyczalnia sprzętu medycznego</h1>
                    <p class="mt-5 text-base leading-7 text-blue-50 sm:text-lg">
                        Łóżka rehabilitacyjne, koncentratory tlenu, wózki inwalidzkie, podnośniki i schodołazy na każdy okres.
                        Sprzęt jest sprawdzony i gotowy do natychmiastowego użycia.
                    </p>

                    @if ($phones !== [])
                        <div class="mt-8 flex flex-wrap gap-3">
                            @foreach ($phones as $phone)
                                <a href="{{ $telHref($phone) }}" class="inline-flex items-center gap-2 rounded-xl {{ $loop->first ? 'bg-white text-[#0b3b70] hover:bg-blue-50' : 'border border-white/30 text-white hover:bg-white/10' }} px-5 py-3 text-sm font-bold transition">
                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 5.5A2.5 2.5 0 0 1 5.5 3h1.6a1 1 0 0 1 .95.68l1.2 3.6a1 1 0 0 1-.5 1.2l-1.6.8a12 12 0 0 0 5.6 5.6l.8-1.6a1 1 0 0 1 1.2-.5l3.6 1.2a1 1 0 0 1 .68.95v1.6A2.5 2.5 0 0 1 18.5 21C10 21 3 14 3 5.5Z" />
                                    </svg>
                                    {{ $phone }}
                                </a>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>

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

            @if ($groups->count() > 1)
                <nav class="mt-10 flex flex-wrap gap-2" aria-label="Rodzaje sprzętu">
                    @foreach ($groups as $group)
                        <a href="#{{ $group['key'] }}" class="rounded-xl border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 transition hover:border-blue-200 hover:bg-blue-50 hover:text-[#155fa8]">
                            {{ $group['heading'] }}
                        </a>
                    @endforeach
                </nav>
            @endif
        </div>
    </section>

    <div class="bg-white">
        <div class="mx-auto max-w-[1480px] px-4 pb-16 sm:px-6 lg:px-8">
            @foreach ($groups as $group)
                <section id="{{ $group['key'] }}" class="scroll-mt-28 pt-12">
                    <h2 class="text-2xl font-extrabold tracking-tight text-slate-950">{{ $group['heading'] }}</h2>

                    <div class="mt-6 grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                        @foreach ($group['items'] as $item)
                            <article class="flex flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                                <div class="flex aspect-[4/3] items-center justify-center bg-white p-4">
                                    @if ($item->image_path)
                                        <img src="{{ asset($item->image_path) }}" alt="{{ $item->name }}" class="max-h-full max-w-full object-contain" loading="lazy" decoding="async">
                                    @endif
                                </div>
                                <div class="flex flex-1 flex-col border-t border-slate-100 p-5">
                                    <h3 class="font-bold leading-snug text-slate-900">{{ $item->name }}</h3>
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
                                </div>
                            </article>
                        @endforeach
                    </div>
                </section>
            @endforeach

            <section class="mt-16 grid gap-6 rounded-[28px] border border-blue-100 bg-blue-50/70 p-6 sm:p-10 lg:grid-cols-[1.4fr_1fr] lg:items-center">
                <div>
                    <h2 class="text-2xl font-extrabold tracking-tight text-slate-950">Zapytaj o dostępność</h2>
                    <p class="mt-3 max-w-2xl leading-7 text-slate-600">
                        Zadzwoń i powiedz, jakiego sprzętu potrzebujesz i na jak długo. Potwierdzimy dostępność, warunki wynajmu i termin dostawy.
                        Oferujemy też materace przeciwodleżynowe (również na zlecenie NFZ), rotory do ćwiczeń kończyn dolnych i przyłóżkowe urządzenia do rehabilitacji kończyn górnych i dolnych.
                    </p>
                    @if ($address)
                        <p class="mt-4 text-sm font-semibold text-slate-700">{{ $address }}</p>
                    @endif
                </div>
                <div class="flex flex-col gap-3">
                    @foreach ($phones as $phone)
                        <a href="{{ $telHref($phone) }}" class="rounded-xl {{ $loop->first ? 'bg-[#155fa8] text-white hover:bg-[#0b3b70]' : 'border border-slate-200 bg-white text-slate-800 hover:border-blue-200 hover:text-[#155fa8]' }} px-5 py-3.5 text-center text-base font-bold transition">
                            {{ $phone }}
                        </a>
                    @endforeach
                    <a href="{{ route('legal.contact') }}" class="text-center text-sm font-semibold text-[#155fa8] transition hover:text-[#0b3b70]">Inne formy kontaktu</a>
                </div>
            </section>
        </div>
    </div>
@endsection
