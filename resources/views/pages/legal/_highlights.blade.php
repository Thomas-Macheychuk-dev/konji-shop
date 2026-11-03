{{-- Short summary tiles shown above the full text of a legal page. --}}
<div class="mt-8 grid gap-3 sm:grid-cols-3">
    @foreach ($items as $item)
        <div class="rounded-xl bg-zinc-50 px-4 py-4 ring-1 ring-zinc-200">
            <p class="text-sm font-semibold text-zinc-900">{{ $item['title'] }}</p>
            <p class="mt-1 text-sm leading-6 text-zinc-600">{!! $item['text'] !!}</p>
        </div>
    @endforeach
</div>
