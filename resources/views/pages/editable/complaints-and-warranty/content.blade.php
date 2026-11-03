@php
    $sellerEmail = (string) config('legal.seller.email');
@endphp
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
