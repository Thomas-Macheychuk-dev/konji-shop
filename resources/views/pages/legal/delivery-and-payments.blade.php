@extends('layouts.storefront')

@section('content')
    @php
        $effectiveDate = \Illuminate\Support\Carbon::parse((string) config('legal.effective_date'))->format('d.m.Y');
    @endphp

    <div class="mx-auto max-w-4xl px-4 py-10 sm:px-6 lg:px-8">
        <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm sm:p-8">
            <p class="text-sm font-medium text-zinc-500">Informacje przed zakupem</p>
            <h1 class="mt-2 text-3xl font-bold tracking-tight text-zinc-900">Dostawa i płatności</h1>
            <p class="mt-2 text-sm text-zinc-500">Stan informacji: {{ $effectiveDate }}</p>

            <div class="prose prose-zinc mt-8 max-w-none">
                <h2>Aktualne metody i ceny dostawy</h2>
                <p>Aktualne sposoby dostawy, ich ceny, terytorium realizacji i przewidywane terminy są przedstawiane przed złożeniem zamówienia. Nie utrwalamy na tej stronie stawek przewoźników, ponieważ mogą się zmieniać dla przyszłych zamówień.</p>
                <p>W zależności od aktualnej konfiguracji Sklepu dostępne mogą być m.in. dostawa kurierska, dostawa do paczkomatu oraz odbiór osobisty. Wiążące dla konkretnego zamówienia są opcje i koszty pokazane w kasie przed jego złożeniem.</p>

                <h2>Płatności</h2>
                <p>Dostępne sposoby płatności są każdorazowo prezentowane w koszyku lub podczas składania zamówienia. Mogą obejmować w szczególności przelew bankowy, płatność elektroniczną Paynow, kartę płatniczą oraz płatność za pobraniem, o ile dana metoda jest aktualnie dostępna.</p>
                <p>Jeżeli Klient wybiera przedpłatę, realizacja zamówienia może rozpocząć się po pozytywnej autoryzacji płatności lub uznaniu rachunku Sprzedawcy, zgodnie z informacją podaną przy danej metodzie.</p>

                <h2>Odbiór przesyłki i protokół szkody</h2>
                <p>Klient powinien w miarę możliwości sprawdzić stan przesyłki przy odbiorze. Protokół szkody przewozowej może ułatwić dochodzenie roszczeń wobec przewoźnika, ale <strong>jego brak nie pozbawia Konsumenta praw z tytułu niezgodności Towaru z Umową</strong>.</p>

                <h2>Odbiór osobisty zamówienia internetowego</h2>
                <p>Jeżeli Sklep umożliwia odbiór osobisty zamówienia zawartego online, sam odbiór w lokalu nie pozbawia Konsumenta prawa odstąpienia od Umowy zawartej na odległość.</p>

                <h2>Zwrot płatności</h2>
                <p>W przypadku zwrotu płatności Sprzedawca używa takiego samego sposobu płatności, jakiego użył Klient, chyba że Klient wyraźnie zgodzi się na inny sposób, który nie powoduje dla niego kosztów.</p>
            </div>
        </div>
    </div>
@endsection
