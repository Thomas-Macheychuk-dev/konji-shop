@extends('layouts.storefront')

@section('content')
    <div class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
        <div class="mb-8 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <a href="{{ route('admin.orders.index') }}" class="text-sm font-medium text-zinc-500 hover:text-zinc-700">
                    ← Wróć do zamówień administratora
                </a>

                <h1 class="mt-3 text-3xl font-bold tracking-tight text-zinc-900">Reklamacje</h1>

                <p class="mt-2 text-sm text-zinc-600">
                    Rejestr reklamacji z tytułu niezgodności towaru z umową. Odpowiedź należy wysłać w ciągu 14 dni od otrzymania.
                </p>
            </div>

            <a href="{{ route('admin.complaints.create') }}" class="inline-flex items-center justify-center self-start rounded-xl bg-zinc-900 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-zinc-800">
                Zarejestruj reklamację
            </a>
        </div>

        @if ($needsAttentionCount > 0)
            <div class="mb-6 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                {{ trans_choice(':count reklamacja wymaga odpowiedzi w ciągu najbliższych dni lub jest po terminie.|:count reklamacje wymagają odpowiedzi w ciągu najbliższych dni lub są po terminie.|:count reklamacji wymaga odpowiedzi w ciągu najbliższych dni lub jest po terminie.', $needsAttentionCount, ['count' => $needsAttentionCount]) }}
            </div>
        @endif

        <div class="mb-4 flex gap-2 text-sm">
            <a href="{{ route('admin.complaints.index') }}" @class([
                'rounded-full px-3 py-1.5 font-semibold',
                'bg-zinc-900 text-white' => $filter === 'open',
                'bg-zinc-100 text-zinc-700 hover:bg-zinc-200' => $filter !== 'open',
            ])>Oczekujące na odpowiedź ({{ $awaitingCount }})</a>
            <a href="{{ route('admin.complaints.index', ['filter' => 'all']) }}" @class([
                'rounded-full px-3 py-1.5 font-semibold',
                'bg-zinc-900 text-white' => $filter === 'all',
                'bg-zinc-100 text-zinc-700 hover:bg-zinc-200' => $filter !== 'all',
            ])>Wszystkie</a>
        </div>

        <div class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-zinc-200">
                    <thead class="bg-zinc-50">
                    <tr>
                        @foreach (['Numer', 'Klient', 'Towar', 'Otrzymano', 'Termin', 'Status'] as $heading)
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">{{ $heading }}</th>
                        @endforeach
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-zinc-500">Akcje</th>
                    </tr>
                    </thead>

                    <tbody class="divide-y divide-zinc-200 bg-white">
                    @forelse ($complaints as $complaint)
                        <tr>
                            <td class="px-4 py-4 text-sm font-medium text-zinc-900">
                                {{ $complaint->number }}
                                @if ($complaint->order_number_snapshot)
                                    <div class="mt-1 text-xs font-normal text-zinc-500">Zam. {{ $complaint->order_number_snapshot }}</div>
                                @endif
                            </td>
                            <td class="px-4 py-4 text-sm text-zinc-700">
                                <div class="font-medium text-zinc-900">{{ $complaint->customer_name }}</div>
                                @if ($complaint->customer_email)
                                    <div class="mt-1 text-zinc-500">{{ $complaint->customer_email }}</div>
                                @endif
                            </td>
                            <td class="max-w-xs px-4 py-4 text-sm text-zinc-700">
                                {{ \Illuminate\Support\Str::limit($complaint->product_description, 60) }}
                            </td>
                            <td class="px-4 py-4 text-sm text-zinc-700">
                                {{ $complaint->received_at->format('Y-m-d') }}
                            </td>
                            <td class="px-4 py-4 text-sm">
                                <div class="text-zinc-700">{{ $complaint->response_due_on->format('Y-m-d') }}</div>
                                <span class="mt-1 inline-flex rounded-full px-2.5 py-1 text-xs font-semibold {{ $complaint->deadlineBadgeClasses() }}">
                                    {{ $complaint->deadlineLabel() }}
                                </span>
                            </td>
                            <td class="px-4 py-4 text-sm">
                                <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold {{ $complaint->status->badgeColorClasses() }}">
                                    {{ $complaint->status->label() }}
                                </span>
                            </td>
                            <td class="px-4 py-4 text-right text-sm">
                                <a href="{{ route('admin.complaints.show', $complaint) }}" class="font-medium text-zinc-900 hover:underline">Zobacz</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-8 text-center text-sm text-zinc-500">
                                {{ $filter === 'open' ? 'Brak reklamacji oczekujących na odpowiedź.' : 'Nie zarejestrowano jeszcze reklamacji.' }}
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>

            @if ($complaints->hasPages())
                <div class="border-t border-zinc-200 px-4 py-4">
                    {{ $complaints->links() }}
                </div>
            @endif
        </div>
    </div>
@endsection
