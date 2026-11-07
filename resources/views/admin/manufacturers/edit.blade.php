@extends('layouts.storefront')

@section('content')
    @php
        $field = 'block w-full rounded-xl border bg-white px-4 py-3 text-sm text-zinc-900 outline-none transition focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100';
        $border = fn (string $name): string => $errors->has($name) ? 'border-red-300 ring-red-100' : 'border-zinc-300';
    @endphp

    <div class="mx-auto max-w-5xl px-4 py-10 sm:px-6 lg:px-8">
        <div class="mb-8">
            <a href="{{ route('admin.manufacturers.index') }}" class="text-sm font-medium text-zinc-500 hover:text-zinc-700">
                ← Wróć do producentów
            </a>

            <h1 class="mt-3 text-3xl font-bold tracking-tight text-zinc-900">{{ $brand->value }}</h1>
            <p class="mt-2 text-sm text-zinc-600">
                Atrybut „{{ $brand->attribute?->name }}” · aktywne produkty: {{ number_format($activeProductCount, 0, ',', ' ') }}
            </p>

            @if ($sampleProducts->isNotEmpty())
                <p class="mt-2 text-sm text-zinc-600">
                    Np.
                    @foreach ($sampleProducts as $product)
                        <a href="{{ route('products.show', $product->slug) }}" target="_blank" class="font-medium text-zinc-700 underline hover:text-zinc-900">{{ $product->name }}</a>@if (! $loop->last), @endif
                    @endforeach
                </p>
            @endif
        </div>

        @if (session('success'))
            <div class="mb-6 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
                {{ session('success') }}
            </div>
        @endif

        <form method="POST" action="{{ route('admin.manufacturers.update', $brand) }}" class="space-y-8 rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm">
            @csrf
            @method('PATCH')

            <fieldset class="space-y-5">
                <legend class="text-lg font-semibold text-zinc-900">Producent</legend>
                <p class="text-sm text-zinc-600">Pełna nazwa firmy z oznakowania lub instrukcji produktu — nie musi być taka sama jak marka (np. marka „Seni”, producent „TZMO SA”).</p>

                <div>
                    <label for="name" class="mb-2 block text-sm font-medium text-zinc-700">Nazwa producenta</label>
                    <input id="name" type="text" name="name" value="{{ old('name', $manufacturer->name) }}" required class="{{ $border('name') }} {{ $field }}">
                    @error('name') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="address" class="mb-2 block text-sm font-medium text-zinc-700">Adres pocztowy</label>
                    <textarea id="address" name="address" rows="3" required class="{{ $border('address') }} {{ $field }}" placeholder="ul. Przykładowa 1&#10;00-000 Miasto&#10;Polska">{{ old('address', $manufacturer->address) }}</textarea>
                    @error('address') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div class="grid gap-4 sm:grid-cols-3">
                    @foreach (['email' => ['E-mail', 'email'], 'website' => ['Strona internetowa', 'url'], 'phone' => ['Telefon (opcjonalnie)', 'text']] as $name => [$label, $type])
                        <div>
                            <label for="{{ $name }}" class="mb-2 block text-sm font-medium text-zinc-700">{{ $label }}</label>
                            <input id="{{ $name }}" type="{{ $type }}" name="{{ $name }}" value="{{ old($name, $manufacturer->{$name}) }}" class="{{ $border($name) }} {{ $field }}">
                            @error($name) <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                    @endforeach
                </div>
                <p class="text-xs text-zinc-500">Wymagany jest e-mail lub strona internetowa.</p>
            </fieldset>

            <fieldset class="space-y-5 border-t border-zinc-100 pt-6">
                <legend class="text-lg font-semibold text-zinc-900">Osoba odpowiedzialna w UE</legend>
                <p class="text-sm text-zinc-600">Tylko gdy producent ma siedzibę poza Unią Europejską (np. importer lub upoważniony przedstawiciel).</p>

                <div>
                    <label for="eu_representative_name" class="mb-2 block text-sm font-medium text-zinc-700">Nazwa</label>
                    <input id="eu_representative_name" type="text" name="eu_representative_name" value="{{ old('eu_representative_name', $manufacturer->eu_representative_name) }}" class="{{ $border('eu_representative_name') }} {{ $field }}">
                    @error('eu_representative_name') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div class="grid gap-4 sm:grid-cols-[2fr_1fr]">
                    <div>
                        <label for="eu_representative_address" class="mb-2 block text-sm font-medium text-zinc-700">Adres pocztowy</label>
                        <textarea id="eu_representative_address" name="eu_representative_address" rows="3" class="{{ $border('eu_representative_address') }} {{ $field }}">{{ old('eu_representative_address', $manufacturer->eu_representative_address) }}</textarea>
                        @error('eu_representative_address') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="eu_representative_email" class="mb-2 block text-sm font-medium text-zinc-700">E-mail</label>
                        <input id="eu_representative_email" type="email" name="eu_representative_email" value="{{ old('eu_representative_email', $manufacturer->eu_representative_email) }}" class="{{ $border('eu_representative_email') }} {{ $field }}">
                        @error('eu_representative_email') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>
            </fieldset>

            <fieldset class="space-y-3 border-t border-zinc-100 pt-6">
                <legend class="text-lg font-semibold text-zinc-900">Ostrzeżenia i informacje dotyczące bezpieczeństwa (opcjonalnie)</legend>
                <p class="text-sm text-zinc-600">Wspólne dla wszystkich produktów marki, np. przeciwwskazania lub ostrzeżenia z instrukcji. Ostrzeżenia dotyczące jednego produktu wpisz w jego opisie.</p>
                <textarea id="safety_information" name="safety_information" rows="5" aria-label="Ostrzeżenia i informacje dotyczące bezpieczeństwa" class="{{ $border('safety_information') }} {{ $field }}">{{ old('safety_information', $manufacturer->safety_information) }}</textarea>
                @error('safety_information') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
            </fieldset>

            <div class="flex justify-end border-t border-zinc-100 pt-6">
                <button type="submit" class="rounded-xl bg-zinc-900 px-5 py-3 text-sm font-semibold text-white hover:bg-zinc-700">
                    Zapisz dane producenta
                </button>
            </div>
        </form>
    </div>
@endsection
