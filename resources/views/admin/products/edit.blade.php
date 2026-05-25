@extends('layouts.storefront')

@section('content')
    <div class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
        <div class="mb-8">
            <a href="{{ route('admin.products.index') }}" class="text-sm font-medium text-zinc-500 hover:text-zinc-700">
                ← Back to products
            </a>

            <div class="mt-3 flex items-start justify-between gap-4">
                <div>
                    <h1 class="text-3xl font-bold tracking-tight text-zinc-900">
                        {{ $product->name }}
                    </h1>

                    <p class="mt-2 text-sm text-zinc-600">
                        Edit product details and package data for variants.
                    </p>
                </div>

                <a
                    href="{{ route('products.show', $product) }}"
                    class="rounded-xl border border-zinc-300 px-4 py-2 text-sm font-semibold text-zinc-700 hover:bg-zinc-50"
                >
                    View product
                </a>
            </div>
        </div>

        @if (session('success'))
            <div class="mb-6 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
                {{ session('success') }}
            </div>
        @endif

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm">
                <h2 class="text-lg font-semibold text-zinc-900">Product details</h2>

                <form
                    method="POST"
                    action="{{ route('admin.products.update', $product) }}"
                    class="mt-4 space-y-5"
                >
                    @csrf
                    @method('PATCH')

                    <div>
                        <label for="name" class="mb-2 block text-sm font-medium text-zinc-700">
                            Product name
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
                            Product status
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

                    <dl class="space-y-3 text-sm">
                        <div>
                            <dt class="text-zinc-500">Slug</dt>
                            <dd class="font-medium text-zinc-900">{{ $product->slug }}</dd>
                        </div>

                        <div>
                            <dt class="text-zinc-500">External source</dt>
                            <dd class="font-medium text-zinc-900">{{ $product->external_source ?: '—' }}</dd>
                        </div>

                        <div>
                            <dt class="text-zinc-500">External ID</dt>
                            <dd class="font-medium text-zinc-900">{{ $product->external_id ?: '—' }}</dd>
                        </div>
                    </dl>

                    <div class="flex justify-end border-t border-zinc-200 pt-5">
                        <button class="rounded-xl bg-zinc-900 px-4 py-2 text-sm font-semibold text-white hover:bg-zinc-700">
                            Save product
                        </button>
                    </div>
                </form>
            </div>

            <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm lg:col-span-2">
                <h2 class="text-lg font-semibold text-zinc-900">
                    Apply package data to all variants
                </h2>

                <p class="mt-2 text-sm text-zinc-500">
                    Use this when all variants of this product have the same shipping package.
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
                                Weight
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
                                Length
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
                                Width
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
                                Height
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
                            Apply to all variants
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div class="mt-6 rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm">
            <div class="mb-4">
                <h2 class="text-lg font-semibold text-zinc-900">
                    Variant package data
                </h2>

                <p class="mt-2 text-sm text-zinc-500">
                    Save individual package data when variants differ in size or weight.
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
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">Variant</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">Status</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">Package status</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-zinc-500">Weight g</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-zinc-500">Length mm</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-zinc-500">Width mm</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-zinc-500">Height mm</th>
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
                                            Missing weight/dimensions
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
                                    This product has no variants.
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
                        Save variant package data
                    </button>
                </div>
            </form>
        </div>
    </div>
@endsection
