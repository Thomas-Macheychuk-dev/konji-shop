@extends('layouts.storefront')

@section('content')
    <div class="mx-auto max-w-4xl px-4 py-10 sm:px-6 lg:px-8">
        <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm sm:p-8">
            <div class="border-b border-zinc-200 pb-6">
                <p class="text-sm font-medium text-zinc-500">
                    {{ $eyebrow ?? 'Informacje prawne' }}
                </p>

                <h1 class="mt-2 text-3xl font-bold tracking-tight text-zinc-900">
                    {{ $title }}
                </h1>

                @if (! empty($version))
                    <p class="mt-2 text-sm text-zinc-500">
                        Version: {{ $version }}
                    </p>
                @endif

                <p class="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                    Ta strona zawiera roboczy tekst operacyjny sklepu. Przed użyciem produkcyjnym skonsultuj go z wykwalifikowanym doradcą prawnym lub księgowym.
                </p>
            </div>

            <div class="prose prose-zinc mt-8 max-w-none">
                {{ $slot }}
            </div>
        </div>
    </div>
@endsection
