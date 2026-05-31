@extends('layouts.storefront')

@section('content')
    <div class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
        <div class="mb-8 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <a
                    href="{{ route('admin.orders.index') }}"
                    class="text-sm font-medium text-zinc-500 hover:text-zinc-700"
                >
                    ← Back to admin orders
                </a>

                <h1 class="mt-3 text-3xl font-bold tracking-tight text-zinc-900">
                    Contract withdrawals
                </h1>

                <p class="mt-2 text-sm text-zinc-600">
                    Review customer withdrawal requests submitted through the one-click withdrawal flow.
                </p>
            </div>
        </div>

        <div class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-zinc-200">
                    <thead class="bg-zinc-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">
                            Reference
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">
                            Order
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">
                            Customer
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">
                            Status
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">
                            Submitted
                        </th>
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-zinc-500">
                            Actions
                        </th>
                    </tr>
                    </thead>

                    <tbody class="divide-y divide-zinc-200 bg-white">
                    @forelse ($withdrawalRequests as $withdrawalRequest)
                        <tr>
                            <td class="px-4 py-4 text-sm font-medium text-zinc-900">
                                {{ $withdrawalRequest->number }}
                            </td>

                            <td class="px-4 py-4 text-sm text-zinc-700">
                                @if ($withdrawalRequest->order)
                                    <a
                                        href="{{ route('admin.orders.show', $withdrawalRequest->order) }}"
                                        class="font-medium text-zinc-900 hover:underline"
                                    >
                                        {{ $withdrawalRequest->order_number_snapshot }}
                                    </a>
                                @else
                                    {{ $withdrawalRequest->order_number_snapshot }}
                                @endif
                            </td>

                            <td class="px-4 py-4 text-sm text-zinc-700">
                                <div class="font-medium text-zinc-900">
                                    {{ $withdrawalRequest->customer_name }}
                                </div>
                                <div class="mt-1 text-zinc-500">
                                    {{ $withdrawalRequest->customer_email }}
                                </div>
                            </td>

                            <td class="px-4 py-4 text-sm">
                                <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold {{ $withdrawalRequest->status->badgeColorClasses() }}">
                                    {{ \Illuminate\Support\Str::headline($withdrawalRequest->status->value) }}
                                </span>
                            </td>

                            <td class="px-4 py-4 text-sm text-zinc-700">
                                {{ $withdrawalRequest->submitted_at?->format('Y-m-d H:i') ?? '—' }}
                            </td>

                            <td class="px-4 py-4 text-right text-sm">
                                <a
                                    href="{{ route('admin.withdrawals.show', $withdrawalRequest) }}"
                                    class="font-medium text-zinc-900 hover:underline"
                                >
                                    View
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-8 text-center text-sm text-zinc-500">
                                No contract withdrawal requests have been submitted yet.
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>

            @if ($withdrawalRequests->hasPages())
                <div class="border-t border-zinc-200 px-4 py-4">
                    {{ $withdrawalRequests->links() }}
                </div>
            @endif
        </div>
    </div>
@endsection
