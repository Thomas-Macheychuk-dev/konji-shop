@php
    $seller = config('legal.seller', []);
    $returns = config('legal.returns', []);

    $shopName = $seller['shop_name'] ?? 'ortezka.pl';
    $identityAddress = trim((string) ($seller['identity_address'] ?? ''));
    $identityAddressLines = filled($identityAddress)
        ? array_values(array_filter(preg_split('/\R+/', $identityAddress) ?: [], fn (string $line): bool => trim($line) !== ''))
        : [];
    $companyName = $identityAddressLines[0] ?? ($seller['company_name'] ?? $shopName);
    $street = $seller['street'] ?? '';
    $postcode = $seller['postcode'] ?? '';
    $city = $seller['city'] ?? '';
    $country = $seller['country'] ?? '';
    $email = $seller['email'] ?? '';
    $phone = $seller['phone'] ?? '';
    $taxId = $seller['tax_id'] ?? '';
    $registryNumber = $seller['business_registry_number'] ?? '';
    $returnAddress = $returns['return_address'] ?? '';
    $phoneHref = filled($phone)
        ? 'tel:'.preg_replace('/[^0-9+]/', '', (string) $phone)
        : null;
    $navigationCategories = $storefrontNavigationCategories ?? collect();
@endphp

<footer class="border-t border-slate-200 bg-[#071f3b] text-white">
    <div class="border-b border-white/10">
        <div class="mx-auto grid max-w-[1480px] gap-6 px-4 py-8 sm:grid-cols-2 sm:px-6 lg:grid-cols-4 lg:px-8">
            @foreach ([
                ['Bezpieczne zakupy', 'Chronione płatności i przejrzyste zasady', 'shield'],
                ['Dostawa w Polsce', 'Wygodne formy wysyłki zamówienia', 'truck'],
                ['14 dni na zwrot', 'Sprawdź zasady odstąpienia od umowy', 'return'],
                ['Pomoc obsługi', 'Wsparcie w wyborze produktu', 'help'],
            ] as [$title, $description, $icon])
                <div class="flex items-center gap-3">
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-white/10 text-blue-100 ring-1 ring-white/10">
                        @if ($icon === 'truck')
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 6h13v10H3zM16 9h3l2 3v4h-5zM7 19a2 2 0 1 0 0-4 2 2 0 0 0 0 4Zm10 0a2 2 0 1 0 0-4 2 2 0 0 0 0 4Z" /></svg>
                        @elseif ($icon === 'return')
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4 7h11a5 5 0 0 1 0 10H8M4 7l4-4M4 7l4 4" /></svg>
                        @elseif ($icon === 'help')
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="10" /><path stroke-linecap="round" d="M9.1 9a3 3 0 1 1 5.1 2.1c-.9.8-2.2 1.4-2.2 2.9M12 17h.01" /></svg>
                        @else
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z" /><path stroke-linecap="round" stroke-linejoin="round" d="m9 12 2 2 4-4" /></svg>
                        @endif
                    </span>
                    <span>
                        <strong class="block text-sm font-bold">{{ $title }}</strong>
                        <span class="mt-1 block text-xs leading-5 text-blue-100/65">{{ $description }}</span>
                    </span>
                </div>
            @endforeach
        </div>
    </div>

    <div class="mx-auto max-w-[1480px] px-4 py-12 sm:px-6 lg:px-8 lg:py-16">
        <div class="grid gap-10 md:grid-cols-2 lg:grid-cols-[1.35fr_0.85fr_0.85fr_1fr]">
            <div>
                <a href="{{ route('home') }}" aria-label="ortezka.pl - strona główna" class="inline-block rounded-2xl bg-white px-4 py-3">
                    <img src="/images/ortezka-logo-v4.png" alt="ortezka.pl" class="h-12 w-auto max-w-[230px] object-contain">
                </a>

                <p class="mt-6 max-w-md text-sm leading-7 text-blue-100/70">
                    Sklep z wyrobami ortopedycznymi, odzieżą medyczną i produktami wspierającymi codzienny komfort, mobilność i rehabilitację.
                </p>

                <div class="mt-6 flex flex-wrap gap-2">
                    <span class="rounded-full bg-white/10 px-3 py-1.5 text-xs font-semibold text-blue-100 ring-1 ring-white/10">Zakupy online</span>
                    <span class="rounded-full bg-white/10 px-3 py-1.5 text-xs font-semibold text-blue-100 ring-1 ring-white/10">Wysyłka w Polsce</span>
                    <span class="rounded-full bg-white/10 px-3 py-1.5 text-xs font-semibold text-blue-100 ring-1 ring-white/10">Wsparcie klienta</span>
                </div>
            </div>

            <div>
                <h3 class="text-sm font-bold uppercase tracking-[0.14em] text-white">Kategorie</h3>
                <nav class="mt-5 flex flex-col gap-3 text-sm">
                    @forelse ($navigationCategories->take(6) as $navigationCategory)
                        <a href="{{ route('categories.show', $navigationCategory->slug) }}" class="text-blue-100/70 transition hover:translate-x-0.5 hover:text-white">
                            {{ $navigationCategory->name }}
                        </a>
                    @empty
                        <a href="{{ route('home') }}#categories" class="text-blue-100/70 transition hover:text-white">Przeglądaj ofertę</a>
                    @endforelse
                </nav>
            </div>

            <div>
                <h3 class="text-sm font-bold uppercase tracking-[0.14em] text-white">Obsługa klienta</h3>
                <nav class="mt-5 flex flex-col gap-3 text-sm">
                    <a href="{{ route('legal.delivery-payments') }}" class="text-blue-100/70 transition hover:translate-x-0.5 hover:text-white">Dostawa i płatności</a>
                    <a href="{{ route('guest.orders.track.show') }}" class="text-blue-100/70 transition hover:translate-x-0.5 hover:text-white">Śledzenie zamówienia gościa</a>
                    <a href="{{ route('legal.returns') }}" class="text-blue-100/70 transition hover:translate-x-0.5 hover:text-white">Zwroty i odstąpienie od umowy</a>
                    <a href="{{ route('legal.complaints') }}" class="text-blue-100/70 transition hover:translate-x-0.5 hover:text-white">Reklamacje i gwarancja</a>
                    <a href="{{ route('legal.contact') }}" class="text-blue-100/70 transition hover:translate-x-0.5 hover:text-white">Kontakt</a>
                </nav>
            </div>

            <div>
                <h3 class="text-sm font-bold uppercase tracking-[0.14em] text-white">Kontakt i dane firmy</h3>

                <div class="mt-5 space-y-4 text-sm text-blue-100/70">
                    @if (filled($phone))
                        <a href="{{ $phoneHref }}" class="flex items-start gap-3 transition hover:text-white">
                            <svg class="mt-0.5 h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6A19.79 19.79 0 0 1 2.12 4.18 2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.12.9.33 1.78.62 2.63a2 2 0 0 1-.45 2.11L8 9.73a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.85.29 1.73.5 2.63.62A2 2 0 0 1 22 16.92Z" /></svg>
                            {{ $phone }}
                        </a>
                    @endif

                    @if (filled($email))
                        <a href="mailto:{{ $email }}" class="flex items-start gap-3 break-all transition hover:text-white">
                            <svg class="mt-0.5 h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2" /><path stroke-linecap="round" stroke-linejoin="round" d="m3 7 9 6 9-6" /></svg>
                            {{ $email }}
                        </a>
                    @endif

                    <div class="leading-6">
                        <strong class="block font-semibold text-white">{{ $companyName }}</strong>

                        @if ($identityAddressLines !== [])
                            @foreach (array_slice($identityAddressLines, 1) as $identityAddressLine)
                                <span class="block">{{ trim($identityAddressLine) }}</span>
                            @endforeach
                        @else
                            @if (filled($street))
                                <span class="block">{{ $street }}</span>
                            @endif
                            @if (filled($postcode) || filled($city))
                                <span class="block">{{ trim($postcode.' '.$city) }}</span>
                            @endif
                            @if (filled($country))
                                <span class="block">{{ $country }}</span>
                            @endif
                        @endif

                        @if (filled($taxId))
                            <span class="mt-2 block">NIP: {{ $taxId }}</span>
                        @endif
                        @if (filled($registryNumber))
                            <span class="block">Numer w rejestrze: {{ $registryNumber }}</span>
                        @endif
                    </div>

                    @if (filled($returnAddress))
                        <div class="leading-6">
                            <strong class="block font-semibold text-white">Adres do zwrotów</strong>
                            <span>{{ $returnAddress }}</span>
                        </div>
                    @endif
                </div>
            </div>
        </div>

        <div class="mt-12 flex flex-col gap-6 border-t border-white/10 pt-7 lg:flex-row lg:items-center lg:justify-between">
            <p class="text-xs leading-5 text-blue-100/50">
                © {{ now()->year }} {{ $shopName }}. Wszelkie prawa zastrzeżone.
            </p>

            <nav class="flex flex-wrap gap-x-5 gap-y-2 text-xs text-blue-100/60">
                <a href="{{ route('legal.terms') }}" class="transition hover:text-white">Regulamin</a>
                <a href="{{ route('legal.privacy') }}" class="transition hover:text-white">Polityka prywatności</a>
                <a href="{{ route('legal.returns') }}" class="transition hover:text-white">Zwroty i odstąpienie od umowy</a>
                <a href="{{ route('legal.complaints') }}" class="transition hover:text-white">Reklamacje i gwarancja</a>
                <a href="{{ route('legal.delivery-payments') }}" class="transition hover:text-white">Dostawa i płatności</a>
                <a href="{{ route('legal.cookie-policy') }}" class="transition hover:text-white">Polityka plików cookie</a>
            </nav>
        </div>
    </div>
</footer>
