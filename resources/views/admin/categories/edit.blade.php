@extends('layouts.storefront')

@section('content')
    <div class="mx-auto max-w-5xl px-4 py-10 sm:px-6 lg:px-8">
        <div class="mb-8 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <a href="{{ route('admin.categories.index') }}" class="text-sm font-medium text-zinc-500 hover:text-zinc-700">
                    ← Wróć do kategorii
                </a>

                <h1 class="mt-3 text-3xl font-bold tracking-tight text-zinc-900">
                    Edytuj kategorię
                </h1>

                <p class="mt-2 text-sm text-zinc-600">
                    {{ $category->name }}
                </p>
            </div>

            <div class="rounded-2xl border border-zinc-200 bg-white px-5 py-4 text-sm shadow-sm">
                <div class="font-semibold text-zinc-900">
                    {{ $category->status->label() }}
                </div>
                <div class="mt-1 text-xs text-zinc-500">
                    Produkty: {{ $category->products_count }} · Podkategorie: {{ $category->children_count }}
                </div>
            </div>
        </div>

        @if (session('success'))
            <div class="mb-6 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
                {{ session('success') }}
            </div>
        @endif

        @if (session('error'))
            <div class="mb-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                {{ session('error') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="mb-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                Nie zapisano kategorii. Popraw zaznaczone pola.
            </div>
        @endif

        <form method="POST" action="{{ route('admin.categories.update', $category) }}" class="space-y-6">
            @csrf
            @method('PATCH')

            @include('admin.categories._form')
        </form>
    </div>
@endsection
