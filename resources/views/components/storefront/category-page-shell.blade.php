@props(['wide' => false])

{{-- "wide" gives the content column 15% more room once the page hits its max width. --}}
<div class="mx-auto {{ $wide ? 'max-w-[1772px]' : 'max-w-[1600px]' }} px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
    <div class="grid gap-6 lg:grid-cols-[336px_minmax(0,1fr)] xl:grid-cols-[360px_minmax(0,1fr)] xl:gap-8">
        <aside class="self-start lg:sticky lg:top-[170px]">
            @include('partials.storefront.category-sidebar')
        </aside>

        <div class="min-w-0 overflow-hidden rounded-[28px] bg-white shadow-[0_12px_45px_rgba(15,23,42,0.04)] ring-1 ring-slate-200/80">
            {{ $slot }}
        </div>
    </div>
</div>
