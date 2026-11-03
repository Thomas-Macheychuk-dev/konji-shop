@extends('layouts.storefront')

@section('content')
    @php
        $effectiveDate = \Illuminate\Support\Carbon::parse((string) config('legal.effective_date'))->format('d.m.Y');
        $withdrawalPdf = asset((string) config('legal.documents.withdrawal_form_pdf'));
    @endphp

    <div class="mx-auto max-w-4xl px-4 py-10 sm:px-6 lg:px-8">
        <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm sm:p-8">
            <p class="text-sm font-medium text-zinc-500">Prawa konsumenta</p>
            <h1 class="mt-2 text-3xl font-bold tracking-tight text-zinc-900">{{ $page->title }}</h1>
            <div class="mt-3 max-w-2xl text-base leading-7 text-zinc-600">
                {!! $page->intro !!}
            </div>

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
                {!! $page->content !!}
            </div>

            <p class="mt-10 border-t border-zinc-200 pt-4 text-sm text-zinc-500">
                Obowiązuje od {{ $effectiveDate }} (wersja {{ config('legal.versions.returns') }}). Wiążące zasady opisują § 9–11 i § 13 <a href="{{ route('legal.terms') }}" class="font-medium text-zinc-700 underline underline-offset-4">Regulaminu sklepu</a>.
            </p>
        </div>
    </div>
@endsection
