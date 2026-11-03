@extends('layouts.storefront')

@section('content')
    <div class="mx-auto max-w-4xl px-4 py-10 sm:px-6 lg:px-8">
        <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm sm:p-8">
            <p class="text-sm font-medium text-zinc-500">Pomoc w realizacji zleceń</p>
            <h1 class="mt-2 text-3xl font-bold tracking-tight text-zinc-900">{{ $page->title }}</h1>
            <div class="mt-3 max-w-2xl text-base leading-7 text-zinc-600">
                {!! $page->intro !!}
            </div>

            @include('pages.legal._highlights', ['items' => [
                ['title' => '1. Zlecenie od lekarza', 'text' => 'Wystawia je lekarz specjalista, a dla części wyrobów także lekarz rodzinny.'],
                ['title' => '2. Przyjdź lub zadzwoń', 'text' => 'Sprawdzimy zlecenie, dostępność wyrobu i ewentualną dopłatę.'],
                ['title' => '3. Odbiór', 'text' => 'Osobiście albo przez osobę z pisemnym upoważnieniem.'],
            ]])

            <div class="prose prose-zinc mt-10 max-w-none prose-h2:mt-8 prose-h2:text-xl prose-a:font-medium">
                {!! $page->content !!}
            </div>
        </div>
    </div>
@endsection
