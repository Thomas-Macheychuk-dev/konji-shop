@php
    $field = 'block w-full rounded-xl border bg-white px-4 py-3 text-sm text-zinc-900 outline-none transition focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100';
    $border = fn (string $name): string => $errors->has($name) ? 'border-red-300 ring-red-100' : 'border-zinc-300';
@endphp

<div class="grid gap-6 lg:grid-cols-[1fr_280px]">
    <div class="space-y-6">
        <div>
            <label for="name" class="mb-2 block text-sm font-medium text-zinc-700">Nazwa</label>
            <input id="name" type="text" name="name" value="{{ old('name', $item->name) }}" required class="{{ $border('name') }} {{ $field }}">
            @error('name')
            <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="group" class="mb-2 block text-sm font-medium text-zinc-700">Rodzaj sprzętu (sekcja na stronie)</label>
            <select id="group" name="group" class="{{ $border('group') }} {{ $field }}">
                @foreach (\App\Models\RentalItem::GROUPS as $key => $heading)
                    <option value="{{ $key }}" @selected(old('group', $item->group) === $key)>{{ $heading }}</option>
                @endforeach
            </select>
            @error('group')
            <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="details" class="mb-2 block text-sm font-medium text-zinc-700">Opis (opcjonalnie)</label>
            <textarea id="details" name="details" rows="4" class="{{ $border('details') }} {{ $field }}">{{ old('details', $item->details) }}</textarea>
            @error('details')
            <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <fieldset class="grid gap-4 sm:grid-cols-2">
            <legend class="mb-2 text-sm font-medium text-zinc-700">Kaucja zwrotna (zł) — puste pole „od” oznacza „Bez kaucji”</legend>
            @foreach (['deposit_min' => 'Od', 'deposit_max' => 'Do (opcjonalnie, gdy cena zależy od modelu)'] as $name => $label)
                <div>
                    <label for="{{ $name }}" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-zinc-500">{{ $label }}</label>
                    <input id="{{ $name }}" type="number" min="0" step="1" name="{{ $name }}" value="{{ old($name, $item->{$name}) }}" class="{{ $border($name) }} {{ $field }}">
                    @error($name)
                    <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>
            @endforeach
        </fieldset>

        <fieldset class="grid gap-4 sm:grid-cols-2">
            <legend class="mb-2 text-sm font-medium text-zinc-700">Opłata miesięczna (zł)</legend>
            @foreach (['monthly_min' => 'Od', 'monthly_max' => 'Do (opcjonalnie, gdy cena zależy od modelu)'] as $name => $label)
                <div>
                    <label for="{{ $name }}" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-zinc-500">{{ $label }}</label>
                    <input id="{{ $name }}" type="number" min="0" step="1" name="{{ $name }}" value="{{ old($name, $item->{$name}) }}" @required($name === 'monthly_min') class="{{ $border($name) }} {{ $field }}">
                    @error($name)
                    <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>
            @endforeach
        </fieldset>

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label for="sort_order" class="mb-2 block text-sm font-medium text-zinc-700">Kolejność</label>
                <input id="sort_order" type="number" min="0" step="1" name="sort_order" value="{{ old('sort_order', $item->sort_order) }}" class="{{ $border('sort_order') }} {{ $field }}">
                <p class="mt-2 text-xs text-zinc-500">Mniejsza liczba = wyżej w sekcji. Puste pole przy dodawaniu ustawia sprzęt na końcu.</p>
                @error('sort_order')
                <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div class="space-y-3 pt-7">
                <label class="flex items-center gap-3 text-sm font-medium text-zinc-700">
                    <input type="hidden" name="is_active" value="0">
                    <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $item->is_active)) class="h-4 w-4 rounded border-zinc-300">
                    Widoczny na stronie
                </label>

                <label class="flex items-center gap-3 text-sm font-medium text-zinc-700">
                    <input type="hidden" name="nfz_purchase" value="0">
                    <input type="checkbox" name="nfz_purchase" value="1" @checked(old('nfz_purchase', $item->nfz_purchase)) class="h-4 w-4 rounded border-zinc-300">
                    Plakietka „Możliwy zakup z dofinansowaniem NFZ”
                </label>
            </div>
        </div>
    </div>

    <div>
        <span class="mb-2 block text-sm font-medium text-zinc-700">Zdjęcie</span>

        <div class="flex aspect-[4/3] items-center justify-center rounded-xl border border-zinc-200 bg-white p-4">
            @if ($item->imageUrl())
                <img src="{{ $item->imageUrl() }}" alt="{{ $item->name }}" class="max-h-full max-w-full object-contain">
            @else
                <span class="text-sm text-zinc-400">Brak zdjęcia</span>
            @endif
        </div>

        <label for="image" class="mt-4 mb-2 block text-xs font-semibold uppercase tracking-wide text-zinc-500">
            {{ $item->image_path ? 'Zamień zdjęcie' : 'Dodaj zdjęcie' }}
        </label>
        <input id="image" type="file" name="image" accept="image/jpeg,image/png,image/webp" class="block w-full text-sm text-zinc-700 file:mr-3 file:rounded-lg file:border-0 file:bg-zinc-100 file:px-3 file:py-2 file:text-sm file:font-semibold">
        <p class="mt-2 text-xs text-zinc-500">JPG, PNG lub WebP, do 5 MB. Najlepiej produkt na białym tle.</p>
        @error('image')
        <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
        @enderror

        @if ($item->image_path)
            <label class="mt-4 flex items-center gap-3 text-sm text-zinc-700">
                <input type="checkbox" name="remove_image" value="1" class="h-4 w-4 rounded border-zinc-300">
                Usuń zdjęcie
            </label>
        @endif
    </div>
</div>
