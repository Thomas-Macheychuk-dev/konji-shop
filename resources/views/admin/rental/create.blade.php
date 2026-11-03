@extends('layouts.storefront')

@section('content')
    <div class="mx-auto max-w-5xl px-4 py-10 sm:px-6 lg:px-8">
        <div class="mb-8">
            <a href="{{ route('admin.rental.index') }}" class="text-sm font-medium text-zinc-500 hover:text-zinc-700">
                ← Wróć do wypożyczalni
            </a>

            <h1 class="mt-3 text-3xl font-bold tracking-tight text-zinc-900">Dodaj sprzęt</h1>
        </div>

        <form
            method="POST"
            action="{{ route('admin.rental.store') }}"
            enctype="multipart/form-data"
            class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm"
        >
            @csrf

            @include('admin.rental._form')

            <div class="mt-8 flex justify-end border-t border-zinc-100 pt-6">
                <button type="submit" class="rounded-xl bg-zinc-900 px-5 py-3 text-sm font-semibold text-white hover:bg-zinc-700">
                    Dodaj sprzęt
                </button>
            </div>
        </form>
    </div>
@endsection
