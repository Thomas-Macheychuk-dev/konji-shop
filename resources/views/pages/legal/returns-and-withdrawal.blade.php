@extends('layouts.storefront')

@section('content')
    @php
        $effectiveDate = \Illuminate\Support\Carbon::parse((string) config('legal.effective_date'))->format('d.m.Y');
        $withdrawalPdf = asset((string) config('legal.documents.withdrawal_form_pdf'));
    @endphp

    <div class="mx-auto max-w-4xl px-4 py-10 sm:px-6 lg:px-8">
        <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm sm:p-8">
            <p class="text-sm font-medium text-zinc-500">Prawa konsumenta</p>
            <h1 class="mt-2 text-3xl font-bold tracking-tight text-zinc-900">Zwroty i odstąpienie od umowy</h1>
            <p class="mt-2 text-sm text-zinc-500">Wersja: {{ config('legal.versions.returns') }} · obowiązuje od {{ $effectiveDate }}</p>

            <div class="mt-6 flex flex-wrap gap-3">
                <a href="{{ route('withdrawals.start') }}" class="inline-flex rounded-xl bg-zinc-900 px-4 py-2.5 text-sm font-semibold text-white hover:bg-zinc-700">Złóż odstąpienie online</a>
                <a href="{{ $withdrawalPdf }}" target="_blank" rel="noopener noreferrer" class="inline-flex rounded-xl border border-zinc-300 px-4 py-2.5 text-sm font-semibold text-zinc-800 hover:bg-zinc-50">Pobierz formularz PDF</a>
            </div>

            <div class="prose prose-zinc mt-8 max-w-none">
                <h2>14 dni na odstąpienie</h2>
                <p>Konsument oraz Przedsiębiorca na prawach konsumenta może co do zasady odstąpić od umowy zawartej na odległość w terminie 14 dni bez podawania przyczyny, z wyjątkami wynikającymi z ustawy i Regulaminu.</p>
                <p>Termin biegnie od objęcia Towaru w posiadanie przez uprawnioną osobę albo wskazaną przez nią osobę trzecią inną niż przewoźnik; przy wielu Towarach dostarczanych osobno - od objęcia w posiadanie ostatniego Towaru, partii lub części.</p>

                <h2>Jak złożyć oświadczenie</h2>
                <p>Aby zachować termin, wystarczy przed jego upływem przesłać jednoznaczne oświadczenie o odstąpieniu. Możesz skorzystać z formularza elektronicznego w sklepie, wysłać wiadomość na <a href="mailto:{{ config('legal.returns.contact_email') }}">{{ config('legal.returns.contact_email') }}</a> albo przesłać oświadczenie pisemnie. Skorzystanie z gotowego formularza nie jest obowiązkowe.</p>
                <p>Adres zwrotu: <strong>{{ config('legal.returns.return_address') }}</strong>.</p>

                <h2>Odesłanie Towaru i zwrot płatności</h2>
                <p>Po odstąpieniu Towar należy zwrócić niezwłocznie, nie później niż w ciągu 14 dni od złożenia oświadczenia. Do zachowania terminu wystarcza odesłanie Towaru przed jego upływem.</p>
                <p>Klient ponosi bezpośredni koszt odesłania Towaru, chyba że Sprzedawca zgodził się go ponieść albo nie poinformował Klienta o tym obowiązku.</p>
                <p>Sprzedawca zwraca otrzymane płatności, w tym koszt najtańszego zwykłego sposobu dostawy oferowanego dla danego zamówienia, niezwłocznie, nie później niż w terminie 14 dni od otrzymania oświadczenia. Zwrot może zostać wstrzymany do chwili otrzymania Towaru albo dostarczenia dowodu jego odesłania - w zależności od tego, które zdarzenie nastąpi wcześniej.</p>

                <h2>Wyroby medyczne i zabezpieczenie higieniczne</h2>
                <p><strong>Sam fakt, że produkt jest wyrobem medycznym albo ma kontakt z ciałem, nie wyłącza automatycznie prawa odstąpienia.</strong></p>
                <p>Prawo odstąpienia może nie przysługiwać w odniesieniu do Towaru dostarczonego w zapieczętowanym opakowaniu, którego po otwarciu nie można zwrócić ze względu na ochronę zdrowia lub ze względów higienicznych, jeżeli opakowanie zostało otwarte po dostarczeniu - zgodnie z art. 38 ust. 1 pkt 5 ustawy o prawach konsumenta.</p>
                <p>Wyjątek jest oceniany dla konkretnego Towaru. Nie oznacza automatycznego wyłączenia całej kategorii wyrobów medycznych. Przy wyrobach kompresyjnych wymagających pomiaru przed naruszeniem zabezpieczenia należy porównać oznaczenie modelu, rozmiaru, strony, klasy ucisku i innych parametrów na opakowaniu z zamówieniem i tabelą producenta.</p>

                <h2>Towary wykonywane według specyfikacji klienta</h2>
                <p>Prawo odstąpienia nie przysługuje w odniesieniu do Towaru nieprefabrykowanego, wyprodukowanego według specyfikacji Konsumenta lub służącego zaspokojeniu jego zindywidualizowanych potrzeb - zgodnie z art. 38 ust. 1 pkt 3 ustawy o prawach konsumenta.</p>
                <p>Sam wybór standardowego rozmiaru, koloru lub gotowego wariantu z katalogu nie przesądza o zastosowaniu tego wyjątku.</p>

                <h2>Sprawdzenie Towaru i zmniejszenie wartości</h2>
                <p>Jeżeli prawo odstąpienia przysługuje, Konsument może sprawdzić Towar w zakresie koniecznym do stwierdzenia jego charakteru, cech i funkcjonowania. Jeżeli korzystanie wykracza poza ten zakres, Konsument może odpowiadać za rzeczywiste zmniejszenie wartości Towaru.</p>
                <p>Ocena jest dokonywana indywidualnie z uwzględnieniem stanu Towaru, śladów używania, zabrudzeń, uszkodzeń, braków, a w Towarach mających kontakt z ciałem także kwestii higienicznych i możliwości bezpiecznego ponownego wprowadzenia do obrotu. Zmniejszenie wartości nie jest potrąceniem automatycznym.</p>

                <h2>Reklamacja to odrębne prawo</h2>
                <p>Wyjątki dotyczące odstąpienia od umowy nie ograniczają prawa do reklamacji Towaru niezgodnego z Umową. Szczegóły znajdują się na stronie <a href="{{ route('legal.complaints') }}">Reklamacje - niezgodność towaru z umową</a>.</p>
            </div>
        </div>
    </div>
@endsection
