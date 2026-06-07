@extends('layouts.storefront')

@section('content')
    <div class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
        <div class="mb-8">
            <a href="{{ route('admin.products.index') }}" class="text-sm font-medium text-zinc-500 hover:text-zinc-700">
                ← Wróć do produktów
            </a>

            <div class="mt-3">
                <h1 class="text-3xl font-bold tracking-tight text-zinc-900">
                    Stwórz produkt
                </h1>

                <p class="mt-2 text-sm text-zinc-600">
                    Utwórz produkt ręcznie razem z wariantami, cenami, statusem dostępności i danymi paczki.
                </p>
            </div>
        </div>

        @if ($errors->any())
            <div class="mb-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                Formularz zawiera błędy. Sprawdź pola produktu i wariantów.
            </div>
        @endif

        @php
            $defaultVariantRows = [
                0 => [
                    'sku' => '',
                    'status' => \App\Enums\ProductVariantStatus::ACTIVE->value,
                    'gross_price' => '',
                    'currency' => \App\Enums\Currency::PLN->value,
                    'vat_rate' => \App\Enums\VatRate::VAT_23->value,
                    'stock_status' => \App\Enums\StockStatus::IN_STOCK->value,
                    'package_weight_grams' => '',
                    'package_length_mm' => '',
                    'package_width_mm' => '',
                    'package_height_mm' => '',
                    'attributes' => [],
                ],
            ];

            $variantRows = old('variants', $defaultVariantRows);
            $variantRowCount = max(1, count($variantRows));
            $defaultVariantIndex = (string) old('default_variant_index', '0');
        @endphp

        <form method="POST" action="{{ route('admin.products.store') }}" enctype="multipart/form-data" class="space-y-6">
            @csrf

            <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm">
                <h2 class="text-lg font-semibold text-zinc-900">Szczegóły produktu</h2>

                <div class="mt-5 grid grid-cols-1 gap-5 lg:grid-cols-2">
                    <div>
                        <label for="name" class="mb-2 block text-sm font-medium text-zinc-700">
                            Nazwa produktu <span class="text-red-500">*</span>
                        </label>

                        <input
                            id="name"
                            type="text"
                            name="name"
                            value="{{ old('name') }}"
                            required
                            maxlength="255"
                            class="@error('name') border-red-300 ring-red-100 @else border-zinc-300 @enderror block w-full rounded-xl border bg-white px-4 py-3 text-sm text-zinc-900 outline-none transition focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100"
                        >

                        @error('name')
                        <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="slug" class="mb-2 block text-sm font-medium text-zinc-700">
                            Slug
                        </label>

                        <input
                            id="slug"
                            type="text"
                            name="slug"
                            value="{{ old('slug') }}"
                            maxlength="255"
                            placeholder="Zostaw puste, aby wygenerować automatycznie"
                            class="@error('slug') border-red-300 ring-red-100 @else border-zinc-300 @enderror block w-full rounded-xl border bg-white px-4 py-3 text-sm text-zinc-900 outline-none transition focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100"
                        >

                        @error('slug')
                        <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="status" class="mb-2 block text-sm font-medium text-zinc-700">
                            Status produktu <span class="text-red-500">*</span>
                        </label>

                        <select
                            id="status"
                            name="status"
                            required
                            class="@error('status') border-red-300 ring-red-100 @else border-zinc-300 @enderror block w-full rounded-xl border bg-white px-4 py-3 text-sm text-zinc-900 outline-none transition focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100"
                        >
                            @foreach (\App\Enums\ProductStatus::cases() as $status)
                                <option value="{{ $status->value }}" @selected(old('status', \App\Enums\ProductStatus::DRAFT->value) === $status->value)>
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
                            <option value="">Brak kategorii</option>

                            @foreach ($categories as $category)
                                <option value="{{ $category->id }}" @selected((string) old('category_id') === (string) $category->id)>
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
                </div>

                <div class="mt-5 grid grid-cols-1 gap-5">
                    <div>
                        <label for="short_description" class="mb-2 block text-sm font-medium text-zinc-700">
                            Krótki opis
                        </label>

                        <textarea
                            id="short_description"
                            name="short_description"
                            rows="3"
                            class="@error('short_description') border-red-300 ring-red-100 @else border-zinc-300 @enderror block w-full rounded-xl border bg-white px-4 py-3 text-sm text-zinc-900 outline-none transition focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100"
                        >{{ old('short_description') }}</textarea>

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
                            rows="10"
                            class="@error('description') border-red-300 ring-red-100 @else border-zinc-300 @enderror block w-full rounded-xl border bg-white px-4 py-3 font-mono text-sm text-zinc-900 outline-none transition focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100"
                        >{{ old('description') }}</textarea>

                        <p class="mt-2 text-xs text-zinc-500">
                            Możesz wkleić surowy HTML opisu produktu.
                        </p>

                        @error('description')
                        <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                <div class="mt-5 grid grid-cols-1 gap-5 lg:grid-cols-2">
                    <div>
                        <label for="seo_title" class="mb-2 block text-sm font-medium text-zinc-700">
                            Tytuł SEO
                        </label>

                        <input
                            id="seo_title"
                            type="text"
                            name="seo_title"
                            value="{{ old('seo_title') }}"
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
                        >{{ old('seo_description') }}</textarea>

                        @error('seo_description')
                        <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>
                </div>
            </div>


            <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm">
                <h2 class="text-lg font-semibold text-zinc-900">Zdjęcia produktu</h2>

                <p class="mt-2 text-sm text-zinc-500">
                    Dodaj jedno lub kilka zdjęć produktu. Pierwsze przesłane zdjęcie zostanie ustawione jako główne zdjęcie produktu.
                </p>

                <div class="mt-5">
                    <label for="product_images" class="mb-2 block text-sm font-medium text-zinc-700">
                        Zdjęcia produktu
                    </label>

                    <input
                        id="product_images"
                        type="file"
                        name="product_images[]"
                        multiple
                        accept="image/jpeg,image/png,image/webp"
                        class="@error('product_images') border-red-300 ring-red-100 @else border-zinc-300 @enderror @error('product_images.*') border-red-300 ring-red-100 @enderror block w-full rounded-xl border bg-white px-4 py-3 text-sm text-zinc-900 outline-none transition file:mr-4 file:rounded-lg file:border-0 file:bg-zinc-900 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-white hover:file:bg-zinc-700 focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100"
                    >

                    <p class="mt-2 text-xs text-zinc-500">
                        Dozwolone formaty: JPG, PNG, WEBP. Maksymalnie 10 plików, do 5 MB każdy.
                    </p>

                    @error('product_images')
                    <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                    @enderror

                    @error('product_images.*')
                    <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <h2 class="text-lg font-semibold text-zinc-900">Warianty produktu</h2>

                        <p class="mt-2 text-sm text-zinc-500">
                            Pierwszy wariant jest wymagany. Dodaj kolejne warianty tylko wtedy, gdy produkt ich potrzebuje.
                        </p>
                    </div>

                    <button
                        type="button"
                        data-add-product-variant
                        class="inline-flex items-center justify-center rounded-xl border border-zinc-300 px-4 py-2 text-sm font-semibold text-zinc-700 hover:bg-zinc-50"
                    >
                        Dodaj kolejny wariant
                    </button>
                </div>

                @error('variants')
                <p class="mt-4 text-sm text-red-600">{{ $message }}</p>
                @enderror

                <div class="mt-5 space-y-5" data-product-variant-list>
                    @for ($variantIndex = 0; $variantIndex < $variantRowCount; $variantIndex++)
                        @php
                            $variant = $variantRows[$variantIndex] ?? [
                                'sku' => '',
                                'status' => \App\Enums\ProductVariantStatus::ACTIVE->value,
                                'gross_price' => '',
                                'currency' => \App\Enums\Currency::PLN->value,
                                'vat_rate' => \App\Enums\VatRate::VAT_23->value,
                                'stock_status' => \App\Enums\StockStatus::IN_STOCK->value,
                                'package_weight_grams' => '',
                                'package_length_mm' => '',
                                'package_width_mm' => '',
                                'package_height_mm' => '',
                                'attributes' => [],
                            ];
                        @endphp

                        <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-5" data-product-variant-card data-variant-index="{{ $variantIndex }}">
                            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                                <h3 class="text-sm font-semibold text-zinc-900">
                                    <span data-variant-title>Wariant {{ $variantIndex + 1 }}</span>
                                    @if ($variantIndex === 0)
                                        <span class="text-red-500">*</span>
                                    @endif
                                </h3>

                                <div class="flex flex-wrap items-center gap-3">
                                    <label class="inline-flex items-center gap-2 text-sm font-medium text-zinc-700">
                                        <input
                                            type="radio"
                                            name="default_variant_index"
                                            value="{{ $variantIndex }}"
                                            @checked($defaultVariantIndex === (string) $variantIndex)
                                            class="border-zinc-300 text-zinc-900 focus:ring-zinc-900"
                                        >
                                        Wariant domyślny
                                    </label>

                                    <button
                                        type="button"
                                        data-remove-product-variant
                                        @if ($variantIndex === 0) hidden @endif
                                        class="rounded-lg border border-red-200 px-3 py-1.5 text-xs font-semibold text-red-700 hover:bg-red-50"
                                    >
                                        Usuń wariant
                                    </button>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 gap-4 lg:grid-cols-4">
                                <div>
                                    <label for="variants-{{ $variantIndex }}-sku" class="mb-2 block text-sm font-medium text-zinc-700">
                                        SKU
                                    </label>

                                    <input
                                        id="variants-{{ $variantIndex }}-sku"
                                        type="text"
                                        name="variants[{{ $variantIndex }}][sku]"
                                        value="{{ $variant['sku'] ?? '' }}"
                                        class="@error('variants.'.$variantIndex.'.sku') border-red-300 ring-red-100 @else border-zinc-300 @enderror block w-full rounded-xl border bg-white px-4 py-3 text-sm text-zinc-900 outline-none transition focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100"
                                    >

                                    @error('variants.'.$variantIndex.'.sku')
                                    <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                                    @enderror
                                </div>

                                <div>
                                    <label for="variants-{{ $variantIndex }}-status" class="mb-2 block text-sm font-medium text-zinc-700">
                                        Status wariantu
                                    </label>

                                    <select
                                        id="variants-{{ $variantIndex }}-status"
                                        name="variants[{{ $variantIndex }}][status]"
                                        class="@error('variants.'.$variantIndex.'.status') border-red-300 ring-red-100 @else border-zinc-300 @enderror block w-full rounded-xl border bg-white px-4 py-3 text-sm text-zinc-900 outline-none transition focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100"
                                    >
                                        @foreach (\App\Enums\ProductVariantStatus::cases() as $status)
                                            <option value="{{ $status->value }}" @selected(($variant['status'] ?? \App\Enums\ProductVariantStatus::ACTIVE->value) === $status->value)>
                                                {{ $status->label() }}
                                            </option>
                                        @endforeach
                                    </select>

                                    @error('variants.'.$variantIndex.'.status')
                                    <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                                    @enderror
                                </div>

                                <div>
                                    <label for="variants-{{ $variantIndex }}-gross-price" class="mb-2 block text-sm font-medium text-zinc-700">
                                        Cena brutto
                                    </label>

                                    <input
                                        id="variants-{{ $variantIndex }}-gross-price"
                                        type="text"
                                        name="variants[{{ $variantIndex }}][gross_price]"
                                        value="{{ $variant['gross_price'] ?? '' }}"
                                        placeholder="np. 123,00"
                                        class="@error('variants.'.$variantIndex.'.gross_price') border-red-300 ring-red-100 @else border-zinc-300 @enderror block w-full rounded-xl border bg-white px-4 py-3 text-sm text-zinc-900 outline-none transition focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100"
                                    >

                                    @error('variants.'.$variantIndex.'.gross_price')
                                    <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                                    @enderror
                                </div>

                                <div>
                                    <label for="variants-{{ $variantIndex }}-currency" class="mb-2 block text-sm font-medium text-zinc-700">
                                        Waluta
                                    </label>

                                    <select
                                        id="variants-{{ $variantIndex }}-currency"
                                        name="variants[{{ $variantIndex }}][currency]"
                                        class="@error('variants.'.$variantIndex.'.currency') border-red-300 ring-red-100 @else border-zinc-300 @enderror block w-full rounded-xl border bg-white px-4 py-3 text-sm text-zinc-900 outline-none transition focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100"
                                    >
                                        @foreach (\App\Enums\Currency::cases() as $currency)
                                            <option value="{{ $currency->value }}" @selected(($variant['currency'] ?? \App\Enums\Currency::PLN->value) === $currency->value)>
                                                {{ $currency->label() }}
                                            </option>
                                        @endforeach
                                    </select>

                                    @error('variants.'.$variantIndex.'.currency')
                                    <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                                    @enderror
                                </div>

                                <div>
                                    <label for="variants-{{ $variantIndex }}-vat-rate" class="mb-2 block text-sm font-medium text-zinc-700">
                                        VAT
                                    </label>

                                    <select
                                        id="variants-{{ $variantIndex }}-vat-rate"
                                        name="variants[{{ $variantIndex }}][vat_rate]"
                                        class="@error('variants.'.$variantIndex.'.vat_rate') border-red-300 ring-red-100 @else border-zinc-300 @enderror block w-full rounded-xl border bg-white px-4 py-3 text-sm text-zinc-900 outline-none transition focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100"
                                    >
                                        @foreach (\App\Enums\VatRate::cases() as $vatRate)
                                            <option value="{{ $vatRate->value }}" @selected((string) ($variant['vat_rate'] ?? \App\Enums\VatRate::VAT_23->value) === (string) $vatRate->value)>
                                                {{ $vatRate->label() }}
                                            </option>
                                        @endforeach
                                    </select>

                                    @error('variants.'.$variantIndex.'.vat_rate')
                                    <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                                    @enderror
                                </div>

                                <div>
                                    <label for="variants-{{ $variantIndex }}-stock-status" class="mb-2 block text-sm font-medium text-zinc-700">
                                        Status dostępności
                                    </label>

                                    <select
                                        id="variants-{{ $variantIndex }}-stock-status"
                                        name="variants[{{ $variantIndex }}][stock_status]"
                                        class="@error('variants.'.$variantIndex.'.stock_status') border-red-300 ring-red-100 @else border-zinc-300 @enderror block w-full rounded-xl border bg-white px-4 py-3 text-sm text-zinc-900 outline-none transition focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100"
                                    >
                                        @foreach (\App\Enums\StockStatus::cases() as $stockStatus)
                                            <option value="{{ $stockStatus->value }}" @selected(($variant['stock_status'] ?? \App\Enums\StockStatus::IN_STOCK->value) === $stockStatus->value)>
                                                {{ $stockStatus->label() }}
                                            </option>
                                        @endforeach
                                    </select>

                                    @error('variants.'.$variantIndex.'.stock_status')
                                    <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                                    @enderror
                                </div>

                                <div>
                                    <label for="variants-{{ $variantIndex }}-weight" class="mb-2 block text-sm font-medium text-zinc-700">
                                        Waga paczki (g)
                                    </label>

                                    <input
                                        id="variants-{{ $variantIndex }}-weight"
                                        type="number"
                                        min="1"
                                        name="variants[{{ $variantIndex }}][package_weight_grams]"
                                        value="{{ $variant['package_weight_grams'] ?? '' }}"
                                        class="@error('variants.'.$variantIndex.'.package_weight_grams') border-red-300 ring-red-100 @else border-zinc-300 @enderror block w-full rounded-xl border bg-white px-4 py-3 text-sm text-zinc-900 outline-none transition focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100"
                                    >

                                    @error('variants.'.$variantIndex.'.package_weight_grams')
                                    <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                                    @enderror
                                </div>

                                <div>
                                    <label for="variants-{{ $variantIndex }}-length" class="mb-2 block text-sm font-medium text-zinc-700">
                                        Długość paczki (mm)
                                    </label>

                                    <input
                                        id="variants-{{ $variantIndex }}-length"
                                        type="number"
                                        min="1"
                                        name="variants[{{ $variantIndex }}][package_length_mm]"
                                        value="{{ $variant['package_length_mm'] ?? '' }}"
                                        class="@error('variants.'.$variantIndex.'.package_length_mm') border-red-300 ring-red-100 @else border-zinc-300 @enderror block w-full rounded-xl border bg-white px-4 py-3 text-sm text-zinc-900 outline-none transition focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100"
                                    >

                                    @error('variants.'.$variantIndex.'.package_length_mm')
                                    <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                                    @enderror
                                </div>

                                <div>
                                    <label for="variants-{{ $variantIndex }}-width" class="mb-2 block text-sm font-medium text-zinc-700">
                                        Szerokość paczki (mm)
                                    </label>

                                    <input
                                        id="variants-{{ $variantIndex }}-width"
                                        type="number"
                                        min="1"
                                        name="variants[{{ $variantIndex }}][package_width_mm]"
                                        value="{{ $variant['package_width_mm'] ?? '' }}"
                                        class="@error('variants.'.$variantIndex.'.package_width_mm') border-red-300 ring-red-100 @else border-zinc-300 @enderror block w-full rounded-xl border bg-white px-4 py-3 text-sm text-zinc-900 outline-none transition focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100"
                                    >

                                    @error('variants.'.$variantIndex.'.package_width_mm')
                                    <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                                    @enderror
                                </div>

                                <div>
                                    <label for="variants-{{ $variantIndex }}-height" class="mb-2 block text-sm font-medium text-zinc-700">
                                        Wysokość paczki (mm)
                                    </label>

                                    <input
                                        id="variants-{{ $variantIndex }}-height"
                                        type="number"
                                        min="1"
                                        name="variants[{{ $variantIndex }}][package_height_mm]"
                                        value="{{ $variant['package_height_mm'] ?? '' }}"
                                        class="@error('variants.'.$variantIndex.'.package_height_mm') border-red-300 ring-red-100 @else border-zinc-300 @enderror block w-full rounded-xl border bg-white px-4 py-3 text-sm text-zinc-900 outline-none transition focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100"
                                    >

                                    @error('variants.'.$variantIndex.'.package_height_mm')
                                    <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                                    @enderror
                                </div>
                            </div>

                            <div class="mt-5 rounded-xl border border-zinc-200 bg-white p-4">
                                <h4 class="text-sm font-semibold text-zinc-900">Atrybuty wariantu</h4>
                                <p class="mt-1 text-xs text-zinc-500">
                                    Opcjonalne, ale zalecane dla produktów z wariantami, np. Rozmiar = M, Kolor = Granatowy.
                                </p>

                                <div class="mt-3 grid grid-cols-1 gap-3 lg:grid-cols-3">
                                    @for ($attributeIndex = 0; $attributeIndex < 3; $attributeIndex++)
                                        @php
                                            $attribute = ($variant['attributes'] ?? [])[$attributeIndex] ?? [
                                                'name' => '',
                                                'value' => '',
                                                'display_type' => \App\Enums\AttributeDisplayType::SELECT->value,
                                            ];
                                        @endphp

                                        <div class="rounded-xl border border-zinc-200 bg-zinc-50 p-3">
                                            <label for="variants-{{ $variantIndex }}-attributes-{{ $attributeIndex }}-name" class="mb-2 block text-xs font-semibold text-zinc-600">
                                                Nazwa atrybutu
                                            </label>

                                            <input
                                                id="variants-{{ $variantIndex }}-attributes-{{ $attributeIndex }}-name"
                                                type="text"
                                                name="variants[{{ $variantIndex }}][attributes][{{ $attributeIndex }}][name]"
                                                value="{{ $attribute['name'] ?? '' }}"
                                                placeholder="np. Rozmiar"
                                                class="@error('variants.'.$variantIndex.'.attributes.'.$attributeIndex.'.name') border-red-300 ring-red-100 @else border-zinc-300 @enderror block w-full rounded-xl border bg-white px-3 py-2 text-sm text-zinc-900 outline-none transition focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100"
                                            >

                                            @error('variants.'.$variantIndex.'.attributes.'.$attributeIndex.'.name')
                                            <p class="mt-2 text-xs text-red-600">{{ $message }}</p>
                                            @enderror

                                            <label for="variants-{{ $variantIndex }}-attributes-{{ $attributeIndex }}-value" class="mb-2 mt-3 block text-xs font-semibold text-zinc-600">
                                                Wartość
                                            </label>

                                            <input
                                                id="variants-{{ $variantIndex }}-attributes-{{ $attributeIndex }}-value"
                                                type="text"
                                                name="variants[{{ $variantIndex }}][attributes][{{ $attributeIndex }}][value]"
                                                value="{{ $attribute['value'] ?? '' }}"
                                                placeholder="np. M"
                                                class="@error('variants.'.$variantIndex.'.attributes.'.$attributeIndex.'.value') border-red-300 ring-red-100 @else border-zinc-300 @enderror block w-full rounded-xl border bg-white px-3 py-2 text-sm text-zinc-900 outline-none transition focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100"
                                            >

                                            @error('variants.'.$variantIndex.'.attributes.'.$attributeIndex.'.value')
                                            <p class="mt-2 text-xs text-red-600">{{ $message }}</p>
                                            @enderror

                                            <label for="variants-{{ $variantIndex }}-attributes-{{ $attributeIndex }}-display-type" class="mb-2 mt-3 block text-xs font-semibold text-zinc-600">
                                                Typ wyświetlania
                                            </label>

                                            <select
                                                id="variants-{{ $variantIndex }}-attributes-{{ $attributeIndex }}-display-type"
                                                name="variants[{{ $variantIndex }}][attributes][{{ $attributeIndex }}][display_type]"
                                                class="@error('variants.'.$variantIndex.'.attributes.'.$attributeIndex.'.display_type') border-red-300 ring-red-100 @else border-zinc-300 @enderror block w-full rounded-xl border bg-white px-3 py-2 text-sm text-zinc-900 outline-none transition focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100"
                                            >
                                                @foreach (\App\Enums\AttributeDisplayType::cases() as $displayType)
                                                    <option value="{{ $displayType->value }}" @selected(($attribute['display_type'] ?? \App\Enums\AttributeDisplayType::SELECT->value) === $displayType->value)>
                                                        {{ $displayType->label() }}
                                                    </option>
                                                @endforeach
                                            </select>

                                            @error('variants.'.$variantIndex.'.attributes.'.$attributeIndex.'.display_type')
                                            <p class="mt-2 text-xs text-red-600">{{ $message }}</p>
                                            @enderror
                                        </div>
                                    @endfor
                                </div>
                            </div>
                        </div>
                    @endfor
                </div>
            </div>

            <div class="flex flex-col-reverse gap-3 border-t border-zinc-200 pt-6 sm:flex-row sm:justify-end">
                <a
                    href="{{ route('admin.products.index') }}"
                    class="rounded-xl border border-zinc-300 px-5 py-3 text-center text-sm font-semibold text-zinc-700 hover:bg-zinc-50"
                >
                    Anuluj
                </a>

                <button class="rounded-xl bg-zinc-900 px-5 py-3 text-sm font-semibold text-white hover:bg-zinc-700">
                    Utwórz produkt
                </button>
            </div>
        </form>
    </div>

<script>
        document.addEventListener('DOMContentLoaded', () => {
            const list = document.querySelector('[data-product-variant-list]');
            const addButton = document.querySelector('[data-add-product-variant]');

            if (!list || !addButton) {
                return;
            }

            const cards = () => Array.from(list.querySelectorAll('[data-product-variant-card]'));

            const reindexCard = (card, index, clearValues = false) => {
                card.dataset.variantIndex = String(index);

                const title = card.querySelector('[data-variant-title]');
                if (title) {
                    title.textContent = `Wariant ${index + 1}`;
                }

                card.querySelectorAll('[name]').forEach((element) => {
                    element.name = element.name.replace(/variants\[\d+\]/g, `variants[${index}]`);
                });

                card.querySelectorAll('[id]').forEach((element) => {
                    element.id = element.id.replace(/variants-\d+-/g, `variants-${index}-`);
                });

                card.querySelectorAll('label[for]').forEach((element) => {
                    element.htmlFor = element.htmlFor.replace(/variants-\d+-/g, `variants-${index}-`);
                });

                const defaultRadio = card.querySelector('input[type="radio"][name="default_variant_index"]');
                if (defaultRadio) {
                    defaultRadio.value = String(index);

                    if (clearValues) {
                        defaultRadio.checked = false;
                    }
                }

                const removeButton = card.querySelector('[data-remove-product-variant]');
                if (removeButton) {
                    removeButton.hidden = index === 0;
                }

                if (!clearValues) {
                    return;
                }

                card.querySelectorAll('p.text-red-600').forEach((element) => element.remove());

                card.querySelectorAll('input').forEach((input) => {
                    if (input.type === 'radio') {
                        return;
                    }

                    input.value = '';
                });

                card.querySelectorAll('select').forEach((select) => {
                    select.selectedIndex = 0;
                });
            };

            const refreshVariantCards = () => {
                cards().forEach((card, index) => reindexCard(card, index));

                if (!list.querySelector('input[type="radio"][name="default_variant_index"]:checked')) {
                    const firstDefaultRadio = list.querySelector('input[type="radio"][name="default_variant_index"]');
                    if (firstDefaultRadio) {
                        firstDefaultRadio.checked = true;
                    }
                }
            };

            addButton.addEventListener('click', () => {
                const existingCards = cards();
                const sourceCard = existingCards[existingCards.length - 1];

                if (!sourceCard) {
                    return;
                }

                const newCard = sourceCard.cloneNode(true);
                reindexCard(newCard, existingCards.length, true);

                list.appendChild(newCard);
                refreshVariantCards();
            });

            list.addEventListener('click', (event) => {
                const removeButton = event.target.closest('[data-remove-product-variant]');

                if (!removeButton) {
                    return;
                }

                const existingCards = cards();
                if (existingCards.length <= 1) {
                    return;
                }

                const card = removeButton.closest('[data-product-variant-card]');
                const removedDefault = card?.querySelector('input[type="radio"][name="default_variant_index"]')?.checked ?? false;

                card?.remove();
                refreshVariantCards();

                if (removedDefault) {
                    const firstDefaultRadio = list.querySelector('input[type="radio"][name="default_variant_index"]');
                    if (firstDefaultRadio) {
                        firstDefaultRadio.checked = true;
                    }
                }
            });
        });
</script>

@endsection
