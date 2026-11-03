@extends('layouts.storefront')

@push('styles')
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/trix@2.1.19/dist/trix.css">
@endpush

@push('scripts')
    @include('admin.partials.html-editor-scripts')
@endpush

@section('content')
    @php
        $field = 'block w-full rounded-xl border bg-white px-4 py-3 text-sm text-zinc-900 outline-none transition focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100';
        // The preview mimics how the content is styled on the public page.
        $previewClass = match ($editablePage) {
            \App\Enums\EditablePage::RENTAL => 'bg-[#155fa8] text-sm leading-6 text-blue-100 [&_a]:underline',
            \App\Enums\EditablePage::CONTACT => 'bg-white',
            default => 'prose prose-zinc max-w-none bg-white prose-h2:mt-8 prose-h2:text-xl prose-a:font-medium',
        };
    @endphp

    <div class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
        <div class="mb-8">
            <a href="{{ route('admin.pages.index') }}" class="text-sm font-medium text-zinc-500 hover:text-zinc-700">
                ← Wróć do listy stron
            </a>

            <div class="mt-3 flex items-start justify-between gap-4">
                <div>
                    <h1 class="text-3xl font-bold tracking-tight text-zinc-900">
                        {{ $content->title }}
                    </h1>

                    <p class="mt-2 text-sm text-zinc-600">
                        /{{ $editablePage->value }} ·
                        {{ $customised ? 'treść zmieniona w edytorze' : 'treść domyślna' }}
                    </p>
                </div>

                <a
                    href="{{ route($editablePage->routeName()) }}"
                    target="_blank"
                    class="rounded-xl border border-zinc-300 px-4 py-2 text-sm font-semibold text-zinc-700 hover:bg-zinc-50"
                >
                    Zobacz stronę
                </a>
            </div>
        </div>

        @if (session('success'))
            <div class="mb-6 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
                {{ session('success') }}
            </div>
        @endif

        <form
            method="POST"
            action="{{ route('admin.pages.update', $editablePage) }}"
            class="space-y-6 rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm"
        >
            @csrf
            @method('PATCH')

            <p class="rounded-xl bg-zinc-50 px-4 py-3 text-sm text-zinc-600">
                Pole pozostawione puste albo bez zmian korzysta z treści domyślnej. Dane firmy w treści domyślnej
                (e-mail, telefon, adres) aktualizują się same; po zmianie tekstu stają się zwykłym tekstem do ręcznej edycji.
            </p>

            @if ($editablePage === \App\Enums\EditablePage::RENTAL)
                <p class="rounded-xl bg-blue-50 px-4 py-3 text-sm text-blue-800">
                    Sprzęt, ceny i zdjęcia edytujesz w sekcji
                    <a href="{{ route('admin.rental.index') }}" class="font-semibold underline underline-offset-4">Wypożyczalnia</a>.
                </p>
            @endif

            <div>
                <label for="title" class="mb-2 block text-sm font-medium text-zinc-700">
                    Tytuł strony (nagłówek H1)
                </label>

                <input
                    id="title"
                    type="text"
                    name="title"
                    value="{{ old('title', $content->title) }}"
                    class="@error('title') border-red-300 ring-red-100 @else border-zinc-300 @enderror {{ $field }}"
                >

                @error('title')
                <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="intro" class="mb-2 block text-sm font-medium text-zinc-700">
                    Wstęp pod tytułem (HTML)
                </label>

                <textarea
                    id="intro"
                    name="intro"
                    rows="4"
                    spellcheck="false"
                    class="@error('intro') border-red-300 ring-red-100 @else border-zinc-300 @enderror font-mono {{ $field }}"
                >{{ old('intro', $content->intro) }}</textarea>

                @error('intro')
                <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div data-html-editor>
                <div class="mb-2 flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                    <label for="content" class="block text-sm font-medium text-zinc-700">
                        Treść HTML strony
                    </label>

                    <span class="text-xs font-medium text-zinc-500">
                        Edytor Trix + kod HTML + podgląd
                    </span>
                </div>

                <p class="mb-4 text-xs text-zinc-500">{{ $editablePage->contentHint() }}</p>

                <div class="grid gap-4 xl:grid-cols-2">
                    <div>
                        <label for="content" class="mb-2 block text-xs font-semibold uppercase tracking-wide text-zinc-500">
                            Kod HTML zapisywany na stronie
                        </label>

                        <textarea
                            id="content"
                            name="content"
                            rows="24"
                            spellcheck="false"
                            data-html-description-source
                            class="@error('content') border-red-300 ring-red-100 @else border-zinc-300 @enderror font-mono {{ $field }}"
                        >{{ old('content', $content->content) }}</textarea>

                        <p class="mt-2 text-xs text-zinc-500">
                            Ten kod HTML jest źródłem prawdy i zostanie zapisany dokładnie w tym polu.
                            Przy klasach, blokach i zaawansowanym HTML edytuj przede wszystkim tutaj.
                        </p>
                    </div>

                    <div class="space-y-4">
                        <div>
                            <label for="content_trix" class="mb-2 block text-xs font-semibold uppercase tracking-wide text-zinc-500">
                                Edytor wizualny Trix
                            </label>

                            <input
                                id="content_trix_input"
                                type="hidden"
                                data-html-description-trix-input
                                value="{{ old('content', $content->content) }}"
                            >

                            <trix-editor
                                id="content_trix"
                                input="content_trix_input"
                                class="admin-trix-editor min-h-64 rounded-xl border border-zinc-300 bg-white text-sm text-zinc-900"
                            ></trix-editor>

                            <p class="mt-2 text-xs text-zinc-500">
                                Trix nadaje się do szybkiej edycji tekstu, pogrubień, linków i list.
                                Usuwa klasy CSS i niestandardowe tagi, więc złożone bloki sprawdzaj w kodzie HTML.
                            </p>
                        </div>

                        <div>
                            <div class="mb-2 flex items-center justify-between gap-3">
                                <span class="block text-xs font-semibold uppercase tracking-wide text-zinc-500">
                                    Podgląd HTML
                                </span>

                                <span class="text-xs text-zinc-400">Aktualizuje się podczas pisania</span>
                            </div>

                            <div
                                data-html-description-preview
                                data-empty-text="Pusta treść: strona pokaże treść domyślną."
                                class="admin-product-html-preview {{ $previewClass }} min-h-64 overflow-x-auto rounded-xl border border-zinc-200 p-4"
                            ></div>
                        </div>
                    </div>
                </div>

                @error('content')
                <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div class="grid gap-6 lg:grid-cols-2">
                <div>
                    <label for="seo_title" class="mb-2 block text-sm font-medium text-zinc-700">
                        Tytuł SEO
                    </label>

                    <input
                        id="seo_title"
                        type="text"
                        name="seo_title"
                        value="{{ old('seo_title', $content->seoTitle) }}"
                        class="@error('seo_title') border-red-300 ring-red-100 @else border-zinc-300 @enderror {{ $field }}"
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
                        class="@error('seo_description') border-red-300 ring-red-100 @else border-zinc-300 @enderror {{ $field }}"
                    >{{ old('seo_description', $content->seoDescription) }}</textarea>

                    @error('seo_description')
                    <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <div class="flex justify-end border-t border-zinc-100 pt-6">
                <button type="submit" class="rounded-xl bg-zinc-900 px-5 py-3 text-sm font-semibold text-white hover:bg-zinc-700">
                    Zapisz stronę
                </button>
            </div>
        </form>

        @if ($customised)
            <form
                method="POST"
                action="{{ route('admin.pages.reset', $editablePage) }}"
                class="mt-6 flex flex-col gap-3 rounded-2xl border border-red-200 bg-red-50 p-5 sm:flex-row sm:items-center sm:justify-between"
                onsubmit="return confirm('Przywrócić domyślną treść tej strony? Zmiany z edytora zostaną usunięte.')"
            >
                @csrf
                @method('DELETE')

                <p class="text-sm text-red-800">Usuwa wszystkie zmiany z edytora i przywraca treść domyślną strony.</p>

                <button type="submit" class="shrink-0 rounded-xl border border-red-300 bg-white px-4 py-2 text-sm font-semibold text-red-700 hover:bg-red-100">
                    Przywróć treść domyślną
                </button>
            </form>
        @endif
    </div>
@endsection
