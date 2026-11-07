{{-- GPSR (EU 2023/988, art. 19): manufacturer, EU responsible person and safety information on the listing. --}}
@php
    $contactLines = function (array $party): array {
        return array_values(array_filter([
            filled($party['email'] ?? null) ? ['label' => 'E-mail', 'value' => $party['email'], 'href' => 'mailto:'.$party['email']] : null,
            filled($party['phone'] ?? null) ? ['label' => 'Telefon', 'value' => $party['phone'], 'href' => 'tel:'.preg_replace('/[^\d+]/', '', $party['phone'])] : null,
            filled($party['website'] ?? null) ? ['label' => 'Strona', 'value' => preg_replace('#^https?://(www\.)?#i', '', rtrim($party['website'], '/')), 'href' => $party['website']] : null,
        ]));
    };
@endphp

<section
    aria-labelledby="product-safety-information"
    class="rounded-2xl border border-zinc-200 bg-white p-6 text-sm shadow-sm dark:border-zinc-800 dark:bg-zinc-900 sm:p-8"
    data-test="product-manufacturer-information"
>
    <h2 id="product-safety-information" class="text-lg font-semibold text-zinc-900 dark:text-white">
        Informacje o bezpieczeństwie produktu
    </h2>

    <div class="mt-4 grid gap-6 sm:grid-cols-2">
        <div>
            <p class="text-xs font-bold uppercase tracking-[0.14em] text-zinc-500">Producent</p>
            <p class="mt-2 font-semibold text-zinc-900 dark:text-white">{{ $manufacturer['name'] }}</p>
            <p class="mt-1 whitespace-pre-line leading-6 text-zinc-700 dark:text-zinc-300">{{ $manufacturer['address'] }}</p>

            @foreach ($contactLines($manufacturer) as $line)
                <p class="mt-1 text-zinc-700 dark:text-zinc-300">
                    {{ $line['label'] }}:
                    <a
                        href="{{ $line['href'] }}"
                        class="font-medium text-[#155fa8] hover:underline"
                        @if ($line['label'] === 'Strona') target="_blank" rel="nofollow noopener" @endif
                    >{{ $line['value'] }}</a>
                </p>
            @endforeach
        </div>

        @if ($manufacturer['eu_representative'])
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.14em] text-zinc-500">Osoba odpowiedzialna w UE</p>
                <p class="mt-2 font-semibold text-zinc-900 dark:text-white">{{ $manufacturer['eu_representative']['name'] }}</p>

                @if (filled($manufacturer['eu_representative']['address']))
                    <p class="mt-1 whitespace-pre-line leading-6 text-zinc-700 dark:text-zinc-300">{{ $manufacturer['eu_representative']['address'] }}</p>
                @endif

                @foreach ($contactLines($manufacturer['eu_representative']) as $line)
                    <p class="mt-1 text-zinc-700 dark:text-zinc-300">
                        {{ $line['label'] }}:
                        <a href="{{ $line['href'] }}" class="font-medium text-[#155fa8] hover:underline">{{ $line['value'] }}</a>
                    </p>
                @endforeach
            </div>
        @endif
    </div>

    @if (filled($manufacturer['safety_information']))
        <div class="mt-6 border-t border-zinc-100 pt-5 dark:border-zinc-800">
            <p class="text-xs font-bold uppercase tracking-[0.14em] text-zinc-500">Ostrzeżenia i informacje dotyczące bezpieczeństwa</p>
            <p class="mt-2 whitespace-pre-line leading-6 text-zinc-700 dark:text-zinc-300">{{ $manufacturer['safety_information'] }}</p>
        </div>
    @endif

    <p class="mt-6 text-xs leading-5 text-zinc-500">
        Przed użyciem zapoznaj się z instrukcją i oznakowaniem dołączonymi do produktu.
    </p>
</section>
