@extends('layouts.storefront')

@section('content')
    <div class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
        <div class="mb-8">
            <a href="{{ route('admin.products.index') }}" class="text-sm font-medium text-zinc-500 hover:text-zinc-700">
                ← Wróć do produktów
            </a>

            <div class="mt-3 flex items-start justify-between gap-4">
                <div>
                    <h1 class="text-3xl font-bold tracking-tight text-zinc-900">
                        {{ $product->name }}
                    </h1>

                    <p class="mt-2 text-sm text-zinc-600">
                        Edytuj dane produktu, domyślne zdjęcie, ceny i dane paczek wariantów.
                    </p>
                </div>

                <a
                    href="{{ route('products.show', $product) }}"
                    class="rounded-xl border border-zinc-300 px-4 py-2 text-sm font-semibold text-zinc-700 hover:bg-zinc-50"
                >
                    Zobacz produkt
                </a>
            </div>
        </div>

        @if (session('success'))
            <div class="mb-6 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
                {{ session('success') }}
            </div>
        @endif

        @php
            $pricedDraftVariantCount = $product->variants
                ->filter(fn ($variant) => $variant->status?->isDraft() === true
                    && $variant->price_net_amount !== null
                    && $variant->currency !== null
                    && $variant->vat_rate !== null)
                ->count();

            $unpricedDraftVariantCount = $product->variants
                ->filter(fn ($variant) => $variant->status?->isDraft() === true
                    && ($variant->price_net_amount === null
                        || $variant->currency === null
                        || $variant->vat_rate === null))
                ->count();

            $currentDefaultImage = $product->selectedDefaultImage();
            $currentDefaultImageSelection = old('default_image');

            if (! $currentDefaultImageSelection && $currentDefaultImage instanceof \App\Models\ProductImage) {
                $currentDefaultImageSelection = \App\Models\Product::DEFAULT_IMAGE_TYPE_PRODUCT_IMAGE . ':' . $currentDefaultImage->id;
            }

            if (! $currentDefaultImageSelection && $currentDefaultImage instanceof \App\Models\ProductAttributeValueImage) {
                $currentDefaultImageSelection = \App\Models\Product::DEFAULT_IMAGE_TYPE_ATTRIBUTE_VALUE_IMAGE . ':' . $currentDefaultImage->id;
            }

            $hasSelectableImages = $product->images->isNotEmpty() || $product->attributeValueImages->isNotEmpty();
            $currentPrimaryKategoria = $product->categories->first(fn ($category): bool => (bool) $category->pivot->is_primary);
            $currentKategoriaId = old('category_id', $currentPrimaryKategoria?->id ?? $product->categories->first()?->id);
        @endphp

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm">
                <h2 class="text-lg font-semibold text-zinc-900">Szczegóły produktu</h2>

                <form
                    method="POST"
                    action="{{ route('admin.products.update', $product) }}"
                    class="mt-4 space-y-5"
                >
                    @csrf
                    @method('PATCH')

                    <div>
                        <label for="name" class="mb-2 block text-sm font-medium text-zinc-700">
                            Nazwa produktu
                        </label>

                        <input
                            id="name"
                            type="text"
                            name="name"
                            value="{{ old('name', $product->name) }}"
                            required
                            maxlength="255"
                            class="@error('name') border-red-300 ring-red-100 @else border-zinc-300 @enderror block w-full rounded-xl border bg-white px-4 py-3 text-sm text-zinc-900 outline-none transition focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100"
                        >

                        @error('name')
                        <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="status" class="mb-2 block text-sm font-medium text-zinc-700">
                            Status produktu
                        </label>

                        <select
                            id="status"
                            name="status"
                            required
                            class="@error('status') border-red-300 ring-red-100 @else border-zinc-300 @enderror block w-full rounded-xl border bg-white px-4 py-3 text-sm text-zinc-900 outline-none transition focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100"
                        >
                            @foreach (\App\Enums\ProductStatus::cases() as $status)
                                <option
                                    value="{{ $status->value }}"
                                    @selected(old('status', $product->status->value) === $status->value)
                                >
                                    {{ $status->label() }}
                                </option>
                            @endforeach
                        </select>

                        @error('status')
                        <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="category_id" class="mb-2 block text-sm font-medium text-zinc-700">
                            Kategoria produktu
                        </label>

                        <select
                            id="category_id"
                            name="category_id"
                            class="@error('category_id') border-red-300 ring-red-100 @else border-zinc-300 @enderror block w-full rounded-xl border bg-white px-4 py-3 text-sm text-zinc-900 outline-none transition focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100"
                        >
                            <option value="" @selected($currentKategoriaId === null || $currentKategoriaId === '')>
                                Brak kategorii
                            </option>

                            @foreach ($categories as $category)
                                <option
                                    value="{{ $category->id }}"
                                    @selected((string) $currentKategoriaId === (string) $category->id)
                                >
                                    {{ $category->name }}
                                </option>
                            @endforeach
                        </select>

                        @if ($categories->isEmpty())
                            <p class="mt-2 text-sm text-zinc-500">
                                Utwórz aktywną kategorię przed przypisaniem produktu.
                            </p>
                        @endif

                        @error('category_id')
                        <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="short_description" class="mb-2 block text-sm font-medium text-zinc-700">
                            Krótki opis
                        </label>

                        <textarea
                            id="short_description"
                            name="short_description"
                            rows="4"
                            class="@error('short_description') border-red-300 ring-red-100 @else border-zinc-300 @enderror block w-full rounded-xl border bg-white px-4 py-3 text-sm text-zinc-900 outline-none transition focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100"
                        >{{ old('short_description', $product->short_description) }}</textarea>

                        @error('short_description')
                        <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="description" class="mb-2 block text-sm font-medium text-zinc-700">
                            Opis HTML produktu
                        </label>

                        <textarea
                            id="description"
                            name="description"
                            rows="12"
                            spellcheck="false"
                            class="@error('description') border-red-300 ring-red-100 @else border-zinc-300 @enderror block w-full rounded-xl border bg-white px-4 py-3 font-mono text-sm text-zinc-900 outline-none transition focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100"
                        >{{ old('description', $product->description) }}</textarea>

                        <p class="mt-2 text-xs text-zinc-500">
                            Surowy HTML jest widoczny tutaj i zapisywany w podanej formie.
                        </p>

                        @error('description')
                        <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="seo_title" class="mb-2 block text-sm font-medium text-zinc-700">
                            Tytuł SEO
                        </label>

                        <input
                            id="seo_title"
                            type="text"
                            name="seo_title"
                            value="{{ old('seo_title', $product->seo_title) }}"
                            maxlength="255"
                            class="@error('seo_title') border-red-300 ring-red-100 @else border-zinc-300 @enderror block w-full rounded-xl border bg-white px-4 py-3 text-sm text-zinc-900 outline-none transition focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100"
                        >

                        @error('seo_title')
                        <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="seo_description" class="mb-2 block text-sm font-medium text-zinc-700">
                            Opis SEO
                        </label>

                        <textarea
                            id="seo_description"
                            name="seo_description"
                            rows="3"
                            class="@error('seo_description') border-red-300 ring-red-100 @else border-zinc-300 @enderror block w-full rounded-xl border bg-white px-4 py-3 text-sm text-zinc-900 outline-none transition focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100"
                        >{{ old('seo_description', $product->seo_description) }}</textarea>

                        @error('seo_description')
                        <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <dl class="space-y-3 text-sm">
                        <div>
                            <dt class="text-zinc-500">Slug</dt>
                            <dd class="font-medium text-zinc-900">{{ $product->slug }}</dd>
                        </div>

                        <div>
                            <dt class="text-zinc-500">Źródło zewnętrzne</dt>
                            <dd class="font-medium text-zinc-900">{{ $product->external_source ?: '—' }}</dd>
                        </div>

                        <div>
                            <dt class="text-zinc-500">ID zewnętrzne</dt>
                            <dd class="font-medium text-zinc-900">{{ $product->external_id ?: '—' }}</dd>
                        </div>
                    </dl>

                    <div class="flex justify-end border-t border-zinc-200 pt-5">
                        <button class="rounded-xl bg-zinc-900 px-4 py-2 text-sm font-semibold text-white hover:bg-zinc-700">
                            Zapisz produkt
                        </button>
                    </div>
                </form>
            </div>

            <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm lg:col-span-2">
                <div class="flex flex-col gap-5 lg:flex-row lg:items-start">
                    <div class="w-full lg:w-64">
                        <h2 class="text-lg font-semibold text-zinc-900">Domyślne zdjęcie produktu</h2>

                        <p class="mt-2 text-sm text-zinc-500">
                            To zdjęcie jest używane jako domyślne zdjęcie produktu, zanim klient wybierze wariant.
                        </p>

                        <div class="mt-4 overflow-hidden rounded-2xl border border-zinc-200 bg-zinc-50">
                            @if ($currentDefaultImage)
                                <img
                                    src="{{ $currentDefaultImage->url }}"
                                    alt="{{ $currentDefaultImage->alt_text ?: $product->name }}"
                                    class="aspect-square w-full object-cover"
                                >
                            @else
                                <div class="flex aspect-square w-full items-center justify-center text-sm text-zinc-500">
                                    Nie wybrano zdjęcia
                                </div>
                            @endif
                        </div>
                    </div>

                    <div class="flex-1">
                        <form
                            method="POST"
                            action="{{ route('admin.products.default-image.update', $product) }}"
                            class="space-y-5"
                        >
                            @csrf
                            @method('PATCH')

                            @if ($hasSelectableImages)
                                @if ($product->images->isNotEmpty())
                                    <div>
                                        <h3 class="text-sm font-semibold text-zinc-900">Zdjęcia produktu</h3>

                                        <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-4">
                                            @foreach ($product->images as $image)
                                                @php
                                                    $selectionValue = \App\Models\Product::DEFAULT_IMAGE_TYPE_PRODUCT_IMAGE . ':' . $image->id;
                                                    $isSelected = $currentDefaultImageSelection === $selectionValue;
                                                @endphp

                                                <label
                                                    for="default-product-image-{{ $image->id }}"
                                                    class="block cursor-pointer overflow-hidden rounded-xl border bg-white transition hover:border-zinc-400 {{ $isSelected ? 'border-zinc-900 ring-2 ring-zinc-900/10' : 'border-zinc-200' }}"
                                                >
                                                    <input
                                                        id="default-product-image-{{ $image->id }}"
                                                        type="radio"
                                                        name="default_image"
                                                        value="{{ $selectionValue }}"
                                                        class="sr-only"
                                                        @checked($isSelected)
                                                    >

                                                    <img
                                                        src="{{ $image->url }}"
                                                        alt="{{ $image->alt_text ?: $product->name }}"
                                                        class="aspect-square w-full object-cover"
                                                    >

                                                    <span class="block truncate px-3 py-2 text-xs font-medium text-zinc-700">
                                                        {{ $image->title ?: $image->alt_text ?: 'Zdjęcie produktu #' . $image->id }}
                                                    </span>
                                                </label>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif

                                @if ($product->attributeValueImages->isNotEmpty())
                                    <div>
                                        <h3 class="text-sm font-semibold text-zinc-900">Zdjęcia wariantów</h3>

                                        <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-4">
                                            @foreach ($product->attributeValueImages as $image)
                                                @php
                                                    $selectionValue = \App\Models\Product::DEFAULT_IMAGE_TYPE_ATTRIBUTE_VALUE_IMAGE . ':' . $image->id;
                                                    $isSelected = $currentDefaultImageSelection === $selectionValue;
                                                    $attributeValue = $image->attributeValue;
                                                    $variantImageLabel = $attributeValue?->attribute?->name && $attributeValue?->value
                                                        ? $attributeValue->attribute->name . ': ' . $attributeValue->value
                                                        : 'Zdjęcie wariantu #' . $image->id;
                                                @endphp

                                                <label
                                                    for="default-attribute-value-image-{{ $image->id }}"
                                                    class="block cursor-pointer overflow-hidden rounded-xl border bg-white transition hover:border-zinc-400 {{ $isSelected ? 'border-zinc-900 ring-2 ring-zinc-900/10' : 'border-zinc-200' }}"
                                                >
                                                    <input
                                                        id="default-attribute-value-image-{{ $image->id }}"
                                                        type="radio"
                                                        name="default_image"
                                                        value="{{ $selectionValue }}"
                                                        class="sr-only"
                                                        @checked($isSelected)
                                                    >

                                                    <img
                                                        src="{{ $image->url }}"
                                                        alt="{{ $image->alt_text ?: $variantImageLabel }}"
                                                        class="aspect-square w-full object-cover"
                                                    >

                                                    <span class="block truncate px-3 py-2 text-xs font-medium text-zinc-700">
                                                        {{ $variantImageLabel }}
                                                    </span>
                                                </label>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif

                                @error('default_image')
                                <p class="text-sm text-red-600">{{ $message }}</p>
                                @enderror

                                <div class="flex justify-end border-t border-zinc-200 pt-5">
                                    <button class="rounded-xl bg-zinc-900 px-4 py-2 text-sm font-semibold text-white hover:bg-zinc-700">
                                        Zapisz domyślne zdjęcie
                                    </button>
                                </div>
                            @else
                                <div class="rounded-xl border border-dashed border-zinc-300 bg-zinc-50 p-6 text-sm text-zinc-500">
                                    Ten produkt nie ma jeszcze zdjęć produktu ani wariantów.
                                </div>
                            @endif
                        </form>
                    </div>
                </div>
            </div>


            <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm lg:col-span-3">
                <h2 class="text-lg font-semibold text-zinc-900">
                    Zastosuj cenę do wszystkich wariantów
                </h2>

                <p class="mt-2 text-sm text-zinc-500">
                    Użyj, gdy wszystkie warianty tego produktu mają mieć tę samą cenę brutto dla klienta, stawkę VAT i walutę.
                    Cena netto jest obliczana i zapisywana automatycznie.
                </p>

                <form
                    method="POST"
                    action="{{ route('admin.products.prices.update', $product) }}"
                    class="mt-5 space-y-5"
                >
                    @csrf
                    @method('PATCH')

                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-3">
                        <div>
                            <label for="gross_price" class="mb-2 block text-sm font-medium text-zinc-700">
                                Cena brutto
                            </label>

                            <div class="flex rounded-xl shadow-sm">
                                <input
                                    id="gross_price"
                                    type="number"
                                    name="gross_price"
                                    min="0.01"
                                    max="999999.99"
                                    step="0.01"
                                    inputmode="decimal"
                                    value="{{ old('gross_price') }}"
                                    required
                                    class="@error('gross_price') border-red-300 ring-red-100 @else border-zinc-300 @enderror block w-full rounded-l-xl border bg-white px-4 py-3 text-sm text-zinc-900 outline-none transition focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100"
                                >

                                <span class="inline-flex items-center rounded-r-xl border border-l-0 border-zinc-300 bg-zinc-50 px-3 text-sm text-zinc-500">
                                    gross
                                </span>
                            </div>

                            @error('gross_price')
                            <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="vat_rate" class="mb-2 block text-sm font-medium text-zinc-700">
                                Stawka VAT
                            </label>

                            <select
                                id="vat_rate"
                                name="vat_rate"
                                required
                                class="@error('vat_rate') border-red-300 ring-red-100 @else border-zinc-300 @enderror block w-full rounded-xl border bg-white px-4 py-3 text-sm text-zinc-900 outline-none transition focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100"
                            >
                                @foreach (\App\Enums\VatRate::cases() as $vatRate)
                                    <option
                                        value="{{ $vatRate->value }}"
                                        @selected((int) old('vat_rate', \App\Enums\VatRate::VAT_23->value) === $vatRate->value)
                                    >
                                        {{ $vatRate->label() }}
                                    </option>
                                @endforeach
                            </select>

                            @error('vat_rate')
                            <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="currency" class="mb-2 block text-sm font-medium text-zinc-700">
                                Waluta
                            </label>

                            <select
                                id="currency"
                                name="currency"
                                required
                                class="@error('currency') border-red-300 ring-red-100 @else border-zinc-300 @enderror block w-full rounded-xl border bg-white px-4 py-3 text-sm text-zinc-900 outline-none transition focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100"
                            >
                                @foreach (\App\Enums\Currency::cases() as $currency)
                                    <option
                                        value="{{ $currency->value }}"
                                        @selected(old('currency', \App\Enums\Currency::default()->value) === $currency->value)
                                    >
                                        {{ $currency->label() }}
                                    </option>
                                @endforeach
                            </select>

                            @error('currency')
                            <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>

                    <div class="flex justify-end">
                        <button class="rounded-xl bg-zinc-900 px-4 py-2 text-sm font-semibold text-white hover:bg-zinc-700">
                            Zastosuj cenę do wszystkich wariantów
                        </button>
                    </div>
                </form>
            </div>

            <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm lg:col-span-3">
                <h2 class="text-lg font-semibold text-zinc-900">
                    Zastosuj dane paczki do wszystkich wariantów
                </h2>

                <p class="mt-2 text-sm text-zinc-500">
                    Użyj tej opcji, gdy wszystkie warianty produktu mają takie same dane paczki do wysyłki.
                </p>

                <form
                    method="POST"
                    action="{{ route('admin.products.package-dimensions.update', $product) }}"
                    class="mt-5 space-y-5"
                >
                    @csrf
                    @method('PATCH')

                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-4">
                        <div>
                            <label for="package_weight_grams" class="mb-2 block text-sm font-medium text-zinc-700">
                                Waga
                            </label>

                            <div class="flex rounded-xl shadow-sm">
                                <input
                                    id="package_weight_grams"
                                    type="number"
                                    name="package_weight_grams"
                                    min="1"
                                    max="100000"
                                    class="@error('package_weight_grams') border-red-300 ring-red-100 @else border-zinc-300 @enderror block w-full rounded-l-xl border bg-white px-4 py-3 text-sm text-zinc-900 outline-none transition focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100"
                                >

                                <span class="inline-flex items-center rounded-r-xl border border-l-0 border-zinc-300 bg-zinc-50 px-3 text-sm text-zinc-500">
                                    g
                                </span>
                            </div>

                            @error('package_weight_grams')
                            <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="package_length_mm" class="mb-2 block text-sm font-medium text-zinc-700">
                                Długość
                            </label>

                            <div class="flex rounded-xl shadow-sm">
                                <input
                                    id="package_length_mm"
                                    type="number"
                                    name="package_length_mm"
                                    min="1"
                                    max="5000"
                                    class="@error('package_length_mm') border-red-300 ring-red-100 @else border-zinc-300 @enderror block w-full rounded-l-xl border bg-white px-4 py-3 text-sm text-zinc-900 outline-none transition focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100"
                                >

                                <span class="inline-flex items-center rounded-r-xl border border-l-0 border-zinc-300 bg-zinc-50 px-3 text-sm text-zinc-500">
                                    mm
                                </span>
                            </div>

                            @error('package_length_mm')
                            <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="package_width_mm" class="mb-2 block text-sm font-medium text-zinc-700">
                                Szerokość
                            </label>

                            <div class="flex rounded-xl shadow-sm">
                                <input
                                    id="package_width_mm"
                                    type="number"
                                    name="package_width_mm"
                                    min="1"
                                    max="5000"
                                    class="@error('package_width_mm') border-red-300 ring-red-100 @else border-zinc-300 @enderror block w-full rounded-l-xl border bg-white px-4 py-3 text-sm text-zinc-900 outline-none transition focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100"
                                >

                                <span class="inline-flex items-center rounded-r-xl border border-l-0 border-zinc-300 bg-zinc-50 px-3 text-sm text-zinc-500">
                                    mm
                                </span>
                            </div>

                            @error('package_width_mm')
                            <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="package_height_mm" class="mb-2 block text-sm font-medium text-zinc-700">
                                Wysokość
                            </label>

                            <div class="flex rounded-xl shadow-sm">
                                <input
                                    id="package_height_mm"
                                    type="number"
                                    name="package_height_mm"
                                    min="1"
                                    max="5000"
                                    class="@error('package_height_mm') border-red-300 ring-red-100 @else border-zinc-300 @enderror block w-full rounded-l-xl border bg-white px-4 py-3 text-sm text-zinc-900 outline-none transition focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100"
                                >

                                <span class="inline-flex items-center rounded-r-xl border border-l-0 border-zinc-300 bg-zinc-50 px-3 text-sm text-zinc-500">
                                    mm
                                </span>
                            </div>

                            @error('package_height_mm')
                            <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>

                    <div class="flex justify-end">
                        <button class="rounded-xl bg-zinc-900 px-4 py-2 text-sm font-semibold text-white hover:bg-zinc-700">
                            Zastosuj do wszystkich wariantów
                        </button>
                    </div>
                </form>
            </div>
        </div>


        <div class="mt-6 rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm">
            <div class="mb-4">
                <h2 class="text-lg font-semibold text-zinc-900">
                    Ceny wariantów
                </h2>

                <p class="mt-2 text-sm text-zinc-500">
                    Zapisz indywidualne ceny brutto, gdy warianty mają różne ceny. Ceny netto są obliczane na podstawie wybranej stawki VAT.
                </p>
            </div>

            @if ($pricedDraftVariantCount > 0 || $unpricedDraftVariantCount > 0)
                <div class="mb-5 rounded-2xl border border-amber-200 bg-amber-50 p-5">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <h3 class="text-sm font-semibold text-amber-900">
                                Publikacja wariantów
                            </h3>

                            <p class="mt-2 text-sm text-amber-800">
                                Warianty w szkicu nie są widoczne w sklepie i nie będą używane do ceny widocznej dla klienta.
                                @if ($pricedDraftVariantCount > 0)
                                    {{ $pricedDraftVariantCount }} {{ $pricedDraftVariantCount === 1 ? 'wyceniony wariant w szkicu można teraz aktywować' : 'wycenione warianty w szkicu można teraz aktywować' }}.
                                @endif
                                @if ($unpricedDraftVariantCount > 0)
                                    {{ $unpricedDraftVariantCount }} {{ $unpricedDraftVariantCount === 1 ? 'wariant w szkicu nadal wymaga ceny przed aktywacją' : 'warianty w szkicu nadal wymagają ceny przed aktywacją' }}.
                                @endif
                            </p>
                        </div>

                        @if ($pricedDraftVariantCount > 0)
                            <form
                                method="POST"
                                action="{{ route('admin.products.variants.activate-priced', $product) }}"
                                class="shrink-0"
                            >
                                @csrf
                                @method('PATCH')

                                <button class="rounded-xl bg-amber-900 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-800">
                                    Aktywuj wycenione warianty
                                </button>
                            </form>
                        @endif
                    </div>
                </div>
            @endif

            <form
                method="POST"
                action="{{ route('admin.products.variants.prices.update', $product) }}"
            >
                @csrf
                @method('PATCH')

                <div class="overflow-hidden rounded-xl border border-zinc-200">
                    <table class="min-w-full divide-y divide-zinc-200">
                        <thead class="bg-zinc-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">Wariant</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">Status</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-zinc-500">Aktualnie netto</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-zinc-500">Cena brutto</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">Stawka VAT</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">Waluta</th>
                        </tr>
                        </thead>

                        <tbody class="divide-y divide-zinc-200 bg-white">
                        @forelse ($product->variants as $variant)
                            @php
                                $variantName = $variant->attributeValues
                                    ->pluck('value')
                                    ->filter()
                                    ->implode(' / ');

                                $grossPriceAmount = $variant->grossPriceAmount();
                                $grossPriceValue = $grossPriceAmount === null
                                    ? ''
                                    : number_format($grossPriceAmount / 100, 2, '.', '');

                                $netPriceValue = $variant->price_net_amount === null
                                    ? '—'
                                    : number_format($variant->price_net_amount / 100, 2, ',', ' ') . ' ' . ($variant->currency?->value ?? '');
                            @endphp

                            <tr>
                                <td class="px-4 py-4 text-sm">
                                    <div class="font-medium text-zinc-900">
                                        {{ $variant->sku ?: 'Variant #' . $variant->id }}
                                    </div>

                                    @if ($variantName)
                                        <div class="mt-1 text-xs text-zinc-500">
                                            {{ $variantName }}
                                        </div>
                                    @endif

                                    <div class="mt-1 text-xs text-zinc-400">
                                        ID: {{ $variant->id }}
                                    </div>
                                </td>

                                <td class="px-4 py-4 text-sm text-zinc-700">
                                    {{ $variant->status->label() }}
                                </td>

                                <td class="px-4 py-4 text-right text-sm text-zinc-700">
                                    {{ $netPriceValue }}
                                </td>

                                <td class="px-4 py-4 text-right text-sm">
                                    <input
                                        type="number"
                                        min="0.01"
                                        max="999999.99"
                                        step="0.01"
                                        inputmode="decimal"
                                        required
                                        name="variants[{{ $variant->id }}][gross_price]"
                                        value="{{ old("variants.{$variant->id}.gross_price", $grossPriceValue) }}"
                                        class="@error("variants.{$variant->id}.gross_price") border-red-300 ring-red-100 @else border-zinc-300 @enderror w-32 rounded-xl border bg-white px-3 py-2 text-right text-sm text-zinc-900 outline-none focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100"
                                    >
                                </td>

                                <td class="px-4 py-4 text-sm">
                                    <select
                                        name="variants[{{ $variant->id }}][vat_rate]"
                                        required
                                        class="@error("variants.{$variant->id}.vat_rate") border-red-300 ring-red-100 @else border-zinc-300 @enderror w-32 rounded-xl border bg-white px-3 py-2 text-sm text-zinc-900 outline-none focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100"
                                    >
                                        @foreach (\App\Enums\VatRate::cases() as $vatRate)
                                            <option
                                                value="{{ $vatRate->value }}"
                                                @selected((int) old("variants.{$variant->id}.vat_rate", $variant->vat_rate?->value ?? \App\Enums\VatRate::VAT_23->value) === $vatRate->value)
                                            >
                                                {{ $vatRate->label() }}
                                            </option>
                                        @endforeach
                                    </select>
                                </td>

                                <td class="px-4 py-4 text-sm">
                                    <select
                                        name="variants[{{ $variant->id }}][currency]"
                                        required
                                        class="@error("variants.{$variant->id}.currency") border-red-300 ring-red-100 @else border-zinc-300 @enderror w-28 rounded-xl border bg-white px-3 py-2 text-sm text-zinc-900 outline-none focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100"
                                    >
                                        @foreach (\App\Enums\Currency::cases() as $currency)
                                            <option
                                                value="{{ $currency->value }}"
                                                @selected(old("variants.{$variant->id}.currency", $variant->currency?->value ?? \App\Enums\Currency::default()->value) === $currency->value)
                                            >
                                                {{ $currency->label() }}
                                            </option>
                                        @endforeach
                                    </select>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-4 py-10 text-center text-sm text-zinc-500">
                                    Ten produkt nie ma wariantów.
                                </td>
                            </tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>

                @if ($errors->has('variants'))
                    <p class="mt-2 text-sm text-red-600">{{ $errors->first('variants') }}</p>
                @endif

                <div class="mt-5 flex justify-end">
                    <button class="rounded-xl bg-zinc-900 px-4 py-2 text-sm font-semibold text-white hover:bg-zinc-700">
                        Zapisz ceny wariantów
                    </button>
                </div>
            </form>
        </div>

        <div class="mt-6 rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm">
            <div class="mb-4">
                <h2 class="text-lg font-semibold text-zinc-900">
                    Status dostępności wariantów
                </h2>

                <p class="mt-2 text-sm text-zinc-500">
                    Status dostępności decyduje, czy aktywne warianty można kupić w sklepie. Importowane warianty Wojdak mogą wymagać zmiany z niedostępnych na dostępne po potwierdzeniu cen.
                </p>
            </div>

            <div class="mb-6 rounded-2xl border border-zinc-100 bg-zinc-50 p-5">
                <h3 class="text-sm font-semibold text-zinc-900">
                    Zastosuj status dostępności do wszystkich wariantów
                </h3>

                <p class="mt-2 text-sm text-zinc-500">
                    Użyj tego, gdy każdy wariant produktu ma mieć taką samą dostępność.
                </p>

                <form
                    method="POST"
                    action="{{ route('admin.products.stock-status.update', $product) }}"
                    class="mt-4 grid gap-4 sm:grid-cols-[1fr_auto] sm:items-end"
                >
                    @csrf
                    @method('PATCH')

                    <div>
                        <label for="stock_status" class="text-sm font-medium text-zinc-700">
                            Status dostępności
                        </label>

                        <select
                            id="stock_status"
                            name="stock_status"
                            required
                            class="@error('stock_status') border-red-300 ring-red-100 @else border-zinc-300 @enderror mt-1 w-full rounded-xl border bg-white px-3 py-2 text-sm text-zinc-900 outline-none focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100"
                        >
                            @foreach (\App\Enums\StockStatus::cases() as $stockStatus)
                                <option value="{{ $stockStatus->value }}">
                                    {{ $stockStatus->label() }}
                                </option>
                            @endforeach
                        </select>

                        @error('stock_status')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="flex justify-end">
                        <button class="rounded-xl bg-zinc-900 px-4 py-2 text-sm font-semibold text-white hover:bg-zinc-700">
                            Zastosuj status dostępności
                        </button>
                    </div>
                </form>
            </div>

            <form
                method="POST"
                action="{{ route('admin.products.variants.stock-status.update', $product) }}"
            >
                @csrf
                @method('PATCH')

                <div class="overflow-hidden rounded-xl border border-zinc-200">
                    <table class="min-w-full divide-y divide-zinc-200">
                        <thead class="bg-zinc-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">Wariant</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">Status</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">Status dostępności</th>
                        </tr>
                        </thead>

                        <tbody class="divide-y divide-zinc-200 bg-white">
                        @forelse ($product->variants as $variant)
                            @php
                                $variantName = $variant->attributeValues
                                    ->pluck('value')
                                    ->filter()
                                    ->implode(' / ');
                            @endphp

                            <tr>
                                <td class="px-4 py-4 text-sm">
                                    <div class="font-medium text-zinc-900">
                                        {{ $variant->sku ?: 'Variant #' . $variant->id }}
                                    </div>

                                    @if ($variantName)
                                        <div class="mt-1 text-xs text-zinc-500">
                                            {{ $variantName }}
                                        </div>
                                    @endif

                                    <div class="mt-1 text-xs text-zinc-400">
                                        ID: {{ $variant->id }}
                                    </div>
                                </td>

                                <td class="px-4 py-4 text-sm text-zinc-700">
                                    {{ $variant->status->label() }}
                                </td>

                                <td class="px-4 py-4 text-sm">
                                    <select
                                        name="variants[{{ $variant->id }}][stock_status]"
                                        required
                                        class="@error("variants.{$variant->id}.stock_status") border-red-300 ring-red-100 @else border-zinc-300 @enderror w-44 rounded-xl border bg-white px-3 py-2 text-sm text-zinc-900 outline-none focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100"
                                    >
                                        @foreach (\App\Enums\StockStatus::cases() as $stockStatus)
                                            <option
                                                value="{{ $stockStatus->value }}"
                                                @selected(old("variants.{$variant->id}.stock_status", $variant->stock_status?->value ?? \App\Enums\StockStatus::OUT_OF_STOCK->value) === $stockStatus->value)
                                            >
                                                {{ $stockStatus->label() }}
                                            </option>
                                        @endforeach
                                    </select>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3" class="px-4 py-10 text-center text-sm text-zinc-500">
                                    Ten produkt nie ma wariantów.
                                </td>
                            </tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>

                @if ($errors->has('variants'))
                    <p class="mt-2 text-sm text-red-600">{{ $errors->first('variants') }}</p>
                @endif

                <div class="mt-5 flex justify-end">
                    <button class="rounded-xl bg-zinc-900 px-4 py-2 text-sm font-semibold text-white hover:bg-zinc-700">
                        Zapisz statusy dostępności wariantów
                    </button>
                </div>
            </form>
        </div>

        <div class="mt-6 rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm">
            <div class="mb-4">
                <h2 class="text-lg font-semibold text-zinc-900">
                    Dane paczek wariantów
                </h2>

                <p class="mt-2 text-sm text-zinc-500">
                    Zapisz indywidualne dane paczek, gdy warianty różnią się rozmiarem lub wagą.
                </p>
            </div>

            <form
                method="POST"
                action="{{ route('admin.products.variants.package-dimensions.update', $product) }}"
            >
                @csrf
                @method('PATCH')

                <div class="overflow-hidden rounded-xl border border-zinc-200">
                    <table class="min-w-full divide-y divide-zinc-200">
                        <thead class="bg-zinc-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">Wariant</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">Status</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">Status paczki</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-zinc-500">Waga g</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-zinc-500">Długość mm</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-zinc-500">Szerokość mm</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-zinc-500">Wysokość mm</th>
                        </tr>
                        </thead>

                        <tbody class="divide-y divide-zinc-200 bg-white">
                        @forelse ($product->variants as $variant)
                            @php
                                $hasCompletePackage = $variant->package_weight_grams
                                    && $variant->package_length_mm
                                    && $variant->package_width_mm
                                    && $variant->package_height_mm;

                                $variantName = $variant->attributeValues
                                    ->pluck('value')
                                    ->filter()
                                    ->implode(' / ');
                            @endphp

                            <tr>
                                <td class="px-4 py-4 text-sm">
                                    <div class="font-medium text-zinc-900">
                                        {{ $variant->sku ?: 'Variant #' . $variant->id }}
                                    </div>

                                    @if ($variantName)
                                        <div class="mt-1 text-xs text-zinc-500">
                                            {{ $variantName }}
                                        </div>
                                    @endif

                                    <div class="mt-1 text-xs text-zinc-400">
                                        ID: {{ $variant->id }}
                                    </div>
                                </td>

                                <td class="px-4 py-4 text-sm text-zinc-700">
                                    {{ $variant->status->label() }}
                                </td>

                                <td class="px-4 py-4 text-sm">
                                    @if ($hasCompletePackage)
                                        <span class="inline-flex rounded-full bg-green-100 px-2.5 py-1 text-xs font-semibold text-green-700">
                                            Complete
                                        </span>
                                    @else
                                        <span class="inline-flex rounded-full bg-red-100 px-2.5 py-1 text-xs font-semibold text-red-700">
                                            Brak wagi/wymiarów
                                        </span>
                                    @endif
                                </td>

                                <td class="px-4 py-4 text-right text-sm">
                                    <input
                                        type="number"
                                        min="1"
                                        max="100000"
                                        name="variants[{{ $variant->id }}][package_weight_grams]"
                                        value="{{ old("variants.{$variant->id}.package_weight_grams", $variant->package_weight_grams) }}"
                                        class="@error("variants.{$variant->id}.package_weight_grams") border-red-300 ring-red-100 @else border-zinc-300 @enderror w-28 rounded-xl border bg-white px-3 py-2 text-right text-sm text-zinc-900 outline-none focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100"
                                    >
                                </td>

                                <td class="px-4 py-4 text-right text-sm">
                                    <input
                                        type="number"
                                        min="1"
                                        max="5000"
                                        name="variants[{{ $variant->id }}][package_length_mm]"
                                        value="{{ old("variants.{$variant->id}.package_length_mm", $variant->package_length_mm) }}"
                                        class="@error("variants.{$variant->id}.package_length_mm") border-red-300 ring-red-100 @else border-zinc-300 @enderror w-28 rounded-xl border bg-white px-3 py-2 text-right text-sm text-zinc-900 outline-none focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100"
                                    >
                                </td>

                                <td class="px-4 py-4 text-right text-sm">
                                    <input
                                        type="number"
                                        min="1"
                                        max="5000"
                                        name="variants[{{ $variant->id }}][package_width_mm]"
                                        value="{{ old("variants.{$variant->id}.package_width_mm", $variant->package_width_mm) }}"
                                        class="@error("variants.{$variant->id}.package_width_mm") border-red-300 ring-red-100 @else border-zinc-300 @enderror w-28 rounded-xl border bg-white px-3 py-2 text-right text-sm text-zinc-900 outline-none focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100"
                                    >
                                </td>

                                <td class="px-4 py-4 text-right text-sm">
                                    <input
                                        type="number"
                                        min="1"
                                        max="5000"
                                        name="variants[{{ $variant->id }}][package_height_mm]"
                                        value="{{ old("variants.{$variant->id}.package_height_mm", $variant->package_height_mm) }}"
                                        class="@error("variants.{$variant->id}.package_height_mm") border-red-300 ring-red-100 @else border-zinc-300 @enderror w-28 rounded-xl border bg-white px-3 py-2 text-right text-sm text-zinc-900 outline-none focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100"
                                    >
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-4 py-10 text-center text-sm text-zinc-500">
                                    Ten produkt nie ma wariantów.
                                </td>
                            </tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>

                @if ($errors->has('variants'))
                    <p class="mt-2 text-sm text-red-600">{{ $errors->first('variants') }}</p>
                @endif

                <div class="mt-5 flex justify-end">
                    <button class="rounded-xl bg-zinc-900 px-4 py-2 text-sm font-semibold text-white hover:bg-zinc-700">
                        Zapisz dane paczek wariantów
                    </button>
                </div>
            </form>
        </div>
    </div>
@endsection
