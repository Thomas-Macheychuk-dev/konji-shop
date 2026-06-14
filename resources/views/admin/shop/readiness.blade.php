@extends('layouts.storefront')

@section('content')
    <div class="mx-auto max-w-6xl px-4 py-10 sm:px-6 lg:px-8">
        <div class="mb-8 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <a
                    href="{{ route('admin.orders.index') }}"
                    class="text-sm font-medium text-zinc-500 hover:text-zinc-700"
                >
                    ← Wróć do zamówień administratora
                </a>

                <h1 class="mt-3 text-3xl font-bold tracking-tight text-zinc-900">
                    Gotowość produkcyjna
                </h1>

                <p class="mt-2 text-sm text-zinc-600">
                    Check whether the shop has the critical configuration required before going live.
                </p>
            </div>

            <div class="rounded-2xl border {{ $ready ? 'border-green-200 bg-green-50 text-green-900' : 'border-red-200 bg-red-50 text-red-900' }} px-5 py-4 text-sm shadow-sm">
                <p class="font-semibold">
                    {{ $ready ? 'Gotowe do produkcji' : 'Nie gotowe do produkcji' }}
                </p>

                <p class="mt-1 text-xs">
                    {{ collect($items)->where('required', true)->where('status', '!=', 'ready')->count() }}
                    required issue(s)
                </p>
            </div>
        </div>

        <div class="grid gap-4 sm:grid-cols-3">
            <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm">
                <p class="text-sm text-zinc-500">Ready</p>
                <p class="mt-2 text-2xl font-bold text-green-700">
                    {{ collect($items)->where('status', 'ready')->count() }}
                </p>
            </div>

            <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm">
                <p class="text-sm text-zinc-500">Warnings</p>
                <p class="mt-2 text-2xl font-bold text-amber-700">
                    {{ collect($items)->where('status', 'warning')->count() }}
                </p>
            </div>

            <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm">
                <p class="text-sm text-zinc-500">Brakujące</p>
                <p class="mt-2 text-2xl font-bold text-red-700">
                    {{ collect($items)->where('status', 'missing')->count() }}
                </p>
            </div>
        </div>

        @if (session('status'))
            <div class="mt-6 rounded-2xl border border-green-200 bg-green-50 px-5 py-4 text-sm font-medium text-green-900 shadow-sm">
                {{ session('status') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="mt-6 rounded-2xl border border-red-200 bg-red-50 px-5 py-4 text-sm text-red-900 shadow-sm">
                <p class="font-semibold">Nie zapisano ustawień. Popraw zaznaczone pola.</p>
            </div>
        @endif

        <div class="mt-6 rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h2 class="text-lg font-semibold text-zinc-900">
                        Ustawienia gotowości produkcyjnej
                    </h2>

                    <p class="mt-1 text-sm text-zinc-600">
                        Uzupełnij najważniejsze dane sklepu używane przez kontrole produkcyjne, wiadomości e-mail i integrację Polkurier.
                    </p>
                </div>
            </div>

            <form method="POST" action="{{ route('admin.shop.readiness.update') }}" class="mt-6 space-y-8">
                @csrf
                @method('PATCH')

                @foreach (collect($settingsFields)->groupBy('category', true) as $category => $fields)
                    <section class="rounded-2xl border border-zinc-100 bg-zinc-50/70 p-5">
                        <h3 class="text-sm font-semibold uppercase tracking-wide text-zinc-500">
                            {{ $category }}
                        </h3>

                        <div class="mt-4 grid gap-5 lg:grid-cols-2">
                            @foreach ($fields as $name => $field)
                                @php
                                    $fieldId = 'settings_'.$name;
                                    $fieldName = 'settings['.$name.']';
                                    $fieldValue = old('settings.'.$name, $settingsValues[$name] ?? '');
                                    $fieldError = $errors->first('settings.'.$name);
                                @endphp

                                <div class="{{ $field['type'] === 'textarea' ? 'lg:col-span-2' : '' }}">
                                    <label for="{{ $fieldId }}" class="block text-sm font-semibold text-zinc-900">
                                        {{ $field['label'] }}
                                        @if ($field['required'])
                                            <span class="text-red-600">*</span>
                                        @endif
                                    </label>

                                    @if ($field['type'] === 'textarea')
                                        <textarea
                                            id="{{ $fieldId }}"
                                            name="{{ $fieldName }}"
                                            rows="4"
                                            @if ($field['required']) required @endif
                                            class="mt-2 block w-full rounded-xl border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 shadow-sm focus:border-zinc-900 focus:outline-none focus:ring-2 focus:ring-zinc-900/10"
                                        >{{ $fieldValue }}</textarea>
                                    @else
                                        <input
                                            id="{{ $fieldId }}"
                                            name="{{ $fieldName }}"
                                            type="{{ $field['type'] }}"
                                            value="{{ $fieldValue }}"
                                            @if ($field['required']) required @endif
                                            autocomplete="{{ $field['autocomplete'] ?? 'on' }}"
                                            class="mt-2 block w-full rounded-xl border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 shadow-sm focus:border-zinc-900 focus:outline-none focus:ring-2 focus:ring-zinc-900/10"
                                        >
                                    @endif

                                    @if (! empty($field['help']))
                                        <p class="mt-1 text-xs text-zinc-500">
                                            {{ $field['help'] }}
                                        </p>
                                    @endif

                                    @if ($fieldError)
                                        <p class="mt-1 text-xs font-medium text-red-700">
                                            {{ $fieldError }}
                                        </p>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </section>
                @endforeach

                <div class="flex justify-end">
                    <button
                        type="submit"
                        class="inline-flex items-center rounded-xl bg-zinc-900 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-zinc-700 focus:outline-none focus:ring-2 focus:ring-zinc-900 focus:ring-offset-2"
                    >
                        Zapisz ustawienia
                    </button>
                </div>
            </form>
        </div>

        <div class="mt-6 overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm">
            <div class="border-b border-zinc-200 px-6 py-4">
                <h2 class="text-lg font-semibold text-zinc-900">
                    Kontrole konfiguracji
                </h2>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-zinc-200">
                    <thead class="bg-zinc-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">
                            Kategoria
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">
                            Check
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">
                            Status
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">
                            Required
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">
                            Komunikat
                        </th>
                    </tr>
                    </thead>

                    <tbody class="divide-y divide-zinc-200 bg-white">
                    @foreach ($items as $item)
                        @php
                            $statusClasses = match ($item['status']) {
                                'ready' => 'bg-green-100 text-green-800',
                                'warning' => 'bg-amber-100 text-amber-800',
                                'missing' => 'bg-red-100 text-red-800',
                                default => 'bg-zinc-100 text-zinc-800',
                            };
                        @endphp

                        <tr>
                            <td class="px-4 py-4 text-sm font-medium text-zinc-900">
                                {{ $item['category'] }}
                            </td>

                            <td class="px-4 py-4 text-sm text-zinc-700">
                                {{ $item['name'] }}
                            </td>

                            <td class="px-4 py-4 text-sm">
                                <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold {{ $statusClasses }}">
                                    {{ ucfirst($item['status']) }}
                                </span>
                            </td>

                            <td class="px-4 py-4 text-sm text-zinc-700">
                                {{ $item['required'] ? 'Yes' : 'No' }}
                            </td>

                            <td class="px-4 py-4 text-sm text-zinc-700">
                                {{ $item['message'] }}
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-6 rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm">
            <h2 class="text-lg font-semibold text-zinc-900">
                Polecenie konsoli
            </h2>

            <p class="mt-2 text-sm text-zinc-600">
                Te same kontrole są dostępne z poziomu CLI:
            </p>

            <pre class="mt-4 overflow-x-auto rounded-xl bg-zinc-900 p-4 text-sm text-white"><code>php artisan shop:check
php artisan shop:check --json</code></pre>
        </div>
    </div>
@endsection
