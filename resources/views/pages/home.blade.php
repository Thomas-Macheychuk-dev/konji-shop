@extends('layouts.storefront')

@section('content')
    <x-storefront.category-page-shell>
    @if ($searchQuery !== '')
        <section class="border-b border-slate-200 bg-white">
            <div class="mx-auto max-w-[1480px] px-4 py-10 sm:px-6 lg:px-8 lg:py-14">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-[0.18em] text-[#1674c4]">Wyniki wyszukiwania</p>
                        <h1 class="mt-2 text-3xl font-extrabold tracking-tight text-slate-950 sm:text-4xl">
                            „{{ $searchQuery }}”
                        </h1>
                        <p class="mt-3 text-sm text-slate-500">
                            Znaleziono {{ $searchResults->count() }} {{ $searchResults->count() === 1 ? 'produkt' : 'produktów' }}.
                        </p>
                    </div>

                    <a href="{{ route('home') }}" class="inline-flex items-center gap-2 text-sm font-semibold text-[#155fa8] transition hover:text-[#0b3b70]">
                        Wyczyść wyszukiwanie
                        <span aria-hidden="true">×</span>
                    </a>
                </div>

                @if ($searchResults->isNotEmpty())
                    <div class="mt-8 grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                        @foreach ($searchResults as $product)
                            <x-storefront.product-card :product="$product" />
                        @endforeach
                    </div>
                @else
                    <div class="mt-8 rounded-[28px] border border-blue-100 bg-blue-50/70 px-6 py-10 text-center sm:px-10">
                        <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-white text-[#155fa8] shadow-sm">
                            <svg class="h-7 w-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <circle cx="11" cy="11" r="7" />
                                <path stroke-linecap="round" d="m20 20-3.5-3.5" />
                            </svg>
                        </div>
                        <h2 class="mt-5 text-xl font-bold text-slate-900">Nie znaleźliśmy takiego produktu</h2>
                        <p class="mx-auto mt-2 max-w-xl text-sm leading-6 text-slate-600">
                            Spróbuj użyć krótszej nazwy, wyszukaj część ciała albo skontaktuj się z nami — pomożemy znaleźć właściwe rozwiązanie.
                        </p>
                        <div class="mt-6 flex flex-wrap justify-center gap-3">
                            <a href="{{ route('home') }}#categories" class="rounded-xl bg-[#155fa8] px-5 py-3 text-sm font-semibold text-white transition hover:bg-[#0b3b70]">
                                Przeglądaj kategorie
                            </a>
                            <a href="{{ route('legal.contact') }}" class="rounded-xl border border-slate-200 bg-white px-5 py-3 text-sm font-semibold text-slate-700 transition hover:border-blue-200 hover:text-[#155fa8]">
                                Poproś o pomoc
                            </a>
                        </div>
                    </div>
                @endif
            </div>
        </section>
    @else
        <section class="overflow-hidden bg-white">
            <div class="mx-auto max-w-[1480px] px-4 py-8 sm:px-6 lg:px-8 lg:py-12">
                <div class="relative overflow-hidden rounded-[32px] bg-gradient-to-br from-[#0b3b70] via-[#155fa8] to-[#1674c4] px-6 py-10 shadow-[0_24px_80px_rgba(11,59,112,0.22)] sm:px-10 sm:py-14 lg:grid lg:min-h-[520px] lg:grid-cols-[1.08fr_0.92fr] lg:items-center lg:gap-10 lg:px-14">
                    <div class="pointer-events-none absolute -left-24 -top-24 h-72 w-72 rounded-full border-[54px] border-white/5"></div>
                    <div class="pointer-events-none absolute -bottom-32 left-1/3 h-80 w-80 rounded-full bg-orange-400/10 blur-3xl"></div>

                    <div class="relative z-10 max-w-3xl">
                        <span class="inline-flex items-center gap-2 rounded-full border border-white/20 bg-white/10 px-3.5 py-2 text-xs font-bold uppercase tracking-[0.14em] text-blue-50 backdrop-blur">
                            <span class="h-2 w-2 rounded-full bg-[#ff7a2f]"></span>
                            Profesjonalne wsparcie w ruchu
                        </span>

                        <h1 class="mt-6 text-4xl font-extrabold leading-[1.05] tracking-[-0.04em] text-white sm:text-5xl lg:text-6xl">
                            Komfort i stabilizacja na każdy dzień
                        </h1>

                        <p class="mt-6 max-w-2xl text-base leading-7 text-blue-50/90 sm:text-lg sm:leading-8">
                            Ortezy, stabilizatory, odzież medyczna i produkty rehabilitacyjne dobrane z myślą o bezpieczeństwie, mobilności i wygodzie użytkowania.
                        </p>

                        <div class="mt-8 flex flex-wrap gap-3">
                            <a
                                href="#categories"
                                class="inline-flex items-center gap-2 rounded-xl bg-white px-5 py-3.5 text-sm font-bold text-[#0b3b70] shadow-lg shadow-blue-950/10 transition hover:-translate-y-0.5 hover:bg-blue-50"
                            >
                                Zobacz ofertę
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m9 18 6-6-6-6" />
                                </svg>
                            </a>

                            <a
                                href="{{ route('legal.contact') }}"
                                class="inline-flex items-center gap-2 rounded-xl border border-white/25 bg-white/10 px-5 py-3.5 text-sm font-bold text-white backdrop-blur transition hover:bg-white/20"
                            >
                                Pomoc w doborze
                            </a>
                        </div>

                        <div class="mt-8 flex flex-wrap gap-x-6 gap-y-3 text-sm font-medium text-blue-50/90">
                            <span class="inline-flex items-center gap-2">
                                <svg class="h-4 w-4 text-[#ff9b61]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                                </svg>
                                Produkty renomowanych marek
                            </span>
                            <span class="inline-flex items-center gap-2">
                                <svg class="h-4 w-4 text-[#ff9b61]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                                </svg>
                                Wysyłka na terenie Polski
                            </span>
                        </div>
                    </div>

                    <div class="relative z-10 mt-10 lg:mt-0">
                        <div class="relative mx-auto max-w-[520px]">
                            <div class="absolute inset-8 rounded-full bg-white/10 blur-3xl"></div>

                            <div class="relative rounded-[30px] border border-white/20 bg-white/95 p-5 shadow-[0_28px_80px_rgba(3,24,48,0.28)] backdrop-blur sm:p-7">
                                <div class="flex items-center justify-between gap-4">
                                    <div>
                                        <p class="text-xs font-bold uppercase tracking-[0.15em] text-[#1674c4]">Dobierz właściwe wsparcie</p>
                                        <h2 class="mt-2 text-2xl font-extrabold tracking-tight text-slate-950">Od czego zaczynamy?</h2>
                                    </div>
                                    <div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-blue-50 to-orange-50">
                                        <img src="/images/ortezka-mark-v4.png" alt="" class="h-11 w-11 object-contain" aria-hidden="true">
                                    </div>
                                </div>

                                <div class="mt-6 grid grid-cols-2 gap-3">
                                    @foreach ([
                                        ['Kolano', 'kolano', 'M12 2v7m0 4v9M8 7c2 2 6 2 8 0M8 17c2-2 6-2 8 0'],
                                        ['Kręgosłup', 'kręgosłup', 'M12 3v18M8 6c2 1 6 1 8 0M8 10c2 1 6 1 8 0M8 14c2 1 6 1 8 0M8 18c2 1 6 1 8 0'],
                                        ['Nadgarstek', 'nadgarstek', 'M8 3v9l-2 5m10-14v9l2 5M8 12h8M7 17h10'],
                                        ['Kostka i stopa', 'kostka', 'M9 3v10c0 4-2 5-4 6h14c0-3-3-4-6-4V3'],
                                    ] as [$label, $query, $iconPath])
                                        <a
                                            href="{{ route('home', ['q' => $query]) }}"
                                            class="group rounded-2xl border border-slate-200 bg-white p-4 transition hover:-translate-y-0.5 hover:border-blue-200 hover:bg-blue-50/60 hover:shadow-md"
                                        >
                                            <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-blue-50 text-[#155fa8] transition group-hover:bg-[#155fa8] group-hover:text-white">
                                                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="{{ $iconPath }}" />
                                                </svg>
                                            </span>
                                            <span class="mt-3 block text-sm font-bold text-slate-800">{{ $label }}</span>
                                            <span class="mt-1 block text-xs text-slate-400">Zobacz produkty →</span>
                                        </a>
                                    @endforeach
                                </div>

                                <div class="mt-5 flex items-center gap-3 rounded-2xl bg-orange-50 px-4 py-3.5 text-sm text-slate-700 ring-1 ring-orange-100">
                                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-[#f26722] text-white">
                                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 17h.01M9.1 9a3 3 0 1 1 5.1 2.1c-.9.8-2.2 1.4-2.2 2.9" />
                                            <circle cx="12" cy="12" r="10" />
                                        </svg>
                                    </span>
                                    <span><strong class="font-bold text-slate-900">Masz wątpliwości?</strong> Pomożemy dobrać typ i rozmiar produktu.</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    @endif

    @if ($searchQuery === '')
        <section class="border-y border-slate-200 bg-white">
            <div class="mx-auto grid max-w-[1480px] grid-cols-2 divide-x divide-y divide-slate-200 px-4 sm:px-6 md:grid-cols-4 md:divide-y-0 lg:px-8">
                @foreach ([
                    ['Sprawdzone produkty', 'Oferta dla codziennego komfortu', 'shield'],
                    ['Szybka realizacja', 'Przejrzyste informacje o dostawie', 'truck'],
                    ['Bezpieczne zakupy', 'Chronione płatności online', 'lock'],
                    ['Pomoc w doborze', 'Wsparcie przed zakupem', 'help'],
                ] as [$title, $description, $icon])
                    <div class="flex min-h-[124px] items-center gap-3 px-3 py-5 sm:px-5">
                        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-blue-50 text-[#155fa8]">
                            @if ($icon === 'truck')
                                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 6h13v10H3zM16 9h3l2 3v4h-5zM7 19a2 2 0 1 0 0-4 2 2 0 0 0 0 4Zm10 0a2 2 0 1 0 0-4 2 2 0 0 0 0 4Z" /></svg>
                            @elseif ($icon === 'lock')
                                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="5" y="10" width="14" height="11" rx="2" /><path stroke-linecap="round" d="M8 10V7a4 4 0 0 1 8 0v3" /></svg>
                            @elseif ($icon === 'help')
                                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="10" /><path stroke-linecap="round" d="M9.1 9a3 3 0 1 1 5.1 2.1c-.9.8-2.2 1.4-2.2 2.9M12 17h.01" /></svg>
                            @else
                                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z" /><path stroke-linecap="round" stroke-linejoin="round" d="m9 12 2 2 4-4" /></svg>
                            @endif
                        </span>
                        <span class="min-w-0">
                            <strong class="block text-sm font-bold text-slate-900">{{ $title }}</strong>
                            <span class="mt-1 block text-xs leading-5 text-slate-500">{{ $description }}</span>
                        </span>
                    </div>
                @endforeach
            </div>
        </section>

        <section id="categories" class="scroll-mt-48">
            <div class="mx-auto max-w-[1480px] px-4 py-14 sm:px-6 lg:px-8 lg:py-20">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-[0.18em] text-[#1674c4]">Kategorie</p>
                        <h2 class="mt-2 text-3xl font-extrabold tracking-tight text-slate-950 sm:text-4xl">Znajdź produkt dopasowany do potrzeb</h2>
                        <p class="mt-3 max-w-2xl text-sm leading-6 text-slate-600 sm:text-base">
                            Przeglądaj ofertę według rodzaju produktu i wybierz rozwiązanie odpowiednie do codziennego użytkowania.
                        </p>
                    </div>
                </div>

                @if ($categories->isNotEmpty())
                    <div class="mt-9 grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                        @foreach ($categories as $category)
                            <a
                                href="{{ route('categories.show', $category->slug) }}"
                                class="group relative min-h-[230px] overflow-hidden rounded-[24px] border border-slate-200 bg-white p-6 shadow-[0_8px_30px_rgba(15,23,42,0.04)] transition duration-300 hover:-translate-y-1 hover:border-blue-200 hover:shadow-[0_20px_50px_rgba(21,95,168,0.11)]"
                            >
                                <div class="absolute -right-8 -top-8 h-32 w-32 rounded-full bg-blue-50 transition duration-300 group-hover:scale-125 group-hover:bg-blue-100"></div>
                                <div class="relative">
                                    <span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-[#155fa8] text-white shadow-lg shadow-blue-200/70">
                                        @switch($loop->index % 4)
                                            @case(0)
                                                <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 2v7m0 4v9M8 7c2 2 6 2 8 0M8 17c2-2 6-2 8 0" /></svg>
                                                @break
                                            @case(1)
                                                <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 4h14v16H5zM8 8h8M8 12h8M8 16h5" /></svg>
                                                @break
                                            @case(2)
                                                <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16c4-1 6-4 7-8 1 5 4 8 9 9M5 20h14" /></svg>
                                                @break
                                            @default
                                                <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v18M3 12h18M5.6 5.6l12.8 12.8M18.4 5.6 5.6 18.4" /></svg>
                                        @endswitch
                                    </span>

                                    <h3 class="mt-7 text-xl font-extrabold tracking-tight text-slate-900 transition group-hover:text-[#155fa8]">
                                        {{ $category->name }}
                                    </h3>

                                    <p class="mt-3 line-clamp-2 text-sm leading-6 text-slate-500">
                                        {{ filled($category->description) ? strip_tags($category->description) : 'Zobacz dostępne produkty i warianty w tej kategorii.' }}
                                    </p>

                                    <div class="mt-6 flex items-center justify-between gap-3">
                                        <span class="text-xs font-semibold text-slate-400">
                                            {{ $category->active_products_count }} {{ $category->active_products_count === 1 ? 'produkt' : 'produktów' }}
                                        </span>
                                        <span class="inline-flex items-center gap-1 text-sm font-bold text-[#155fa8]">
                                            Zobacz
                                            <span class="transition group-hover:translate-x-1" aria-hidden="true">→</span>
                                        </span>
                                    </div>
                                </div>
                            </a>
                        @endforeach
                    </div>
                @else
                    <div class="mt-9 grid gap-5 rounded-[28px] border border-blue-100 bg-gradient-to-r from-blue-50 to-white p-6 sm:grid-cols-[auto_1fr] sm:items-center sm:p-9">
                        <span class="flex h-16 w-16 items-center justify-center rounded-2xl bg-white text-[#155fa8] shadow-sm ring-1 ring-blue-100">
                            <svg class="h-8 w-8" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 7h16M4 12h16M4 17h10" />
                            </svg>
                        </span>
                        <div>
                            <h3 class="text-xl font-bold text-slate-900">Oferta jest właśnie porządkowana</h3>
                            <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">
                                Kategorie pojawią się tutaj po ich opublikowaniu. W międzyczasie możesz skontaktować się z obsługą i zapytać o konkretny produkt.
                            </p>
                            <a href="{{ route('legal.contact') }}" class="mt-4 inline-flex items-center gap-2 text-sm font-bold text-[#155fa8] hover:text-[#0b3b70]">
                                Skontaktuj się z nami <span aria-hidden="true">→</span>
                            </a>
                        </div>
                    </div>
                @endif
            </div>
        </section>

        @if ($featuredProducts->isNotEmpty())
            <section class="border-y border-slate-200 bg-white">
                <div class="mx-auto max-w-[1480px] px-4 py-14 sm:px-6 lg:px-8 lg:py-20">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <p class="text-xs font-bold uppercase tracking-[0.18em] text-[#f26722]">Polecane produkty</p>
                            <h2 class="mt-2 text-3xl font-extrabold tracking-tight text-slate-950 sm:text-4xl">Najczęściej wybierane rozwiązania</h2>
                        </div>
                        <a href="#categories" class="inline-flex items-center gap-2 text-sm font-bold text-[#155fa8] hover:text-[#0b3b70]">
                            Wszystkie kategorie <span aria-hidden="true">→</span>
                        </a>
                    </div>

                    <div class="mt-9 grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                        @foreach ($featuredProducts as $product)
                            <x-storefront.product-card :product="$product" />
                        @endforeach
                    </div>
                </div>
            </section>
        @endif

        <section>
            <div class="mx-auto max-w-[1480px] px-4 py-14 sm:px-6 lg:px-8 lg:py-20">
                <div class="overflow-hidden rounded-[30px] bg-[#0b3b70] shadow-[0_24px_70px_rgba(11,59,112,0.16)] lg:grid lg:grid-cols-[1fr_auto] lg:items-center">
                    <div class="px-6 py-10 sm:px-10 lg:px-14 lg:py-14">
                        <p class="text-xs font-bold uppercase tracking-[0.18em] text-[#ff9b61]">Wsparcie przed zakupem</p>
                        <h2 class="mt-3 text-3xl font-extrabold tracking-tight text-white sm:text-4xl">Nie wiesz, jaki produkt wybrać?</h2>
                        <p class="mt-4 max-w-2xl text-base leading-7 text-blue-100">
                            Skontaktuj się z nami. Pomożemy znaleźć właściwy typ produktu, sprawdzić warianty i przygotować się do wyboru odpowiedniego rozmiaru.
                        </p>
                        <div class="mt-7 flex flex-wrap gap-3">
                            <a href="{{ route('legal.contact') }}" class="rounded-xl bg-[#f26722] px-5 py-3.5 text-sm font-bold text-white transition hover:bg-[#d95513]">
                                Skontaktuj się
                            </a>
                            <a href="{{ route('legal.delivery-payments') }}" class="rounded-xl border border-white/20 bg-white/10 px-5 py-3.5 text-sm font-bold text-white transition hover:bg-white/20">
                                Dostawa i płatności
                            </a>
                        </div>
                    </div>

                    <div class="relative hidden h-full min-h-[280px] w-[360px] overflow-hidden lg:block">
                        <div class="absolute -bottom-28 -right-20 h-96 w-96 rounded-full border-[70px] border-white/5"></div>
                        <div class="absolute right-16 top-1/2 flex h-40 w-40 -translate-y-1/2 items-center justify-center rounded-[42px] bg-white shadow-2xl">
                            <img src="/images/ortezka-mark-v4.png" alt="" class="h-28 w-28 object-contain" aria-hidden="true">
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="border-t border-slate-200 bg-white">
            <div class="mx-auto max-w-[1480px] px-4 py-14 sm:px-6 lg:px-8 lg:py-20">
                <div class="max-w-2xl">
                    <p class="text-xs font-bold uppercase tracking-[0.18em] text-[#1674c4]">Warto wiedzieć</p>
                    <h2 class="mt-2 text-3xl font-extrabold tracking-tight text-slate-950 sm:text-4xl">Świadomy wybór produktu</h2>
                    <p class="mt-3 text-sm leading-6 text-slate-600 sm:text-base">
                        Najważniejsze kwestie, które warto sprawdzić przed zakupem wyrobu ortopedycznego lub medycznego.
                    </p>
                </div>

                <div class="mt-9 grid gap-5 md:grid-cols-3">
                    @foreach ([
                        ['01', 'Jak prawidłowo dobrać rozmiar?', 'Zmierz wskazany obwód zgodnie z tabelą producenta i nie dobieraj rozmiaru wyłącznie na podstawie zwykle noszonej odzieży.'],
                        ['02', 'Orteza czy stabilizator?', 'Poziom usztywnienia i przeznaczenie produktu powinny odpowiadać potrzebie użytkownika oraz zaleceniom specjalisty.'],
                        ['03', 'Na co zwrócić uwagę przed zakupem?', 'Sprawdź stronę produktu, dostępne warianty, sposób zakładania oraz zasady pielęgnacji materiału.'],
                    ] as [$number, $title, $description])
                        <article class="rounded-[24px] border border-slate-200 bg-slate-50/70 p-6 transition hover:border-blue-200 hover:bg-blue-50/50">
                            <span class="text-sm font-extrabold text-[#f26722]">{{ $number }}</span>
                            <h3 class="mt-5 text-xl font-bold tracking-tight text-slate-900">{{ $title }}</h3>
                            <p class="mt-3 text-sm leading-6 text-slate-600">{{ $description }}</p>
                        </article>
                    @endforeach
                </div>
            </div>
        </section>
    @endif
    </x-storefront.category-page-shell>
@endsection
