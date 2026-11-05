@props([
    'action',
    'filters',
    'sorts',
    'brands' => [],
    'searchQuery' => null,
])

@php
    $activeCount = ($filters['price_min'] !== null || $filters['price_max'] !== null ? 1 : 0)
        + count($filters['brands'])
        + ($filters['available'] ? 1 : 0);
    $resetUrl = $searchQuery !== null ? $action.'?'.http_build_query(['q' => $searchQuery]) : $action;
    $priceValue = static fn (?int $amount): string => $amount === null ? '' : rtrim(rtrim(number_format($amount / 100, 2, '.', ''), '0'), '.');
@endphp

<form
    method="GET"
    action="{{ $action }}"
    class="mb-7 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm"
    x-data
>
    @if ($searchQuery !== null)
        <input type="hidden" name="q" value="{{ $searchQuery }}">
    @endif

    <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div class="grid grid-cols-2 gap-3 sm:flex sm:flex-wrap sm:items-end">
            <label class="col-span-2 block sm:col-span-1">
                <span class="mb-1 block text-xs font-semibold text-slate-600">Sortuj</span>
                <select
                    name="sort"
                    x-on:change="$el.form.requestSubmit()"
                    class="block w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900 sm:w-56"
                >
                    @foreach ($sorts as $value => $label)
                        <option value="{{ $value }}" @selected($filters['sort'] === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>

            <label class="block">
                <span class="mb-1 block text-xs font-semibold text-slate-600">Cena od (zł)</span>
                <input type="number" name="cena_od" min="0" step="1" inputmode="numeric" value="{{ $priceValue($filters['price_min']) }}" class="block w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm sm:w-28">
            </label>

            <label class="block">
                <span class="mb-1 block text-xs font-semibold text-slate-600">Cena do (zł)</span>
                <input type="number" name="cena_do" min="0" step="1" inputmode="numeric" value="{{ $priceValue($filters['price_max']) }}" class="block w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm sm:w-28">
            </label>

            @if ($brands !== [])
                <details class="relative col-span-2 sm:col-span-1" @if ($filters['brands'] !== []) open @endif>
                    <summary class="cursor-pointer list-none rounded-xl border border-slate-300 px-3 py-2.5 text-sm text-slate-700">
                        Producent
                        @if ($filters['brands'] !== [])
                            <span class="ml-1 rounded-full bg-blue-50 px-2 py-0.5 text-xs font-bold text-[#155fa8]">{{ count($filters['brands']) }}</span>
                        @endif
                    </summary>
                    <div class="z-10 mt-2 max-h-64 w-full overflow-y-auto rounded-xl border border-slate-200 bg-white p-3 shadow-lg sm:absolute sm:w-64">
                        @foreach ($brands as $brand)
                            <label class="flex items-center justify-between gap-3 py-1 text-sm text-slate-700">
                                <span class="flex items-center gap-2">
                                    <input type="checkbox" name="marka[]" value="{{ $brand['slug'] }}" @checked(in_array($brand['slug'], $filters['brands'], true)) class="h-4 w-4 rounded border-slate-300">
                                    {{ $brand['name'] }}
                                </span>
                                <span class="text-xs text-slate-400">{{ $brand['count'] }}</span>
                            </label>
                        @endforeach
                    </div>
                </details>
            @endif

            <label class="col-span-2 flex items-center gap-2 py-2.5 text-sm text-slate-700 sm:col-span-1">
                <input type="checkbox" name="dostepne" value="1" @checked($filters['available']) class="h-4 w-4 rounded border-slate-300">
                Tylko dostępne
            </label>
        </div>

        <div class="flex items-center gap-3">
            @if ($activeCount > 0 || $filters['sort'] !== array_key_first($sorts))
                <a href="{{ $resetUrl }}" class="text-sm font-semibold text-slate-500 hover:text-slate-900">Wyczyść filtry</a>
            @endif

            <button type="submit" class="rounded-xl bg-[#155fa8] px-5 py-2.5 text-sm font-bold text-white transition hover:bg-[#0b3b70]">
                Pokaż wyniki
            </button>
        </div>
    </div>
</form>
