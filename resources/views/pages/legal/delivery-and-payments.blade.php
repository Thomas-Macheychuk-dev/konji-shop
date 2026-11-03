@extends('layouts.storefront')

@section('content')
    @php
        $effectiveDate = \Illuminate\Support\Carbon::parse((string) config('legal.effective_date'))->format('d.m.Y');
        $seller = config('legal.seller');
    @endphp

    <div class="mx-auto max-w-4xl px-4 py-10 sm:px-6 lg:px-8">
        <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm sm:p-8">
            <p class="text-sm font-medium text-zinc-500">Informacje przed zakupem</p>
            <h1 class="mt-2 text-3xl font-bold tracking-tight text-zinc-900">{{ $page->title }}</h1>
            <div class="mt-3 max-w-2xl text-base leading-7 text-zinc-600">
                {!! $page->intro !!}
            </div>

            @include('pages.legal._highlights', ['items' => [
                ['title' => 'Kurier i paczkomaty', 'text' => 'InPost, DPD i UPS. Paczkomaty InPost w całej Polsce.'],
                ['title' => 'Płatność online', 'text' => 'Przez Paynow. Dostępne metody zobaczysz przy płatności.'],
                ['title' => 'Odbiór osobisty', 'text' => e($seller['city']).', ul. '.e($seller['street']).', jeżeli tę opcję pokazuje kasa.'],
            ]])

            <div class="prose prose-zinc mt-10 max-w-none prose-h2:mt-8 prose-h2:text-xl prose-a:font-medium">
                {!! $page->content !!}
            </div>

            <p class="mt-10 border-t border-zinc-200 pt-4 text-sm text-zinc-500">
                Aktualne na dzień {{ $effectiveDate }}. Szczegółowe zasady znajdziesz w <a href="{{ route('legal.terms') }}" class="font-medium text-zinc-700 underline underline-offset-4">Regulaminie sklepu</a> (§ 7 i § 8).
            </p>
        </div>
    </div>
@endsection
