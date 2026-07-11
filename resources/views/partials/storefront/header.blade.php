@php
    $seller = config('legal.seller', []);
    $supportPhone = trim((string) ($seller['phone'] ?? ''));
    $supportPhoneHref = filled($supportPhone)
        ? 'tel:'.preg_replace('/[^0-9+]/', '', $supportPhone)
        : null;
    $navigationCategories = $storefrontNavigationCategories ?? collect();
@endphp

<header
    class="sticky top-0 z-40 border-b border-slate-200/80 bg-white/95 shadow-[0_1px_0_rgba(15,23,42,0.03)] backdrop-blur"
    x-data="{ mobileMenuOpen: false }"
>
    <div class="bg-[#0b3b70] text-white">
        <div class="mx-auto flex min-h-9 max-w-[1480px] items-center justify-center gap-6 px-4 text-[11px] font-semibold sm:px-6 lg:justify-between lg:px-8">
            <div class="flex items-center gap-5">
                <span class="inline-flex items-center gap-2">
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                    </svg>
                    Sprawdzone produkty medyczne
                </span>

                <span class="hidden items-center gap-2 sm:inline-flex">
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 6h13v10H3zM16 9h3l2 3v4h-5zM7 19a2 2 0 1 0 0-4 2 2 0 0 0 0 4Zm10 0a2 2 0 1 0 0-4 2 2 0 0 0 0 4Z" />
                    </svg>
                    Szybka dostawa na terenie Polski
                </span>

                <span class="hidden items-center gap-2 md:inline-flex">
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z" />
                    </svg>
                    Bezpieczne płatności
                </span>
            </div>

            <div class="hidden items-center gap-5 lg:flex">
                <a href="{{ route('legal.contact') }}" class="transition hover:text-blue-100">
                    Pomoc w doborze produktu
                </a>

                @if ($supportPhoneHref)
                    <a href="{{ $supportPhoneHref }}" class="inline-flex items-center gap-2 transition hover:text-blue-100">
                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6A19.79 19.79 0 0 1 2.12 4.18 2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.12.9.33 1.78.62 2.63a2 2 0 0 1-.45 2.11L8 9.73a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.85.29 1.73.5 2.63.62A2 2 0 0 1 22 16.92Z" />
                        </svg>
                        {{ $supportPhone }}
                    </a>
                @endif
            </div>
        </div>
    </div>

    <div class="mx-auto max-w-[1480px] px-4 sm:px-6 lg:px-8">
        <div class="flex min-h-[82px] items-center gap-4 lg:gap-7">
            <button
                type="button"
                class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-xl border border-slate-200 text-slate-700 transition hover:border-blue-200 hover:bg-blue-50 hover:text-[#155fa8] lg:hidden"
                aria-label="Otwórz menu"
                :aria-expanded="mobileMenuOpen"
                @click="mobileMenuOpen = !mobileMenuOpen"
            >
                <svg x-show="!mobileMenuOpen" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" d="M4 7h16M4 12h16M4 17h16" />
                </svg>
                <svg x-cloak x-show="mobileMenuOpen" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" d="m6 6 12 12M18 6 6 18" />
                </svg>
            </button>

            <a
                href="{{ route('home') }}"
                class="shrink-0"
                aria-label="ortezka.pl - strona główna"
            >
                <img
                    src="/images/ortezka-mark-v4.png"
                    alt="ortezka.pl"
                    class="h-11 w-11 object-contain sm:hidden"
                >
                <img
                    src="/images/ortezka-logo-v4.png"
                    alt="ortezka.pl"
                    class="hidden h-12 w-auto max-w-[225px] object-contain sm:block xl:h-[54px] xl:max-w-[250px]"
                >
            </a>

            <form
                method="GET"
                action="{{ route('home') }}"
                class="order-last hidden min-w-0 flex-1 lg:order-none lg:block"
                role="search"
            >
                <label for="storefront-search-desktop" class="sr-only">Szukaj produktów</label>
                <div class="group relative">
                    <svg class="pointer-events-none absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2 text-slate-400 transition group-focus-within:text-[#155fa8]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <circle cx="11" cy="11" r="7" />
                        <path stroke-linecap="round" d="m20 20-3.5-3.5" />
                    </svg>
                    <input
                        id="storefront-search-desktop"
                        type="search"
                        name="q"
                        value="{{ request('q') }}"
                        placeholder="Szukaj ortezy, stabilizatora, odzieży medycznej…"
                        class="h-12 w-full rounded-2xl border border-slate-200 bg-slate-50 pl-12 pr-28 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 hover:border-slate-300 focus:border-[#1674c4] focus:bg-white focus:ring-4 focus:ring-blue-100"
                    >
                    <button
                        type="submit"
                        class="absolute right-1.5 top-1.5 inline-flex h-9 items-center justify-center rounded-xl bg-[#155fa8] px-5 text-sm font-semibold text-white transition hover:bg-[#0b3b70] focus:outline-none focus:ring-4 focus:ring-blue-100"
                    >
                        Szukaj
                    </button>
                </div>
            </form>

            <nav class="ml-auto flex shrink-0 items-center gap-1 sm:gap-2" aria-label="Narzędzia sklepu">
                <a
                    href="{{ route('guest.orders.track.show') }}"
                    class="hidden min-w-[72px] flex-col items-center justify-center rounded-xl px-2 py-2 text-xs font-medium text-slate-600 transition hover:bg-blue-50 hover:text-[#155fa8] xl:flex"
                >
                    <svg class="mb-1 h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 6h13v10H3zM16 9h3l2 3v4h-5zM7 19a2 2 0 1 0 0-4 2 2 0 0 0 0 4Zm10 0a2 2 0 1 0 0-4 2 2 0 0 0 0 4Z" />
                    </svg>
                    Śledzenie
                </a>

                @auth
                    <a
                        href="{{ route('account.details.show') }}"
                        class="hidden min-w-[68px] flex-col items-center justify-center rounded-xl px-2 py-2 text-xs font-medium text-slate-600 transition hover:bg-blue-50 hover:text-[#155fa8] sm:flex"
                    >
                        <svg class="mb-1 h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                            <circle cx="12" cy="8" r="4" />
                            <path stroke-linecap="round" d="M4 21a8 8 0 0 1 16 0" />
                        </svg>
                        Konto
                    </a>
                @else
                    <a
                        href="{{ route('login') }}"
                        class="hidden min-w-[68px] flex-col items-center justify-center rounded-xl px-2 py-2 text-xs font-medium text-slate-600 transition hover:bg-blue-50 hover:text-[#155fa8] sm:flex"
                    >
                        <svg class="mb-1 h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                            <circle cx="12" cy="8" r="4" />
                            <path stroke-linecap="round" d="M4 21a8 8 0 0 1 16 0" />
                        </svg>
                        Zaloguj się
                    </a>
                @endauth

                <div class="rounded-xl px-2 py-2 transition hover:bg-blue-50 sm:px-3">
                    <div
                        id="cart-widget"
                        data-summary-url="{{ route('cart.summary', absolute: false) }}"
                    ></div>
                </div>
            </nav>
        </div>

        <form
            method="GET"
            action="{{ route('home') }}"
            class="pb-4 lg:hidden"
            role="search"
        >
            <label for="storefront-search-mobile" class="sr-only">Szukaj produktów</label>
            <div class="relative">
                <svg class="pointer-events-none absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <circle cx="11" cy="11" r="7" />
                    <path stroke-linecap="round" d="m20 20-3.5-3.5" />
                </svg>
                <input
                    id="storefront-search-mobile"
                    type="search"
                    name="q"
                    value="{{ request('q') }}"
                    placeholder="Czego szukasz?"
                    class="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 pl-12 pr-20 text-sm text-slate-900 outline-none transition focus:border-[#1674c4] focus:bg-white focus:ring-4 focus:ring-blue-100"
                >
                <button type="submit" class="absolute right-1.5 top-1.5 h-8 rounded-lg bg-[#155fa8] px-3 text-xs font-semibold text-white">
                    Szukaj
                </button>
            </div>
        </form>
    </div>

    <div class="hidden border-t border-slate-100 lg:block">
        <div class="mx-auto flex max-w-[1480px] items-center gap-1 overflow-x-auto px-4 py-2 sm:px-6 lg:px-8">
            <a
                href="{{ route('home') }}#categories"
                class="inline-flex shrink-0 items-center gap-2 rounded-xl bg-[#155fa8] px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-[#0b3b70]"
            >
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" d="M4 6h16M4 12h16M4 18h16" />
                </svg>
                Kategorie
            </a>

            @foreach ($navigationCategories->take(7) as $navigationCategory)
                <a
                    href="{{ route('categories.show', $navigationCategory->slug) }}"
                    class="shrink-0 rounded-xl px-3.5 py-2.5 text-sm font-medium text-slate-700 transition hover:bg-blue-50 hover:text-[#155fa8]"
                >
                    {{ $navigationCategory->name }}
                </a>
            @endforeach

            <a href="{{ route('legal.delivery-payments') }}" class="ml-auto shrink-0 rounded-xl px-3.5 py-2.5 text-sm font-medium text-slate-700 transition hover:bg-blue-50 hover:text-[#155fa8]">
                Dostawa i płatności
            </a>

            @auth
                @if(auth()->user()->is_admin)
                    <a
                        href="{{ route('admin.orders.index') }}"
                        class="shrink-0 rounded-xl bg-orange-50 px-3.5 py-2.5 text-sm font-semibold text-[#d95513] transition hover:bg-orange-100"
                    >
                        Administracja
                    </a>
                    <span class="sr-only">
                        <a href="{{ route('admin.products.index') }}">Produkty</a>
                        <a href="{{ route('admin.categories.index') }}">Kategorie</a>
                        <a href="{{ route('admin.withdrawals.index') }}">Odstąpienia</a>
                        <a href="{{ route('admin.shop.readiness') }}">Gotowość</a>
                    </span>
                @endif
            @endauth
        </div>
    </div>

    <div
        x-cloak
        x-show="mobileMenuOpen"
        x-transition.opacity.duration.150ms
        class="border-t border-slate-200 bg-white lg:hidden"
        @click.outside="mobileMenuOpen = false"
    >
        <nav class="mx-auto max-w-[1480px] px-4 py-5 sm:px-6" aria-label="Menu mobilne">
            <p class="mb-3 text-xs font-bold uppercase tracking-[0.16em] text-slate-400">Kategorie</p>

            <div class="grid gap-1 sm:grid-cols-2">
                @forelse ($navigationCategories as $navigationCategory)
                    <a
                        href="{{ route('categories.show', $navigationCategory->slug) }}"
                        class="flex items-center justify-between rounded-xl px-3 py-3 text-sm font-semibold text-slate-700 transition hover:bg-blue-50 hover:text-[#155fa8]"
                    >
                        {{ $navigationCategory->name }}
                        <span aria-hidden="true">→</span>
                    </a>
                @empty
                    <a href="{{ route('home') }}#categories" class="rounded-xl bg-slate-50 px-3 py-3 text-sm font-medium text-slate-600">
                        Zobacz ofertę sklepu
                    </a>
                @endforelse
            </div>

            <div class="mt-5 grid gap-1 border-t border-slate-100 pt-5 sm:grid-cols-2">
                <a href="{{ route('guest.orders.track.show') }}" class="rounded-xl px-3 py-3 text-sm font-medium text-slate-700 hover:bg-slate-50">Śledzenie zamówienia</a>
                <a href="{{ route('withdrawals.start') }}" class="rounded-xl px-3 py-3 text-sm font-medium text-slate-700 hover:bg-slate-50">Odstąp od umowy</a>
                <a href="{{ route('legal.contact') }}" class="rounded-xl px-3 py-3 text-sm font-medium text-slate-700 hover:bg-slate-50">Kontakt</a>
                <a href="{{ route('legal.delivery-payments') }}" class="rounded-xl px-3 py-3 text-sm font-medium text-slate-700 hover:bg-slate-50">Dostawa i płatności</a>

                @auth
                    <a href="{{ route('account.orders.index') }}" class="rounded-xl px-3 py-3 text-sm font-medium text-slate-700 hover:bg-slate-50">Moje zamówienia</a>
                    <a href="{{ route('account.details.show') }}" class="rounded-xl px-3 py-3 text-sm font-medium text-slate-700 hover:bg-slate-50">Moje konto</a>

                    @if(auth()->user()->is_admin)
                        <a href="{{ route('admin.orders.index') }}" class="rounded-xl bg-orange-50 px-3 py-3 text-sm font-semibold text-[#d95513]">Administracja</a>
                    @endif

                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="w-full rounded-xl px-3 py-3 text-left text-sm font-medium text-slate-700 hover:bg-slate-50" data-test="logout-button">
                            Wyloguj się
                        </button>
                    </form>
                @else
                    <a href="{{ route('login') }}" class="rounded-xl px-3 py-3 text-sm font-medium text-slate-700 hover:bg-slate-50">Logowanie</a>
                    <a href="{{ route('register') }}" class="rounded-xl bg-[#155fa8] px-3 py-3 text-sm font-semibold text-white">Załóż konto</a>
                @endauth
            </div>
        </nav>
    </div>
</header>
