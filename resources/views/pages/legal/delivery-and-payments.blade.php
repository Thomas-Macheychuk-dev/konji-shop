@extends('layouts.storefront')

@section('content')
    <div class="mx-auto max-w-4xl px-4 py-10 sm:px-6 lg:px-8">
        <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm sm:p-8">
            <p class="text-sm font-medium text-zinc-500">Informacje o kasie</p>

            <h1 class="mt-2 text-3xl font-bold tracking-tight text-zinc-900">
                Dostawa i płatności
            </h1>

            <p class="mt-6 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                Tekst roboczy. Sprawdź przed użyciem produkcyjnym.
            </p>

            <div class="prose prose-zinc mt-8 max-w-none">
                <h2>Metody dostawy</h2>
                <p>
                    Dostępne metody dostawy mogą obejmować dostawę kurierską, dostawę do paczkomatu InPost oraz odbiór osobisty, jeśli jest włączony.
                </p>

                <h2>Koszty dostawy</h2>
                <p>
                    Koszty dostawy są obliczane i wyświetlane w kasie przed złożeniem zamówienia.
                </p>

                <h2>Płatności</h2>
                <p>
                    Dostępne metody płatności są wyświetlane w kasie. Zamówienia są realizowane po potwierdzeniu płatności, chyba że wskazano inaczej.
                </p>

                <h2>Śledzenie zamówienia</h2>
                <p>
                    Jeśli śledzenie przesyłki jest dostępne, szczegóły śledzenia są wyświetlane na stronie zamówienia klienta i mogą zostać wysłane e-mailem.
                </p>
            </div>
        </div>
    </div>
@endsection
