@extends('layouts.storefront')

@section('content')
    @php
        $effectiveDate = \Illuminate\Support\Carbon::parse((string) config('legal.effective_date'))->format('d.m.Y');
        $seller = config('legal.seller');
    @endphp

    <div class="mx-auto max-w-4xl px-4 py-10 sm:px-6 lg:px-8">
        <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm sm:p-8">
            <p class="text-sm font-medium text-zinc-500">Informacje przed zakupem</p>
            <h1 class="mt-2 text-3xl font-bold tracking-tight text-zinc-900">Dostawa i płatności</h1>
            <p class="mt-3 max-w-2xl text-base leading-7 text-zinc-600">
                Wszystkie dostępne metody dostawy i płatności, wraz z ich kosztem, zobaczysz w kasie przed złożeniem zamówienia.
            </p>

            @include('pages.legal._highlights', ['items' => [
                ['title' => 'Kurier i paczkomaty', 'text' => 'InPost, DPD i UPS. Paczkomaty InPost w całej Polsce.'],
                ['title' => 'Płatność online', 'text' => 'Przez Paynow. Dostępne metody zobaczysz przy płatności.'],
                ['title' => 'Odbiór osobisty', 'text' => e($seller['city']).', ul. '.e($seller['street']).', jeżeli tę opcję pokazuje kasa.'],
            ]])

            <div class="prose prose-zinc mt-10 max-w-none prose-h2:mt-8 prose-h2:text-xl prose-a:font-medium">
                <h2>Koszt i czas dostawy</h2>
                <p>Sposoby dostawy, ich ceny, obszar i przewidywany czas realizacji pokazujemy w kasie przed złożeniem zamówienia. Nie podajemy tu stawek przewoźników, bo mogą się zmieniać. Wiążące są opcje i koszty pokazane przy Twoim zamówieniu.</p>

                <h2>Płatności</h2>
                <p>Dostępne sposoby płatności widzisz w koszyku i podczas składania zamówienia. Mogą to być w szczególności płatność elektroniczna Paynow, karta płatnicza i przelew bankowy, o ile dana metoda jest w danym momencie dostępna.</p>
                <p>Przy przedpłacie realizujemy zamówienie po autoryzacji płatności albo po zaksięgowaniu wpłaty na naszym rachunku.</p>

                <h2>Odbiór przesyłki</h2>
                <p>Jeśli możesz, sprawdź stan paczki przy kurierze. Protokół szkody ułatwia rozliczenie z przewoźnikiem, ale <strong>jego brak nie odbiera Ci prawa do reklamacji towaru niezgodnego z umową</strong>.</p>

                <h2>Odbiór osobisty</h2>
                <p>Odbiór osobisty zamówienia złożonego przez internet nie odbiera Ci prawa do odstąpienia od umowy zawartej na odległość.</p>

                <h2>Zwrot płatności</h2>
                <p>Pieniądze zwracamy tą samą metodą, którą zapłaciłeś(-aś), chyba że wyraźnie zgodzisz się na inną, bezpłatną dla Ciebie.</p>
            </div>

            <p class="mt-10 border-t border-zinc-200 pt-4 text-sm text-zinc-500">
                Aktualne na dzień {{ $effectiveDate }}. Szczegółowe zasady znajdziesz w <a href="{{ route('legal.terms') }}" class="font-medium text-zinc-700 underline underline-offset-4">Regulaminie sklepu</a> (§ 7 i § 8).
            </p>
        </div>
    </div>
@endsection
