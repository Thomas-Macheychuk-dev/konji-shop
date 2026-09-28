@extends('layouts.storefront')

@section('content')
    @php
        $effectiveDate = \Illuminate\Support\Carbon::parse((string) config('legal.effective_date'))->format('d.m.Y');
        $complaintPdf = asset((string) config('legal.documents.complaint_form_pdf'));
    @endphp

    <div class="mx-auto max-w-4xl px-4 py-10 sm:px-6 lg:px-8">
        <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm sm:p-8">
            <p class="text-sm font-medium text-zinc-500">Prawa konsumenta</p>
            <h1 class="mt-2 text-3xl font-bold tracking-tight text-zinc-900">Reklamacje - niezgodność towaru z umową</h1>
            <p class="mt-2 text-sm text-zinc-500">Wersja: {{ config('legal.versions.terms') }} · obowiązuje od {{ $effectiveDate }}</p>

            <div class="mt-6">
                <a href="{{ $complaintPdf }}" target="_blank" rel="noopener noreferrer" class="inline-flex rounded-xl border border-zinc-300 px-4 py-2.5 text-sm font-semibold text-zinc-800 hover:bg-zinc-50">Pobierz formularz reklamacyjny PDF</a>
            </div>

            <div class="prose prose-zinc mt-8 max-w-none">
                <h2>Odpowiedzialność Sprzedawcy</h2>
                <p>Sprzedawca odpowiada wobec Konsumenta i Przedsiębiorcy na prawach konsumenta za brak zgodności Towaru z Umową na zasadach określonych w rozdziale 5a ustawy o prawach konsumenta.</p>
                <p>Towar jest zgodny z Umową, jeżeli odpowiada w szczególności uzgodnionemu opisowi, rodzajowi, ilości, jakości, kompletności i funkcjonalności oraz nadaje się do szczególnego celu, o którym Klient poinformował Sprzedawcę najpóźniej przy zawarciu Umowy i który Sprzedawca zaakceptował.</p>
                <p>Sprzedawca ponosi odpowiedzialność za brak zgodności istniejący w chwili dostarczenia i ujawniony w ciągu dwóch lat od tej chwili, chyba że dłuższy termin wynika z przepisów lub właściwości Towaru.</p>

                <h2>Naprawa, wymiana, obniżenie ceny albo odstąpienie</h2>
                <p>Jeżeli Towar jest niezgodny z Umową, Konsument może żądać naprawy lub wymiany. Sprzedawca może w przypadkach przewidzianych ustawą zaproponować drugi sposób doprowadzenia do zgodności albo odmówić naprawy i wymiany, jeżeli są niemożliwe lub wymagałyby nadmiernych kosztów.</p>
                <p>W przypadkach określonych w ustawie Konsument może żądać obniżenia ceny albo odstąpić od Umowy, w szczególności gdy Sprzedawca odmówił doprowadzenia Towaru do zgodności, nie dokonał tego prawidłowo, brak zgodności nadal występuje, jest istotny albo z okoliczności wynika, że Towar nie zostanie doprowadzony do zgodności w rozsądnym czasie lub bez nadmiernych niedogodności.</p>
                <p>Naprawa lub wymiana odbywa się w rozsądnym czasie i bez nadmiernych niedogodności dla Konsumenta. Koszty naprawy lub wymiany, w tym koszty przesyłki, ponosi Sprzedawca w zakresie wymaganym przez prawo.</p>

                <h2>Jak złożyć reklamację</h2>
                <p>Reklamację można złożyć e-mailem na adres <a href="mailto:{{ config('legal.seller.email') }}">{{ config('legal.seller.email') }}</a> albo pisemnie na dane kontaktowe Sprzedawcy.</p>
                <p>Dla sprawnego rozpatrzenia zaleca się podanie numeru zamówienia lub innego dowodu zakupu, nazwy Towaru, opisu problemu, daty jego ujawnienia i żądania Klienta. <strong>Brak paragonu nie jest samodzielną podstawą do odmowy przyjęcia reklamacji</strong>, jeżeli zakup może być wykazany w inny sposób.</p>
                <p>Oryginalne opakowanie nie jest warunkiem przyjęcia reklamacji. Protokół szkody przewozowej może być pomocny, ale jego brak nie pozbawia Konsumenta praw z tytułu niezgodności Towaru z Umową.</p>

                <h2>Termin odpowiedzi</h2>
                <p>Sprzedawca udziela odpowiedzi na reklamację Konsumenta w terminie <strong>14 dni od dnia jej otrzymania</strong>. Brak odpowiedzi w tym terminie oznacza uznanie reklamacji. Odpowiedź jest przekazywana na papierze lub innym trwałym nośniku.</p>

                <h2>Przekazanie Towaru</h2>
                <p>W reklamacji z tytułu niezgodności Towaru z Umową Konsument udostępnia Towar Sprzedawcy, a Sprzedawca odbiera go na swój koszt w przypadkach wymaganych przepisami. Sposób przekazania Towaru może zostać uzgodniony z obsługą Sklepu.</p>

                <h2>Gwarancja</h2>
                <p>Gwarancja producenta lub dystrybutora, jeżeli została udzielona, jest niezależna od ustawowej odpowiedzialności Sprzedawcy za zgodność Towaru z Umową i nie ogranicza praw Konsumenta.</p>
            </div>
        </div>
    </div>
@endsection
