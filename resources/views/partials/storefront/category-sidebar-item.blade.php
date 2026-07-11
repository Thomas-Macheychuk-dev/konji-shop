@php
    $children = $category->relationLoaded('children')
        ? $category->children
        : collect();
    $hasChildren = $children->isNotEmpty();
    $isCurrent = $currentCategoryId !== null && (int) $category->id === $currentCategoryId;
    $containsCurrent = $containsCurrentCategory($category);
    $openByDefault = $hasChildren && $containsCurrent;
    $itemId = $sidebarIdPrefix.'-category-'.$category->id;
    $indentClass = match (true) {
        $level >= 3 => 'pl-8',
        $level === 2 => 'pl-6',
        $level === 1 => 'pl-4',
        default => '',
    };
@endphp

<li
    @if ($hasChildren) x-data="{ open: @js($openByDefault) }" @endif
    data-category-item="{{ $category->id }}"
    data-category-open-by-default="{{ $openByDefault ? 'true' : 'false' }}"
>
    @if ($hasChildren)
        <button
            type="button"
            class="group flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left transition {{ $indentClass }} {{ $containsCurrent ? 'bg-blue-50 text-[#155fa8]' : 'text-slate-700 hover:bg-slate-50 hover:text-[#155fa8]' }}"
            data-category-toggle="{{ $category->id }}"
            aria-controls="{{ $itemId }}"
            :aria-expanded="open.toString()"
            @click="open = ! open"
        >
            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg {{ $containsCurrent ? 'bg-white text-[#155fa8] shadow-sm' : 'bg-slate-100 text-slate-500 group-hover:bg-white group-hover:text-[#155fa8]' }}">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h6l2 2h8v10H4z" />
                </svg>
            </span>

            <span class="min-w-0 flex-1 truncate text-sm font-semibold">
                {{ $category->name }}
            </span>

            <svg
                class="h-4 w-4 shrink-0 text-slate-400 transition-transform duration-200"
                :class="{ 'rotate-90 text-[#155fa8]': open }"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="2"
                aria-hidden="true"
            >
                <path stroke-linecap="round" stroke-linejoin="round" d="m9 18 6-6-6-6" />
            </svg>
        </button>

        <div
            id="{{ $itemId }}"
            x-cloak
            x-show="open"
            class="mt-1"
        >
            <a
                href="{{ route('categories.show', $category->slug) }}"
                class="ml-11 flex items-center justify-between rounded-lg px-3 py-2 text-xs font-semibold {{ $isCurrent ? 'bg-[#155fa8] text-white' : 'text-[#155fa8] hover:bg-blue-50' }}"
                data-category-link="{{ $category->id }}"
            >
                <span>Wszystkie: {{ $category->name }}</span>
                <span aria-hidden="true">→</span>
            </a>

            <ul class="mt-1 space-y-1 border-l border-slate-200 pl-2">
                @foreach ($children as $childCategory)
                    @include('partials.storefront.category-sidebar-item', [
                        'category' => $childCategory,
                        'currentCategoryId' => $currentCategoryId,
                        'containsCurrentCategory' => $containsCurrentCategory,
                        'sidebarIdPrefix' => $sidebarIdPrefix,
                        'level' => $level + 1,
                    ])
                @endforeach
            </ul>
        </div>
    @else
        <a
            href="{{ route('categories.show', $category->slug) }}"
            class="group flex items-center gap-3 rounded-xl px-3 py-2.5 transition {{ $indentClass }} {{ $isCurrent ? 'bg-[#155fa8] text-white shadow-sm' : 'text-slate-700 hover:bg-slate-50 hover:text-[#155fa8]' }}"
            data-category-link="{{ $category->id }}"
        >
            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg {{ $isCurrent ? 'bg-white/15 text-white' : 'bg-slate-100 text-slate-500 group-hover:bg-white group-hover:text-[#155fa8]' }}">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 5h14v14H5zM8 9h8M8 13h5" />
                </svg>
            </span>

            <span class="min-w-0 flex-1 truncate text-sm font-semibold">
                {{ $category->name }}
            </span>

            <span class="text-sm {{ $isCurrent ? 'text-white/80' : 'text-slate-300 group-hover:text-[#155fa8]' }}" aria-hidden="true">→</span>
        </a>
    @endif
</li>
