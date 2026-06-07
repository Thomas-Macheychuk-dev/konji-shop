<footer class="border-t border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
    @php
        $seller = config('legal.seller', []);
        $returns = config('legal.returns', []);

        $shopName = $seller['shop_name'] ?? 'Konji Shop';
        $companyName = $seller['company_name'] ?? $shopName;
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
    @endphp

    <div class="mx-auto max-w-[1600px] px-4 py-12 sm:px-6 lg:px-8 xl:px-10">
        <div class="grid grid-cols-1 gap-10 md:grid-cols-2 xl:grid-cols-4">
            <div>
                <h3 class="text-lg font-semibold text-zinc-900 dark:text-white">
                    {{ $companyName }}
                </h3>

                <div class="mt-4 space-y-1 text-sm text-zinc-600 dark:text-zinc-300">
                    @if (filled($street))
                        <p>{{ $street }}</p>
                    @endif

                    @if (filled($postcode) || filled($city))
                        <p>{{ trim($postcode.' '.$city) }}</p>
                    @endif

                    @if (filled($country))
                        <p>{{ $country }}</p>
                    @endif
                </div>

                @if (filled($taxId) || filled($registryNumber))
                    <div class="mt-6 space-y-1 text-sm text-zinc-600 dark:text-zinc-300">
                        @if (filled($taxId))
                            <p>NIP: {{ $taxId }}</p>
                        @endif

                        @if (filled($registryNumber))
                            <p>Numer w rejestrze: {{ $registryNumber }}</p>
                        @endif
                    </div>
                @endif

                @if (filled($returnAddress))
                    <div class="mt-6">
                        <h4 class="text-sm font-semibold uppercase tracking-wide text-zinc-900 dark:text-white">
                            Adres do zwrotów
                        </h4>

                        <div class="mt-2 space-y-1 text-sm text-zinc-600 dark:text-zinc-300">
                            <p>{{ $companyName }}</p>
                            <p>{{ $returnAddress }}</p>
                        </div>
                    </div>
                @endif
            </div>

            <div>
                <h3 class="text-lg font-semibold text-zinc-900 dark:text-white">
                    Zakupy
                </h3>

                <nav class="mt-4 flex flex-col gap-3 text-sm">
                    <a href="{{ route('legal.delivery-payments') }}" class="text-zinc-600 transition hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-white">
                        Dostawa i płatności
                    </a>

                    <a href="{{ route('guest.orders.track.show') }}" class="text-zinc-600 transition hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-white">
                        Śledzenie zamówienia gościa
                    </a>

                    <a href="{{ route('legal.returns') }}" class="text-zinc-600 transition hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-white">
                        Zwroty i odstąpienie od umowy
                    </a>

                    <a href="{{ route('legal.complaints') }}" class="text-zinc-600 transition hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-white">
                        Reklamacje i gwarancja
                    </a>

                    <a href="{{ route('legal.contact') }}" class="text-zinc-600 transition hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-white">
                        Kontakt
                    </a>
                </nav>
            </div>

            <div>
                <h3 class="text-lg font-semibold text-zinc-900 dark:text-white">
                    Informacje prawne
                </h3>

                <nav class="mt-4 flex flex-col gap-3 text-sm">
                    <a href="{{ route('legal.terms') }}" class="text-zinc-600 transition hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-white">
                        Regulamin
                    </a>

                    <a href="{{ route('legal.privacy') }}" class="text-zinc-600 transition hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-white">
                        Polityka prywatności
                    </a>

                    <a href="{{ route('legal.returns') }}" class="text-zinc-600 transition hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-white">
                        Zwroty i odstąpienie od umowy
                    </a>

                    <a href="{{ route('legal.complaints') }}" class="text-zinc-600 transition hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-white">
                        Reklamacje i gwarancja
                    </a>

                    <a href="{{ route('legal.delivery-payments') }}" class="text-zinc-600 transition hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-white">
                        Dostawa i płatności
                    </a>

                    <a href="{{ route('legal.cookie-policy') }}" class="text-zinc-600 transition hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-white">
                        Polityka plików cookie
                    </a>
                </nav>
            </div>

            <div>
                <h3 class="text-lg font-semibold text-zinc-900 dark:text-white">
                    Kontakt
                </h3>

                <div class="mt-4 space-y-3 text-sm text-zinc-600 dark:text-zinc-300">
                    @if (filled($phone))
                        <p>
                            <a href="{{ $phoneHref }}" class="transition hover:text-zinc-900 dark:hover:text-white">
                                {{ $phone }}
                            </a>
                        </p>
                    @endif

                    @if (filled($email))
                        <p>
                            <a href="mailto:{{ $email }}" class="transition hover:text-zinc-900 dark:hover:text-white">
                                {{ $email }}
                            </a>
                        </p>
                    @endif

                    <p>
                        <a href="{{ route('legal.contact') }}" class="font-medium text-zinc-700 underline decoration-zinc-300 underline-offset-4 transition hover:text-zinc-900 dark:text-zinc-200 dark:hover:text-white">
                            Pełne dane sprzedawcy
                        </a>
                    </p>
                </div>

                <div class="mt-8">
                    <h4 class="text-sm font-semibold uppercase tracking-wide text-zinc-900 dark:text-white">
                        Obserwuj nas
                    </h4>

                    <div class="mt-3 flex items-center gap-4 text-sm">
                        <a href="#" class="text-zinc-600 transition hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-white">
                            Facebook
                        </a>

                        <a href="#" class="text-zinc-600 transition hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-white">
                            Instagram
                        </a>

                        <a href="#" class="text-zinc-600 transition hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-white">
                            YouTube
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <div class="mt-10 border-t border-zinc-200 pt-6 text-sm text-zinc-500 dark:border-zinc-800 dark:text-zinc-400">
            © {{ now()->year }} {{ $shopName }}. All rights reserved.
        </div>
    </div>
</footer>
