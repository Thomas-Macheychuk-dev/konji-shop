@php
    $parentCategories = [
        'Knee supports',
        'Back supports',
        'Ankle supports',
        'Wrist supports',
        'Shoulder supports',
        'Posture correctors',
        'Compression sleeves',
        'Orthopedic braces',
        'Recovery therapy',
        'Cold & heat & testing long names therapy',
        'Massage tools',
    ];
@endphp

<div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
    <div class="mb-4">
        <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">
            Categories
        </h2>
    </div>

    <nav class="flex flex-col gap-2">
        @foreach ($parentCategories as $category)
            <a
                href="#"
                class="rounded-lg px-3 py-2 text-m text-zinc-700 transition hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-300 dark:hover:bg-zinc-800 dark:hover:text-white"
            >
                {{ $category }}
            </a>
        @endforeach
    </nav>
</div>
