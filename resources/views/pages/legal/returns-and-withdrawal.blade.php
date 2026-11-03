@extends('layouts.storefront')

@section('content')
    @php
        $effectiveDate = \Illuminate\Support\Carbon::parse((string) config('legal.effective_date'))->format('d.m.Y');
        $withdrawalPdf = asset((string) config('legal.documents.withdrawal_form_pdf'));
        $returnsEmail = (string) config('legal.returns.contact_email');
    @endphp

    <div class="mx-auto max-w-4xl px-4 py-10 sm:px-6 lg:px-8">
        <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm sm:p-8">
            <p class="text-sm font-medium text-zinc-500">Prawa konsumenta</p>
            <h1 class="mt-2 text-3xl font-bold tracking-tight text-zinc-900">Zwroty i odstąpienie od umowy</h1>
            <p class="mt-3 max-w-2xl text-base leading-7 text-zinc-600">
                Zamówienie złożone przez internet możesz zwrócić w ciągu 14 dni bez podawania przyczyny. Poniżej wyjaśniamy, jak to zrobić i kiedy to prawo nie przysługuje.
            </p>

            <div class="mt-6 flex flex-wrap gap-3">
                <a href="{{ route('withdrawals.start') }}" class="inline-flex rounded-xl bg-zinc-900 px-4 py-2.5 text-sm font-semibold text-white hover:bg-zinc-700">Złóż odstąpienie online</a>
                <a href="{{ $withdrawalPdf }}" target="_blank" rel="noopener noreferrer" class="inline-flex rounded-xl border border-zinc-300 px-4 py-2.5 text-sm font-semibold text-zinc-800 hover:bg-zinc-50">Pobierz formularz PDF</a>
            </div>

            @include('pages.legal._highlights', ['items' => [
                ['title' => '14 dni na decyzję', 'text' => 'Liczone od dnia, w którym odebrałeś(-aś) paczkę.'],
                ['title' => '14 dni na odesłanie', 'text' => 'Od złożenia oświadczenia. Wystarczy nadać paczkę przed terminem.'],
                ['title' => 'Adres zwrotu', 'text' => e((string) config('legal.returns.return_address'))],
            ]])

            <div class="prose prose-zinc mt-10 max-w-none prose-h2:mt-8 prose-h2:text-xl prose-a:font-medium">
                <h2>Kto może odstąpić od umowy</h2>
                <p>Konsument oraz przedsiębiorca na prawach konsumenta mogą odstąpić od umowy zawartej na odległość w ciągu 14 dni bez podawania przyczyny, z wyjątkami opisanymi niżej i w Regulaminie.</p>
                <p>Termin liczy się od dnia, w którym Ty albo wskazana przez Ciebie osoba (inna niż przewoźnik) odebraliście towar. Jeśli zamówienie przyszło w kilku paczkach, liczy się od odbioru ostatniej.</p>

                <h2>Jak złożyć oświadczenie</h2>
                <p>Wystarczy, że przed upływem terminu wyślesz nam jednoznaczne oświadczenie o odstąpieniu. Możesz:</p>
                <ul>
                    <li>skorzystać z <a href="{{ route('withdrawals.start') }}">formularza online</a>,</li>
                    <li>napisać na <a href="mailto:{{ $returnsEmail }}">{{ $returnsEmail }}</a>,</li>
                    <li>wysłać oświadczenie pocztą.</li>
                </ul>
                <p>Gotowy formularz ułatwia sprawę, ale nie jest obowiązkowy.</p>

                <h2>Odesłanie towaru i zwrot pieniędzy</h2>
                <p>Towar odeślij niezwłocznie, najpóźniej w ciągu 14 dni od złożenia oświadczenia. Wystarczy, że nadasz paczkę przed upływem tego terminu. Koszt odesłania ponosisz Ty, chyba że zgodziliśmy się go pokryć.</p>
                <p>Zwracamy wszystkie płatności, łącznie z kosztem najtańszej zwykłej dostawy oferowanej przy zamówieniu, najpóźniej w ciągu 14 dni od otrzymania oświadczenia. Możemy wstrzymać zwrot do chwili otrzymania towaru albo dowodu jego nadania, zależnie od tego, co nastąpi wcześniej.</p>

                <h2>Wyroby medyczne i zabezpieczenie higieniczne</h2>
                <p><strong>Sam fakt, że produkt jest wyrobem medycznym albo ma kontakt z ciałem, nie wyłącza automatycznie prawa odstąpienia.</strong></p>
                <p>Prawo odstąpienia nie przysługuje, jeśli towar został dostarczony w zapieczętowanym opakowaniu, po otwarciu nie może zostać zwrócony ze względu na ochronę zdrowia lub higienę, a opakowanie otwarto po dostarczeniu (art. 38 ust. 1 pkt 5 ustawy o prawach konsumenta). O takim zabezpieczeniu informujemy na karcie produktu przed zakupem.</p>
                <p>Przy wyrobach kompresyjnych przed otwarciem plomby porównaj model, rozmiar, stronę i klasę ucisku na opakowaniu z zamówieniem i tabelą producenta.</p>

                <h2>Towary wykonywane na zamówienie</h2>
                <p>Prawo odstąpienia nie przysługuje dla towaru wykonanego według Twojej specyfikacji lub dopasowanego indywidualnie do Twoich potrzeb (art. 38 ust. 1 pkt 3 ustawy o prawach konsumenta). Wybór standardowego rozmiaru, koloru czy wariantu z katalogu nie czyni towaru indywidualnym.</p>

                <h2>Sprawdzenie towaru</h2>
                <p>Możesz sprawdzić towar tak, jak w sklepie stacjonarnym, aby ocenić jego charakter, cechy i działanie. Jeśli używasz go w szerszym zakresie, możesz odpowiadać za rzeczywiste zmniejszenie jego wartości.</p>
                <p>Każdy zwrot oceniamy indywidualnie: stan towaru, ślady używania, zabrudzenia, braki, a przy produktach mających kontakt z ciałem także higienę i możliwość ponownej sprzedaży. Nie potrącamy kwot automatycznie.</p>

                <h2>Reklamacja to osobne prawo</h2>
                <p>Ograniczenia zwrotów nie dotyczą reklamacji. Jeśli towar jest wadliwy albo niezgodny z zamówieniem, przeczytaj stronę <a href="{{ route('legal.complaints') }}">Reklamacje – niezgodność towaru z umową</a>.</p>
            </div>

            <p class="mt-10 border-t border-zinc-200 pt-4 text-sm text-zinc-500">
                Obowiązuje od {{ $effectiveDate }} (wersja {{ config('legal.versions.returns') }}). Wiążące zasady opisują § 9–11 i § 13 <a href="{{ route('legal.terms') }}" class="font-medium text-zinc-700 underline underline-offset-4">Regulaminu sklepu</a>.
            </p>
        </div>
    </div>
@endsection
