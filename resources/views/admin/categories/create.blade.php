@extends('layouts.storefront')

@section('content')
    <div class="mx-auto max-w-5xl px-4 py-10 sm:px-6 lg:px-8">
        <div class="mb-8">
            <a href="{{ route('admin.categories.index') }}" class="text-sm font-medium text-zinc-500 hover:text-zinc-700">
                ← Wróć do kategorii
            </a>

            <h1 class="mt-3 text-3xl font-bold tracking-tight text-zinc-900">
                Stwórz kategorię
            </h1>

            <p class="mt-2 text-sm text-zinc-600">
                Dodaj nową kategorię produktu do katalogu sklepu.
            </p>
        </div>

        @if ($errors->any())
            <div class="mb-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                Nie zapisano kategorii. Popraw zaznaczone pola.
            </div>
        @endif

        <form method="POST" action="{{ route('admin.categories.store') }}" class="space-y-6">
            @csrf

            @include('admin.categories._form')
        </form>
    </div>
@endsection
