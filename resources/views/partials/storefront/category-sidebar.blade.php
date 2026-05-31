@php
    $sidebarCategories = $storefrontSidebarCategories ?? collect();
    $currentCategory = request()->route('category');
    $currentCategorySlug = $currentCategory instanceof \App\Models\Category
        ? $currentCategory->slug
        : null;
@endphp

<div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
    <div class="mb-4">
        <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">
            Categories
        </h2>
    </div>

    @if ($sidebarCategories->isNotEmpty())
        <nav class="flex flex-col gap-2">
            @foreach ($sidebarCategories as $category)
                @php
                    $isActive = $currentCategorySlug === $category->slug;
                @endphp

                <a
                    href="{{ route('categories.show', $category->slug) }}"
                    @class([
                        'rounded-lg px-3 py-2 text-m transition',
                        'bg-zinc-900 text-white dark:bg-white dark:text-zinc-900' => $isActive,
                        'text-zinc-700 hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-300 dark:hover:bg-zinc-800 dark:hover:text-white' => ! $isActive,
                    ])
                >
                    {{ $category->name }}
                </a>
            @endforeach
        </nav>
    @else
        <p class="text-sm text-zinc-500 dark:text-zinc-400">
            No categories available yet.
        </p>
    @endif
</div>
