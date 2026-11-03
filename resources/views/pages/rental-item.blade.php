@extends('layouts.storefront')

@php
    $telHref = fn (string $phone): string => 'tel:'.preg_replace('/[^0-9+]/', '', $phone);
@endphp

@section('content')
    <section class="bg-white">
        <div class="mx-auto max-w-[1480px] px-4 pb-16 pt-8 sm:px-6 lg:px-8 lg:pt-12">
            <nav class="text-sm text-slate-500" aria-label="Okruszki">
                <a href="{{ route('home') }}" class="transition hover:text-[#155fa8]">Strona główna</a>
                <span class="mx-2" aria-hidden="true">/</span>
                <a href="{{ route('rental.index') }}" class="transition hover:text-[#155fa8]">Wypożyczalnia sprzętu medycznego</a>
                <span class="mx-2" aria-hidden="true">/</span>
                <a href="{{ route('rental.index') }}#{{ $item->group }}" class="transition hover:text-[#155fa8]">{{ $item->groupHeading() }}</a>
                <span class="mx-2" aria-hidden="true">/</span>
                <span class="text-slate-700">{{ $item->name }}</span>
            </nav>

            <div class="mt-6 grid gap-8 lg:grid-cols-[1.1fr_1fr] lg:gap-12">
                <div class="flex aspect-[4/3] items-center justify-center rounded-[28px] border border-slate-200 bg-white p-6 sm:p-10">
                    @if ($item->imageUrl())
                        <img src="{{ $item->imageUrl() }}" alt="{{ $item->name }}" class="max-h-full max-w-full object-contain" decoding="async" fetchpriority="high">
                    @endif
                </div>

                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.18em] text-[#155fa8]">Wypożyczalnia · Poznań i okolice</p>
                    <h1 class="mt-3 text-3xl font-extrabold tracking-tight text-slate-950 sm:text-4xl">{{ $item->name }}</h1>

                    @if ($item->details)
                        <p class="mt-4 text-base leading-7 text-slate-600">{{ $item->details }}</p>
                    @endif

                    @if ($item->nfz_purchase)
                        <p class="mt-4 inline-flex rounded-lg bg-emerald-50 px-3 py-1.5 text-sm font-semibold text-emerald-700">Możliwy zakup z dofinansowaniem NFZ</p>
                    @endif

                    <dl class="mt-6 grid grid-cols-2 gap-3">
                        <div class="rounded-2xl bg-slate-50 px-4 py-3">
                            <dt class="text-sm font-medium text-slate-500">Kaucja zwrotna</dt>
                            <dd class="mt-1 text-xl font-bold {{ $item->deposit_min === null ? 'text-emerald-700' : 'text-slate-900' }}">{{ $item->depositLabel() }}</dd>
                        </div>
                        <div class="rounded-2xl bg-blue-50 px-4 py-3">
                            <dt class="text-sm font-medium text-slate-500">Opłata miesięczna</dt>
                            <dd class="mt-1 text-xl font-bold text-[#155fa8]">{{ $item->monthlyLabel() }}</dd>
                        </div>
                    </dl>

                    <div class="mt-6 rounded-2xl border border-slate-200 p-5">
                        <h2 class="text-lg font-extrabold tracking-tight text-slate-950">Zapytaj o dostępność</h2>
                        <p class="mt-1 text-sm leading-6 text-slate-600">
                            Potwierdzimy dostępność, warunki wynajmu i termin dostawy. Sprzęt przywozimy, wnosimy i przygotowujemy do użycia.
                        </p>

                        <div class="mt-4 flex flex-col gap-2.5 sm:flex-row sm:flex-wrap">
                            @foreach ($phones as $phone)
                                <a href="{{ $telHref($phone) }}" class="inline-flex items-center justify-center gap-2 rounded-xl {{ $loop->first ? 'bg-[#155fa8] text-white hover:bg-[#0b3b70]' : 'border border-slate-200 text-slate-800 hover:border-blue-200 hover:text-[#155fa8]' }} px-5 py-3 text-base font-bold transition">
                                    {{ $phone }}
                                </a>
                            @endforeach
                            <a href="{{ route('rental.index', ['sprzet' => $item->slug]) }}#zapytanie" rel="nofollow" class="inline-flex items-center justify-center rounded-xl border border-slate-200 px-5 py-3 text-base font-bold text-slate-800 transition hover:border-blue-200 hover:text-[#155fa8]">
                                Zapytaj e-mailem
                            </a>
                        </div>

                        @if ($address)
                            <p class="mt-4 text-sm font-semibold text-slate-700">{{ $address }}</p>
                        @endif
                    </div>
                </div>
            </div>

            @if (filled($item->description))
                <div class="prose prose-slate mt-12 max-w-4xl prose-h2:mt-8 prose-h2:text-2xl prose-a:font-medium prose-a:text-[#155fa8]">
                    {!! $item->description !!}
                </div>
            @endif

            @if ($related->isNotEmpty())
                <section class="mt-14">
                    <h2 class="text-2xl font-extrabold tracking-tight text-slate-950">{{ $item->groupHeading() }} – zobacz też</h2>

                    <div class="mt-6 grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4">
                        @foreach ($related as $relatedItem)
                            <a href="{{ $relatedItem->url() }}" class="flex flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm transition hover:border-blue-200">
                                <span class="flex aspect-[4/3] items-center justify-center bg-white p-4">
                                    @if ($relatedItem->imageUrl())
                                        <img src="{{ $relatedItem->imageUrl() }}" alt="{{ $relatedItem->name }}" class="max-h-full max-w-full object-contain" loading="lazy" decoding="async">
                                    @endif
                                </span>
                                <span class="border-t border-slate-100 p-4">
                                    <span class="block font-bold leading-snug text-slate-900">{{ $relatedItem->name }}</span>
                                    <span class="mt-1 block text-sm font-semibold text-[#155fa8]">{{ $relatedItem->monthlyLabel() }} / miesiąc</span>
                                </span>
                            </a>
                        @endforeach
                    </div>
                </section>
            @endif

            <p class="mt-12">
                <a href="{{ route('rental.index') }}" class="font-semibold text-[#155fa8] transition hover:text-[#0b3b70]">← Cała oferta wypożyczalni</a>
            </p>
        </div>
    </section>
@endsection
