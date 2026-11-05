@props(['categories'])

<div class="mt-9 grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
    @foreach ($categories as $category)
        <a
            href="{{ route('categories.show', $category->slug) }}"
            class="group relative min-h-[230px] overflow-hidden rounded-[24px] border border-slate-200 bg-white p-6 shadow-[0_8px_30px_rgba(15,23,42,0.04)] transition duration-300 hover:-translate-y-1 hover:border-blue-200 hover:shadow-[0_20px_50px_rgba(21,95,168,0.11)]"
        >
            <div class="absolute -right-8 -top-8 h-32 w-32 rounded-full bg-blue-50 transition duration-300 group-hover:scale-125 group-hover:bg-blue-100"></div>
            <div class="relative">
                <span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-[#155fa8] text-white shadow-lg shadow-blue-200/70">
                    @switch($loop->index % 4)
                        @case(0)
                            <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 2v7m0 4v9M8 7c2 2 6 2 8 0M8 17c2-2 6-2 8 0" /></svg>
                            @break
                        @case(1)
                            <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 4h14v16H5zM8 8h8M8 12h8M8 16h5" /></svg>
                            @break
                        @case(2)
                            <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16c4-1 6-4 7-8 1 5 4 8 9 9M5 20h14" /></svg>
                            @break
                        @default
                            <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v18M3 12h18M5.6 5.6l12.8 12.8M18.4 5.6 5.6 18.4" /></svg>
                    @endswitch
                </span>

                <h3 class="mt-7 text-xl font-extrabold tracking-tight text-slate-900 transition group-hover:text-[#155fa8]">
                    {{ $category->name }}
                </h3>

                <p class="mt-3 line-clamp-2 text-sm leading-6 text-slate-500">
                    {{ filled($category->description) ? strip_tags($category->description) : 'Zobacz dostępne produkty i warianty w tej kategorii.' }}
                </p>

                <div class="mt-6 flex items-center justify-between gap-3">
                    <span class="text-xs font-semibold text-slate-500">
                        {{ $category->active_products_count }} {{ trans_choice('produkt|produkty|produktów', $category->active_products_count) }}
                    </span>
                    <span class="inline-flex items-center gap-1 text-sm font-bold text-[#155fa8]">
                        Zobacz
                        <span class="transition group-hover:translate-x-1" aria-hidden="true">→</span>
                    </span>
                </div>
            </div>
        </a>
    @endforeach
</div>
