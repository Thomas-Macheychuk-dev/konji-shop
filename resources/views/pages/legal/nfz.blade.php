@extends('layouts.storefront')

@section('content')
    @php
        $seller = config('legal.seller');
        $phoneHref = 'tel:'.preg_replace('/[^0-9+]/', '', (string) $seller['phone']);
        $forms = collect([
            ['title' => 'Zlecenie na zaopatrzenie w wyroby medyczne', 'path' => (string) config('legal.documents.nfz_order_form_pdf')],
            ['title' => 'Upoważnienie do odbioru', 'path' => (string) config('legal.documents.nfz_collection_authorisation_pdf')],
        ])->map(fn (array $form): array => [...$form, 'available' => is_file(public_path($form['path']))]);
    @endphp

    <div class="mx-auto max-w-4xl px-4 py-10 sm:px-6 lg:px-8">
        <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm sm:p-8">
            <p class="text-sm font-medium text-zinc-500">Pomoc w realizacji zleceń</p>
            <h1 class="mt-2 text-3xl font-bold tracking-tight text-zinc-900">Refundacje NFZ</h1>
            <p class="mt-3 max-w-2xl text-base leading-7 text-zinc-600">
                Realizujemy zlecenia na wyroby medyczne refundowane przez NFZ.
                Szczegółów udzielamy w sklepie stacjonarnym w {{ $seller['city'] === 'Poznań' ? 'Poznaniu' : $seller['city'] }} i pod numerem
                <a href="{{ $phoneHref }}" class="font-medium text-zinc-900 underline underline-offset-4">{{ $seller['phone'] }}</a>.
            </p>

            @include('pages.legal._highlights', ['items' => [
                ['title' => '1. Zlecenie od lekarza', 'text' => 'Wystawia je lekarz specjalista, a dla części wyrobów także lekarz rodzinny.'],
                ['title' => '2. Przyjdź lub zadzwoń', 'text' => 'Sprawdzimy zlecenie, dostępność wyrobu i ewentualną dopłatę.'],
                ['title' => '3. Odbiór', 'text' => 'Osobiście albo przez osobę z pisemnym upoważnieniem.'],
            ]])

            <h2 class="mt-10 text-xl font-semibold text-zinc-900">Formularze do pobrania</h2>
            <div class="mt-4 grid gap-3 sm:grid-cols-2">
                @foreach ($forms as $form)
                    <div class="flex items-center justify-between gap-4 rounded-xl px-4 py-4 ring-1 ring-zinc-200">
                        <div>
                            <p class="text-sm font-semibold text-zinc-900">{{ $form['title'] }}</p>
                            <p class="mt-0.5 text-xs text-zinc-500">{{ $form['available'] ? 'PDF' : 'Dostępny w sklepie lub na prośbę e-mailem' }}</p>
                        </div>
                        @if ($form['available'])
                            <a href="{{ asset($form['path']) }}" target="_blank" rel="noopener noreferrer" class="inline-flex shrink-0 rounded-xl border border-zinc-300 px-3 py-2 text-sm font-semibold text-zinc-800 hover:bg-zinc-50">Pobierz</a>
                        @endif
                    </div>
                @endforeach
            </div>

            <div class="prose prose-zinc mt-10 max-w-none prose-h2:mt-8 prose-h2:text-xl prose-a:font-medium">
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
            </div>
        </div>
    </div>
@endsection
