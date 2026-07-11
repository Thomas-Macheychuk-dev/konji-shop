<div class="mx-auto max-w-[1600px] px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
    <div class="grid gap-6 lg:grid-cols-[280px_minmax(0,1fr)] xl:grid-cols-[300px_minmax(0,1fr)] xl:gap-8">
        <aside class="self-start lg:sticky lg:top-[170px]">
            @include('partials.storefront.category-sidebar')
        </aside>

        <div class="min-w-0 overflow-hidden rounded-[28px] bg-white shadow-[0_12px_45px_rgba(15,23,42,0.04)] ring-1 ring-slate-200/80">
            {{ $slot }}
        </div>
    </div>
</div>
