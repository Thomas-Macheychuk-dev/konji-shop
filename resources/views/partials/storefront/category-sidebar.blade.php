@php
    $sidebarCategories = $storefrontSidebarCategories ?? collect();
    $currentCategory = request()->route('category');
    $currentCategoryId = $currentCategory instanceof \App\Models\Category
        ? (int) $currentCategory->id
        : null;

    $containsCurrentCategory = function (\App\Models\Category $category) use (&$containsCurrentCategory, $currentCategoryId): bool {
        if ($currentCategoryId !== null && (int) $category->id === $currentCategoryId) {
            return true;
        }

        if (! $category->relationLoaded('children')) {
            return false;
        }

        return $category->children->contains(
            fn (\App\Models\Category $child): bool => $containsCurrentCategory($child),
        );
    };
@endphp

<div data-category-sidebar>
    <div class="lg:hidden" x-data="{ open: false }">
        <button
            type="button"
            class="flex w-full items-center justify-between rounded-2xl border border-slate-200 bg-white px-4 py-3.5 text-left shadow-sm"
            :aria-expanded="open.toString()"
            aria-controls="storefront-mobile-category-tree"
            @click="open = ! open"
        >
            <span class="flex items-center gap-3">
                <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-blue-50 text-[#155fa8]">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path stroke-linecap="round" d="M4 6h16M4 12h16M4 18h16" />
                    </svg>
                </span>
                <span>
                    <strong class="block text-sm font-bold text-slate-900">Kategorie produktów</strong>
                    <span class="block text-xs text-slate-500">Rozwiń listę kategorii</span>
                </span>
            </span>

            <svg class="h-5 w-5 text-slate-400 transition-transform" :class="{ 'rotate-180': open }" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6" />
            </svg>
        </button>

        <div
            id="storefront-mobile-category-tree"
            x-cloak
            x-show="open"
            class="mt-3 rounded-2xl border border-slate-200 bg-white p-3 shadow-sm"
        >
            @if ($sidebarCategories->isNotEmpty())
                <ul class="space-y-1" aria-label="Kategorie produktów">
                    @foreach ($sidebarCategories as $category)
                        @include('partials.storefront.category-sidebar-item', [
                            'category' => $category,
                            'currentCategoryId' => $currentCategoryId,
                            'containsCurrentCategory' => $containsCurrentCategory,
                            'sidebarIdPrefix' => 'mobile',
                            'level' => 0,
                        ])
                    @endforeach
                </ul>
            @else
                <p class="rounded-xl bg-slate-50 px-4 py-5 text-sm text-slate-500">
                    Brak dostępnych kategorii.
                </p>
            @endif
        </div>
    </div>

    <div class="hidden overflow-hidden rounded-[24px] border border-slate-200 bg-white shadow-[0_14px_45px_rgba(15,23,42,0.06)] lg:block">
        <div class="border-b border-slate-100 bg-gradient-to-br from-blue-50 to-white px-5 py-5">
            <span class="flex h-11 w-11 items-center justify-center rounded-2xl bg-[#155fa8] text-white shadow-sm">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                    <path stroke-linecap="round" d="M4 6h16M4 12h16M4 18h16" />
                </svg>
            </span>
            <h2 class="mt-4 text-lg font-extrabold tracking-tight text-slate-950">Kategorie produktów</h2>
            <p class="mt-1 text-xs leading-5 text-slate-500">
                Kliknij kategorię, aby rozwinąć dostępne podkategorie.
            </p>
        </div>

        <div
            class="relative py-2 pr-1.5"
            x-data="{
                atTop: true,
                atBottom: true,
                sync(list) {
                    this.atTop = list.scrollTop <= 1;
                    this.atBottom = list.scrollTop + list.clientHeight >= list.scrollHeight - 1;
                },
            }"
            x-init="sync($refs.list); new ResizeObserver(() => sync($refs.list)).observe($refs.list.firstElementChild ?? $refs.list)"
            @resize.window.debounce.100ms="sync($refs.list)"
        >
            <nav
                x-ref="list"
                class="category-sidebar-scroll max-h-[calc(100vh-430px)] overflow-y-auto py-1 pl-3 pr-1.5"
                aria-label="Kategorie produktów"
                @scroll.passive="sync($el)"
            >
                @if ($sidebarCategories->isNotEmpty())
                    <ul class="space-y-1">
                        @foreach ($sidebarCategories as $category)
                            @include('partials.storefront.category-sidebar-item', [
                                'category' => $category,
                                'currentCategoryId' => $currentCategoryId,
                                'containsCurrentCategory' => $containsCurrentCategory,
                                'sidebarIdPrefix' => 'desktop',
                                'level' => 0,
                            ])
                        @endforeach
                    </ul>
                @else
                    <p class="rounded-xl bg-slate-50 px-4 py-5 text-sm text-slate-500">
                        Brak dostępnych kategorii.
                    </p>
                @endif
            </nav>

            {{-- Fades hint that the list scrolls; they stay clear of the scrollbar. --}}
            <div
                class="pointer-events-none absolute inset-x-0 top-2 right-4 h-8 bg-gradient-to-b from-white to-transparent transition-opacity duration-200"
                :class="atTop ? 'opacity-0' : 'opacity-100'"
                aria-hidden="true"
            ></div>
            <div
                class="pointer-events-none absolute inset-x-0 bottom-2 right-4 h-12 bg-gradient-to-t from-white to-transparent transition-opacity duration-200"
                :class="atBottom ? 'opacity-0' : 'opacity-100'"
                aria-hidden="true"
            ></div>
        </div>

        <div class="border-t border-slate-100 bg-slate-50/70 p-3">
            <a
                href="{{ route('home') }}#categories"
                class="flex items-center justify-between rounded-xl px-3 py-2.5 text-sm font-bold text-[#155fa8] transition hover:bg-white"
            >
                Zobacz wszystkie działy
                <span aria-hidden="true">→</span>
            </a>
        </div>
    </div>
</div>
