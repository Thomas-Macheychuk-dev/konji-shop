@php
    $translationKey = 'admin.orders.timeline.metadata.'.str_replace('.', '_', (string) $key);
    $label = \Illuminate\Support\Facades\Lang::has($translationKey)
        ? __($translationKey)
        : str((string) $key)->headline();

    $isStructured = is_array($value) || is_object($value);

    $displayValue = match (true) {
        $isStructured => '',
        is_bool($value) => $value
            ? __('admin.orders.timeline.values.yes')
            : __('admin.orders.timeline.values.no'),
        $value === null => '—',
        default => (string) $value,
    };

    $prettyJson = $isStructured
        ? json_encode(
            $value,
            JSON_PRETTY_PRINT
                | JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_INVALID_UTF8_SUBSTITUTE
        )
        : null;
@endphp

<div class="grid min-w-0 grid-cols-1 gap-1 sm:grid-cols-[minmax(0,12rem)_minmax(0,1fr)] sm:gap-4">
    <dt class="min-w-0 font-medium text-zinc-600">
        {{ $label }}
    </dt>

    <dd class="min-w-0 text-zinc-700 sm:text-right">
        @if ($isStructured)
            <details class="min-w-0 overflow-hidden rounded-lg border border-zinc-200 bg-white text-left">
                <summary class="cursor-pointer select-none px-3 py-2 font-medium text-zinc-700 hover:bg-zinc-50">
                    {{ __('admin.orders.timeline.technical_details') }}
                </summary>

                <div class="min-w-0 border-t border-zinc-200 bg-zinc-950 p-3">
                    <pre class="max-h-80 max-w-full overflow-auto whitespace-pre-wrap break-words font-mono text-[11px] leading-5 text-zinc-100">{{ $prettyJson !== false ? $prettyJson : __('admin.orders.timeline.unavailable_value') }}</pre>
                </div>
            </details>
        @else
            <span class="block max-w-full break-words">
                {{ $displayValue }}
            </span>
        @endif
    </dd>
</div>
