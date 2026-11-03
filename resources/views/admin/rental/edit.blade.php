@extends('layouts.storefront')

@push('styles')
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/trix@2.1.19/dist/trix.css">
@endpush

@push('scripts')
    @include('admin.partials.html-editor-scripts')
@endpush

@section('content')
    <div class="mx-auto max-w-5xl px-4 py-10 sm:px-6 lg:px-8">
        <div class="mb-8">
            <a href="{{ route('admin.rental.index') }}" class="text-sm font-medium text-zinc-500 hover:text-zinc-700">
                ← Wróć do wypożyczalni
            </a>

            <div class="mt-3 flex items-start justify-between gap-4">
                <div>
                    <h1 class="text-3xl font-bold tracking-tight text-zinc-900">{{ $item->name }}</h1>
                    <p class="mt-2 text-sm text-zinc-600">
                        Identyfikator w zapytaniach: {{ $item->slug }} · {{ $item->is_active ? 'widoczny na stronie' : 'ukryty' }}
                    </p>
                </div>

                @if ($item->is_active)
                    <a
                        href="{{ $item->url() }}"
                        target="_blank"
                        class="shrink-0 rounded-xl border border-zinc-300 px-4 py-2 text-sm font-semibold text-zinc-700 hover:bg-zinc-50"
                    >
                        Zobacz stronę sprzętu
                    </a>
                @endif
            </div>
        </div>

        @if (session('success'))
            <div class="mb-6 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
                {{ session('success') }}
            </div>
        @endif

        <form
            method="POST"
            action="{{ route('admin.rental.update', $item) }}"
            enctype="multipart/form-data"
            class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm"
        >
            @csrf
            @method('PATCH')

            @include('admin.rental._form')

            <div class="mt-8 flex justify-end border-t border-zinc-100 pt-6">
                <button type="submit" class="rounded-xl bg-zinc-900 px-5 py-3 text-sm font-semibold text-white hover:bg-zinc-700">
                    Zapisz zmiany
                </button>
            </div>
        </form>

        <form
            method="POST"
            action="{{ route('admin.rental.destroy', $item) }}"
            class="mt-6 flex flex-col gap-3 rounded-2xl border border-red-200 bg-red-50 p-5 sm:flex-row sm:items-center sm:justify-between"
            onsubmit="return confirm('Usunąć ten sprzęt z wypożyczalni? Tej operacji nie można cofnąć.')"
        >
            @csrf
            @method('DELETE')

            <p class="text-sm text-red-800">
                Usuwa sprzęt na stałe. Aby tylko tymczasowo go nie pokazywać, odznacz „Widoczny na stronie”.
            </p>

            <button type="submit" class="shrink-0 rounded-xl border border-red-300 bg-white px-4 py-2 text-sm font-semibold text-red-700 hover:bg-red-100">
                Usuń sprzęt
            </button>
        </form>
    </div>
@endsection
