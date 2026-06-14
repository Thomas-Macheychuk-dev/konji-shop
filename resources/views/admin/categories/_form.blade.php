@php
    $isEdit = isset($category);
@endphp

<div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm">
    <h2 class="text-lg font-semibold text-zinc-900">
        Szczegóły kategorii
    </h2>

    <p class="mt-2 text-sm text-zinc-500">
        Ustaw nazwę, adres URL, status widoczności oraz dane SEO kategorii.
    </p>

    <div class="mt-6 grid grid-cols-1 gap-5 lg:grid-cols-2">
        <div>
            <label for="name" class="mb-2 block text-sm font-medium text-zinc-700">
                Nazwa kategorii <span class="text-red-500">*</span>
            </label>

            <input
                id="name"
                type="text"
                name="name"
                value="{{ old('name', $category->name ?? '') }}"
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
                Slug <span class="text-red-500">*</span>
            </label>

            <input
                id="slug"
                type="text"
                name="slug"
                value="{{ old('slug', $category->slug ?? '') }}"
                maxlength="255"
                placeholder="Zostaw puste, aby wygenerować z nazwy"
                class="@error('slug') border-red-300 ring-red-100 @else border-zinc-300 @enderror block w-full rounded-xl border bg-white px-4 py-3 text-sm text-zinc-900 outline-none transition focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100"
            >

            @error('slug')
            <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="parent_id" class="mb-2 block text-sm font-medium text-zinc-700">
                Kategoria nadrzędna
            </label>

            <select
                id="parent_id"
                name="parent_id"
                class="@error('parent_id') border-red-300 ring-red-100 @else border-zinc-300 @enderror block w-full rounded-xl border bg-white px-4 py-3 text-sm text-zinc-900 outline-none transition focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100"
            >
                <option value="">Brak kategorii nadrzędnej</option>

                @foreach ($parentCategories as $parentCategory)
                    <option
                        value="{{ $parentCategory->id }}"
                        @selected((string) old('parent_id', $category->parent_id ?? '') === (string) $parentCategory->id)
                    >
                        {{ $parentCategory->name }}
                    </option>
                @endforeach
            </select>

            @error('parent_id')
            <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="status" class="mb-2 block text-sm font-medium text-zinc-700">
                Status kategorii <span class="text-red-500">*</span>
            </label>

            <select
                id="status"
                name="status"
                required
                class="@error('status') border-red-300 ring-red-100 @else border-zinc-300 @enderror block w-full rounded-xl border bg-white px-4 py-3 text-sm text-zinc-900 outline-none transition focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100"
            >
                @foreach (\App\Enums\CategoryStatus::cases() as $status)
                    <option value="{{ $status->value }}" @selected(old('status', ($category->status ?? \App\Enums\CategoryStatus::ACTIVE)->value) === $status->value)>
                        {{ $status->label() }}
                    </option>
                @endforeach
            </select>

            @error('status')
            <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>
    </div>

    <div class="mt-5">
        <label for="description" class="mb-2 block text-sm font-medium text-zinc-700">
            Opis kategorii
        </label>

        <textarea
            id="description"
            name="description"
            rows="6"
            class="@error('description') border-red-300 ring-red-100 @else border-zinc-300 @enderror block w-full rounded-xl border bg-white px-4 py-3 text-sm text-zinc-900 outline-none transition focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100"
        >{{ old('description', $category->description ?? '') }}</textarea>

        @error('description')
        <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
        @enderror
    </div>
</div>

<div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm">
    <h2 class="text-lg font-semibold text-zinc-900">
        SEO
    </h2>

    <p class="mt-2 text-sm text-zinc-500">
        Te pola są używane na stronie kategorii oraz w metatagach.
    </p>

    <div class="mt-6 grid grid-cols-1 gap-5 lg:grid-cols-2">
        <div>
            <label for="seo_title" class="mb-2 block text-sm font-medium text-zinc-700">
                Tytuł SEO
            </label>

            <input
                id="seo_title"
                type="text"
                name="seo_title"
                value="{{ old('seo_title', $category->seo_title ?? '') }}"
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
            >{{ old('seo_description', $category->seo_description ?? '') }}</textarea>

            @error('seo_description')
            <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>
    </div>
</div>

<div class="flex flex-wrap items-center justify-end gap-3">
    <a
        href="{{ route('admin.categories.index') }}"
        class="rounded-xl border border-zinc-300 px-5 py-3 text-sm font-semibold text-zinc-700 hover:bg-zinc-50"
    >
        Anuluj
    </a>

    <button class="rounded-xl bg-zinc-900 px-5 py-3 text-sm font-semibold text-white hover:bg-zinc-700">
        {{ $isEdit ? 'Zapisz kategorię' : 'Stwórz kategorię' }}
    </button>
</div>
