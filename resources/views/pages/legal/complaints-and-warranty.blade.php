@extends('layouts.storefront')

@section('content')
    @php
        $effectiveDate = \Illuminate\Support\Carbon::parse((string) config('legal.effective_date'))->format('d.m.Y');
        $complaintPdf = asset((string) config('legal.documents.complaint_form_pdf'));
        $sellerEmail = (string) config('legal.seller.email');
    @endphp

    <div class="mx-auto max-w-4xl px-4 py-10 sm:px-6 lg:px-8">
        <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm sm:p-8">
            <p class="text-sm font-medium text-zinc-500">Prawa konsumenta</p>
            <h1 class="mt-2 text-3xl font-bold tracking-tight text-zinc-900">Reklamacje – niezgodność towaru z umową</h1>
            <p class="mt-3 max-w-2xl text-base leading-7 text-zinc-600">
                Jeśli towar jest wadliwy albo nie odpowiada zamówieniu, zgłoś reklamację. Odpowiadamy w ciągu 14 dni.
            </p>

            <div class="mt-6 flex flex-wrap gap-3">
                <a href="mailto:{{ $sellerEmail }}?subject={{ rawurlencode('Reklamacja') }}" class="inline-flex rounded-xl bg-zinc-900 px-4 py-2.5 text-sm font-semibold text-white hover:bg-zinc-700">Zgłoś reklamację e-mailem</a>
                <a href="{{ $complaintPdf }}" target="_blank" rel="noopener noreferrer" class="inline-flex rounded-xl border border-zinc-300 px-4 py-2.5 text-sm font-semibold text-zinc-800 hover:bg-zinc-50">Pobierz formularz reklamacyjny PDF</a>
            </div>

            @include('pages.legal._highlights', ['items' => [
                ['title' => '2 lata odpowiedzialności', 'text' => 'Za wady istniejące przy dostawie i ujawnione w tym czasie.'],
                ['title' => '14 dni na odpowiedź', 'text' => 'Brak odpowiedzi w terminie oznacza uznanie reklamacji.'],
                ['title' => 'Paragon nie jest wymagany', 'text' => 'Wystarczy inny dowód zakupu, np. numer zamówienia.'],
            ]])

            <div class="prose prose-zinc mt-10 max-w-none prose-h2:mt-8 prose-h2:text-xl prose-a:font-medium">
                <h2>Za co odpowiadamy</h2>
                <p>Odpowiadamy wobec konsumenta i przedsiębiorcy na prawach konsumenta za brak zgodności towaru z umową, na zasadach rozdziału 5a ustawy o prawach konsumenta.</p>
                <p>Towar jest zgodny z umową, jeśli odpowiada opisowi, rodzajowi, ilości, jakości, kompletności i funkcjonalności oraz nadaje się do celu, o którym nas poinformowałeś(-aś) najpóźniej przy zakupie i który zaakceptowaliśmy.</p>
                <p>Odpowiadamy za niezgodność istniejącą w chwili dostarczenia i ujawnioną w ciągu dwóch lat, chyba że dłuższy termin wynika z przepisów lub właściwości towaru.</p>

                <h2>Czego możesz żądać</h2>
                <ul>
                    <li><strong>Naprawy albo wymiany</strong> – to pierwszy krok. Możemy zaproponować drugi z tych sposobów albo odmówić, jeśli są niemożliwe lub wymagałyby nadmiernych kosztów.</li>
                    <li><strong>Obniżenia ceny albo odstąpienia od umowy</strong> – gdy odmówimy naprawy i wymiany, nie wykonamy ich prawidłowo, wada się powtarza, jest istotna albo nie da się jej usunąć w rozsądnym czasie.</li>
                </ul>
                <p>Naprawa lub wymiana odbywa się w rozsądnym czasie i bez nadmiernych niedogodności dla Ciebie. Jej koszty, w tym przesyłkę, ponosimy my w zakresie wymaganym przez prawo.</p>

                <h2>Jak złożyć reklamację</h2>
                <p>Napisz na <a href="mailto:{{ $sellerEmail }}">{{ $sellerEmail }}</a> albo wyślij reklamację pocztą na adres {{ config('legal.returns.return_address') }}. Żeby przyspieszyć sprawę, podaj:</p>
                <ul>
                    <li>numer zamówienia lub inny dowód zakupu,</li>
                    <li>nazwę towaru,</li>
                    <li>opis problemu i datę jego zauważenia,</li>
                    <li>czego oczekujesz (naprawa, wymiana, obniżka ceny, odstąpienie).</li>
                </ul>
                <p><strong>Brak paragonu nie jest samodzielną podstawą do odmowy przyjęcia reklamacji</strong>, jeżeli zakup można wykazać inaczej. Nie wymagamy też oryginalnego opakowania ani protokołu szkody od kuriera.</p>

                <h2>Termin odpowiedzi</h2>
                <p>Odpowiadamy na reklamację konsumenta w terminie <strong>14 dni od dnia jej otrzymania</strong>, na papierze lub innym trwałym nośniku (np. e-mailem). Brak odpowiedzi w tym terminie oznacza uznanie reklamacji.</p>

                <h2>Przekazanie towaru</h2>
                <p>Towar trzeba nam udostępnić. W przypadkach wymaganych przepisami odbieramy go na nasz koszt. Sposób przekazania możesz ustalić z obsługą sklepu.</p>

                <h2>Gwarancja</h2>
                <p>Gwarancja producenta lub dystrybutora, jeśli została udzielona, działa niezależnie od naszej odpowiedzialności za zgodność towaru z umową i nie ogranicza Twoich praw.</p>
            </div>

            <p class="mt-10 border-t border-zinc-200 pt-4 text-sm text-zinc-500">
                Obowiązuje od {{ $effectiveDate }} (wersja {{ config('legal.versions.terms') }}). Wiążące zasady opisują § 12 i § 13 <a href="{{ route('legal.terms') }}" class="font-medium text-zinc-700 underline underline-offset-4">Regulaminu sklepu</a>.
            </p>
        </div>
    </div>
@endsection
