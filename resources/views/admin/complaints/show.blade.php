@extends('layouts.storefront')

@section('content')
    <div class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
        <div class="mb-8 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <a href="{{ route('admin.complaints.index') }}" class="text-sm font-medium text-zinc-500 hover:text-zinc-700">
                    ← Wróć do reklamacji
                </a>

                <h1 class="mt-3 text-3xl font-bold tracking-tight text-zinc-900">
                    Reklamacja {{ $complaint->number }}
                </h1>

                <p class="mt-2 text-sm text-zinc-600">
                    Otrzymano {{ $complaint->received_at->format('Y-m-d H:i') }} · {{ $complaint->channel->label() }}
                </p>
            </div>

            <div class="flex flex-wrap gap-2 self-start">
                <span class="inline-flex rounded-full px-3 py-1 text-sm font-semibold {{ $complaint->status->badgeColorClasses() }}">
                    {{ $complaint->status->label() }}
                </span>
                <span class="inline-flex rounded-full px-3 py-1 text-sm font-semibold {{ $complaint->deadlineBadgeClasses() }}">
                    {{ $complaint->deadlineLabel() }}
                </span>
            </div>
        </div>

        @if (session('success'))
            <div class="mb-6 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
                {{ session('success') }}
            </div>
        @endif

        @if ($complaint->deadlineState() === \App\Models\Complaint::DEADLINE_OVERDUE)
            <div class="mb-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                Termin odpowiedzi minął {{ $complaint->response_due_on->format('Y-m-d') }}. Zgodnie z § 12 ust. 8 Regulaminu brak odpowiedzi w terminie oznacza uznanie reklamacji.
            </div>
        @elseif ($complaint->deadlineState() === \App\Models\Complaint::DEADLINE_DUE_SOON)
            <div class="mb-6 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                Zbliża się termin odpowiedzi: {{ $complaint->response_due_on->format('Y-m-d') }}. Wyślij klientowi odpowiedź na trwałym nośniku.
            </div>
        @endif

        <div class="grid gap-8 lg:grid-cols-3">
            <form method="POST" action="{{ route('admin.complaints.update', $complaint) }}" class="space-y-6 lg:col-span-2">
                @csrf
                @method('PATCH')

                @include('admin.complaints._form', ['complaint' => $complaint])

                <button type="submit" class="inline-flex items-center justify-center rounded-xl bg-zinc-900 px-5 py-3 text-sm font-semibold text-white transition hover:bg-zinc-800">
                    Zapisz reklamację
                </button>
            </form>

            <div class="space-y-8">
                <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm">
                    <h2 class="text-lg font-semibold text-zinc-900">Terminy</h2>

                    <dl class="mt-5 space-y-3 text-sm">
                        <div>
                            <dt class="text-zinc-500">Otrzymano</dt>
                            <dd class="mt-1 font-medium text-zinc-900">{{ $complaint->received_at->format('Y-m-d H:i') }}</dd>
                        </div>
                        <div>
                            <dt class="text-zinc-500">Przypomnienie wewnętrzne</dt>
                            <dd class="mt-1 font-medium text-zinc-900">{{ $complaint->alertOn()->format('Y-m-d') }}</dd>
                        </div>
                        <div>
                            <dt class="text-zinc-500">Termin odpowiedzi</dt>
                            <dd class="mt-1 font-medium text-zinc-900">{{ $complaint->response_due_on->format('Y-m-d') }}</dd>
                        </div>
                        <div>
                            <dt class="text-zinc-500">Odpowiedź wysłana</dt>
                            <dd class="mt-1 font-medium text-zinc-900">
                                {{ $complaint->responded_at?->format('Y-m-d H:i') ?? '—' }}
                                @if ($complaint->response_channel)
                                    · {{ $complaint->response_channel->label() }}
                                @endif
                            </dd>
                        </div>
                    </dl>
                </div>

                <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm">
                    <h2 class="text-lg font-semibold text-zinc-900">Szczegóły</h2>

                    <dl class="mt-5 space-y-3 text-sm">
                        <div>
                            <dt class="text-zinc-500">Zamówienie</dt>
                            <dd class="mt-1 font-medium text-zinc-900">
                                @if ($complaint->order)
                                    <a href="{{ route('admin.orders.show', $complaint->order) }}" class="hover:underline">{{ $complaint->order->number }}</a>
                                @elseif ($complaint->order_number_snapshot)
                                    {{ $complaint->order_number_snapshot }} <span class="text-zinc-500">(nie znaleziono w sklepie)</span>
                                @else
                                    —
                                @endif
                            </dd>
                        </div>
                        <div>
                            <dt class="text-zinc-500">Zarejestrował(a)</dt>
                            <dd class="mt-1 font-medium text-zinc-900">{{ $complaint->createdBy?->name ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-zinc-500">Utworzono w systemie</dt>
                            <dd class="mt-1 font-medium text-zinc-900">{{ $complaint->created_at?->format('Y-m-d H:i') }}</dd>
                        </div>
                    </dl>
                </div>
            </div>
        </div>
    </div>
@endsection
