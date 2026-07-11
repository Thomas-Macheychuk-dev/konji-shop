@props(['product'])

@php
    $displayVariant = $product->variants->firstWhere('is_default', true)
        ?? $product->variants->first();
    $grossPriceAmount = $displayVariant?->grossPriceAmount();
    $currency = $displayVariant?->currency?->value ?? 'PLN';
    $imageUrl = $product->default_image_url;
    $categoryName = $product->categories->first()?->name;
    $isAvailable = $displayVariant?->stock_status?->isInStock() ?? false;
@endphp

<a
    href="{{ route('products.show', $product->slug) }}"
    class="group flex h-full min-w-0 flex-col overflow-hidden rounded-[22px] border border-slate-200 bg-white shadow-[0_8px_30px_rgba(15,23,42,0.05)] transition duration-300 hover:-translate-y-1 hover:border-blue-200 hover:shadow-[0_18px_45px_rgba(21,95,168,0.12)]"
>
    <div class="relative aspect-square overflow-hidden bg-gradient-to-br from-slate-50 to-blue-50/60 p-5">
        @if ($imageUrl)
            <img
                src="{{ $imageUrl }}"
                alt="{{ $product->name }}"
                class="h-full w-full object-contain transition duration-500 group-hover:scale-[1.04]"
                loading="lazy"
            >
        @else
            <div class="flex h-full flex-col items-center justify-center rounded-2xl border border-dashed border-slate-200 bg-white/70 px-6 text-center">
                <svg class="h-12 w-12 text-blue-200" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" aria-hidden="true">
                    <rect x="3" y="3" width="18" height="18" rx="3" />
                    <circle cx="8.5" cy="8.5" r="1.5" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="m5 17 4.5-4.5 3 3L15 13l4 4" />
                </svg>
                <span class="mt-3 text-xs font-medium text-slate-400">Zdjęcie produktu w przygotowaniu</span>
            </div>
        @endif

        @if ($isAvailable)
            <span class="absolute left-4 top-4 inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2.5 py-1 text-[11px] font-bold text-emerald-700 shadow-sm ring-1 ring-emerald-100">
                <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                Dostępny
            </span>
        @endif
    </div>

    <div class="flex flex-1 flex-col p-5">
        @if (filled($categoryName))
            <p class="text-[11px] font-bold uppercase tracking-[0.14em] text-[#1674c4]">
                {{ $categoryName }}
            </p>
        @endif

        <h3 class="mt-2 line-clamp-2 text-base font-bold leading-snug text-slate-900 transition group-hover:text-[#155fa8]">
            {{ $product->name }}
        </h3>

        @if (filled($product->short_description))
            <p class="mt-2 line-clamp-2 text-sm leading-6 text-slate-500">
                {{ strip_tags($product->short_description) }}
            </p>
        @endif

        <div class="mt-auto flex items-end justify-between gap-3 pt-5">
            <div>
                <p class="text-[11px] font-medium text-slate-400">Cena brutto</p>
                @if ($grossPriceAmount !== null)
                    <p class="mt-0.5 text-lg font-extrabold tracking-tight text-slate-950">
                        {{ number_format($grossPriceAmount / 100, 2, ',', ' ') }} {{ $currency }}
                    </p>
                @else
                    <p class="mt-0.5 text-sm font-semibold text-slate-500">
                        Cena niedostępna
                    </p>
                @endif
            </div>

            <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-blue-50 text-[#155fa8] transition group-hover:bg-[#155fa8] group-hover:text-white" aria-hidden="true">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m9 18 6-6-6-6" />
                </svg>
            </span>
        </div>
    </div>
</a>
