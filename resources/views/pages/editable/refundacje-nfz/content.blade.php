@php
    $seller = config('legal.seller');
    $phoneHref = 'tel:'.preg_replace('/[^0-9+]/', '', (string) $seller['phone']);
@endphp
<h2>Na czym polega refundacja</h2>
<p>Narodowy Fundusz Zdrowia finansuje zakup wyrobów medycznych wydawanych na zlecenie, do kwoty limitu określonego w przepisach. Przepisy określają też, komu i jak często przysługuje dany wyrób oraz które wyroby podlegają naprawie. Jeśli cena wybranego wyrobu przekracza limit, różnicę dopłacasz sam(a).</p>

<h2>Jak zrealizować zlecenie</h2>
<ol>
    <li>Poproś lekarza o zlecenie na zaopatrzenie w wyrób medyczny. Najczęściej wystawia je lekarz specjalista, np. ortopeda. Część wyrobów może zlecić także lekarz pierwszego kontaktu.</li>
    <li>Z wystawionym zleceniem przyjdź do naszego sklepu albo zadzwoń. Pomożemy dobrać wyrób, sprawdzimy jego dostępność i powiemy, czy wymaga dopłaty.</li>
    <li>Wyrób odbierasz osobiście. Może go też odebrać inna osoba, jeśli ma od Ciebie pisemne upoważnienie.</li>
</ol>

<h2>Masz pytania?</h2>
<p>
    Zadzwoń pod numer <a href="{{ $phoneHref }}">{{ $seller['phone'] }}</a>, napisz na
    <a href="mailto:{{ $seller['email'] }}">{{ $seller['email'] }}</a> albo odwiedź nas:
    {{ $seller['company_name'] }}, ul. {{ $seller['street'] }}, {{ $seller['postcode'] }} {{ $seller['city'] }}.
</p>
