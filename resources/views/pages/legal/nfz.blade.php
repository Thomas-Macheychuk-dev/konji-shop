@extends('layouts.storefront')

@section('content')
    <div class="mx-auto max-w-4xl px-4 py-10 sm:px-6 lg:px-8">
        <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm sm:p-8">
            <p class="text-sm font-medium text-zinc-500">Pomoc w realizacji zleceń</p>
            <h1 class="mt-2 text-3xl font-bold tracking-tight text-zinc-900">Refundacje NFZ</h1>

            <div class="prose prose-zinc mt-8 max-w-none">
                <p>
                    Chcesz zapytać o realizację zlecenia NFZ? Skontaktuj się z nami,
                    aby potwierdzić dostępność wybranego wyrobu medycznego i sposób realizacji zlecenia.
                </p>
                <p>
                    Podaj nazwę lub rodzaj potrzebnego produktu. Przed złożeniem zamówienia
                    internetowego ustal szczegóły z obsługą sklepu.
                </p>
                <p>
                    <a href="{{ route('legal.contact') }}">Skontaktuj się z nami w sprawie refundacji NFZ</a>
                </p>
            </div>
        </div>
    </div>
@endsection
