@extends('layouts.storefront')

@section('content')
    <section class="mb-12">
        <div class="rounded-2xl border border-zinc-200 bg-white p-8 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
            <p class="mb-2 text-sm font-medium uppercase tracking-wide text-zinc-500">
                Konji Shop
            </p>

            <h1 class="text-4xl font-bold tracking-tight text-zinc-900 dark:text-white">
                Support, recovery, and everyday comfort
            </h1>

            <p class="mt-4 max-w-2xl text-base text-zinc-600 dark:text-zinc-300">
                Browse trusted products designed for comfort, mobility, and daily support.
            </p>

            <div class="mt-6 flex flex-wrap gap-3">
                <a
                    href="#deals"
                    class="inline-flex items-center rounded-lg bg-zinc-900 px-5 py-3 text-sm font-medium text-white transition hover:bg-zinc-800 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-200"
                >
                    View deals
                </a>
            </div>
        </div>
    </section>

    <section id="deals">
        <div class="mb-6 flex items-center justify-between">
            <h2 class="text-2xl font-semibold text-zinc-900 dark:text-white">Deals</h2>
        </div>

        @forelse ($deals as $deal)
            <div class="mb-3 rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                {{ $deal->title }}
            </div>
        @empty
            <div class="rounded-2xl border border-dashed border-zinc-300 bg-zinc-50 p-6 text-zinc-600 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300">
                No deals available yet.
            </div>
        @endforelse
    </section>
@endsection
