@extends('layouts.storefront')

@section('content')
    <div class="mx-auto max-w-4xl px-4 py-10 sm:px-6 lg:px-8">
        <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm sm:p-8">
            <div class="border-b border-zinc-200 pb-6">
                <p class="text-sm font-medium text-zinc-500">{{ $eyebrow ?? 'Informacje prawne' }}</p>
                <h1 class="mt-2 text-3xl font-bold tracking-tight text-zinc-900">{{ $title }}</h1>
                @if (! empty($version))
                    <p class="mt-2 text-sm text-zinc-500">Wersja: {{ $version }}</p>
                @endif
            </div>
            <div class="prose prose-zinc mt-8 max-w-none">{{ $slot }}</div>
        </div>
    </div>
@endsection
