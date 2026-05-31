@extends('layouts.storefront')

@section('content')
    <section class="mb-12">
        <div class="rounded-2xl border border-zinc-200 bg-white p-8 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
            <p class="mb-2 text-sm font-medium uppercase tracking-wide text-zinc-500">
                Konji Shop
            </p>

            <h1 class="text-4xl font-bold tracking-tight text-zinc-900 dark:text-white">
                Medical clothing, orthopedic supports and recovery products
            </h1>

            <p class="mt-4 max-w-3xl text-base leading-7 text-zinc-600 dark:text-zinc-300">
                Browse medical clothing, orthopedic braces, supports and recovery products selected for comfort, mobility and everyday use.
            </p>

            <div class="mt-6 flex flex-wrap gap-3">
                <a
                    href="#categories"
                    class="inline-flex items-center rounded-lg bg-zinc-900 px-5 py-3 text-sm font-medium text-white transition hover:bg-zinc-800 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-200"
                >
                    Browse categories
                </a>

                <a
                    href="#deals"
                    class="inline-flex items-center rounded-lg border border-zinc-300 px-5 py-3 text-sm font-medium text-zinc-800 transition hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-200 dark:hover:bg-zinc-800"
                >
                    View deals
                </a>
            </div>
        </div>
    </section>

    <section id="categories" class="mb-12">
        <div class="mb-6 flex items-center justify-between">
            <h2 class="text-2xl font-semibold text-zinc-900 dark:text-white">Shop by category</h2>
        </div>

        @if ($categories->isNotEmpty())
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($categories as $category)
                    <a
                        href="{{ route('categories.show', $category->slug) }}"
                        class="group rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md dark:border-zinc-800 dark:bg-zinc-900"
                    >
                        <h3 class="text-lg font-semibold text-zinc-900 group-hover:text-zinc-700 dark:text-white dark:group-hover:text-zinc-200">
                            {{ $category->name }}
                        </h3>

                        @if (filled($category->description))
                            <p class="mt-2 line-clamp-2 text-sm leading-6 text-zinc-600 dark:text-zinc-300">
                                {{ strip_tags($category->description) }}
                            </p>
                        @else
                            <p class="mt-2 text-sm leading-6 text-zinc-500 dark:text-zinc-400">
                                View products in this category.
                            </p>
                        @endif
                    </a>
                @endforeach
            </div>
        @else
            <div class="rounded-2xl border border-dashed border-zinc-300 bg-zinc-50 p-6 text-zinc-600 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300">
                No categories available yet.
            </div>
        @endif
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
