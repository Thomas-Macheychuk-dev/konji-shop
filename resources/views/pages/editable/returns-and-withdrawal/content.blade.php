@php
    $returnsEmail = (string) config('legal.returns.contact_email');
@endphp
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
