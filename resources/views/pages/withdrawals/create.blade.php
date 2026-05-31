@extends('layouts.storefront')

@section('content')
    <div class="mx-auto max-w-4xl px-4 py-10 sm:px-6 lg:px-8">
        <div class="mb-8">
            <a href="{{ $backUrl }}" class="text-sm font-medium text-zinc-600 transition hover:text-zinc-900">
                ← Back to order
            </a>

            <h1 class="mt-3 text-3xl font-bold tracking-tight text-zinc-900">
                Withdraw from contract
            </h1>

            <p class="mt-2 text-sm text-zinc-600">
                Order {{ $order->number }}. Select the items and quantities you want to include in your withdrawal statement.
            </p>
        </div>

        @if (session('error'))
            <div class="mb-6 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                {{ session('error') }}
            </div>
        @endif

        <form method="POST" action="{{ $storeUrl }}" class="space-y-6">
            @csrf

            <input type="hidden" name="source" value="{{ $mode }}">

            <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm">
                <h2 class="text-lg font-semibold text-zinc-900">
                    Customer details
                </h2>

                <div class="mt-5 grid gap-5 sm:grid-cols-2">
                    <div>
                        <label for="customer_name" class="mb-2 block text-sm font-medium text-zinc-700">
                            Customer name
                        </label>

                        <input
                            id="customer_name"
                            name="customer_name"
                            type="text"
                            value="{{ old('customer_name', $customerName) }}"
                            class="@error('customer_name') border-red-300 ring-red-100 @else border-zinc-300 @enderror block w-full rounded-xl border bg-white px-4 py-3 text-sm text-zinc-900 shadow-sm outline-none transition focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100"
                        >

                        @error('customer_name')
                        <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="customer_email" class="mb-2 block text-sm font-medium text-zinc-700">
                            Confirmation email
                        </label>

                        <input
                            id="customer_email"
                            name="customer_email"
                            type="email"
                            value="{{ old('customer_email', $customerEmail) }}"
                            class="@error('customer_email') border-red-300 ring-red-100 @else border-zinc-300 @enderror block w-full rounded-xl border bg-white px-4 py-3 text-sm text-zinc-900 shadow-sm outline-none transition focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100"
                        >

                        @error('customer_email')
                        <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>
                </div>
            </div>

            <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm">
                <h2 class="text-lg font-semibold text-zinc-900">
                    Items
                </h2>

                <p class="mt-2 text-sm text-zinc-600">
                    Enter the quantity you want to withdraw. Leave quantity as 0 for items you do not want to include.
                </p>

                @error('items')
                <p class="mt-3 text-sm text-red-600">{{ $message }}</p>
                @enderror

                <div class="mt-5 divide-y divide-zinc-200">
                    @foreach ($order->items as $item)
                        @php
                            $alreadyRequested = $item
                                ->withdrawalRequestItems
                                ->filter(fn ($withdrawalItem) => ! $withdrawalItem->withdrawalRequest->isFinal())
                                ->sum('quantity_requested');

                            $remainingQuantity = max(0, (int) $item->quantity - (int) $alreadyRequested);
                        @endphp

                        <div class="py-5">
                            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                                <div>
                                    <p class="font-semibold text-zinc-900">
                                        {{ $item->product_name_snapshot }}
                                    </p>

                                    @if ($item->variant_name_snapshot)
                                        <p class="mt-1 text-sm text-zinc-600">
                                            {{ $item->variant_name_snapshot }}
                                        </p>
                                    @endif

                                    @if ($item->sku_snapshot)
                                        <p class="mt-1 text-sm text-zinc-500">
                                            SKU: {{ $item->sku_snapshot }}
                                        </p>
                                    @endif

                                    <p class="mt-2 text-sm text-zinc-500">
                                        Ordered: {{ $item->quantity }} · Available for withdrawal: {{ $remainingQuantity }}
                                    </p>
                                </div>

                                <div class="w-full sm:w-36">
                                    <label for="items_{{ $item->id }}" class="mb-2 block text-sm font-medium text-zinc-700">
                                        Quantity
                                    </label>

                                    <input
                                        id="items_{{ $item->id }}"
                                        name="items[{{ $item->id }}]"
                                        type="number"
                                        min="0"
                                        max="{{ $remainingQuantity }}"
                                        value="{{ old('items.'.$item->id, $remainingQuantity > 0 ? $remainingQuantity : 0) }}"
                                        @disabled($remainingQuantity < 1)
                                        class="@error('items.'.$item->id) border-red-300 ring-red-100 @else border-zinc-300 @enderror block w-full rounded-xl border bg-white px-4 py-3 text-sm text-zinc-900 shadow-sm outline-none transition focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100 disabled:cursor-not-allowed disabled:bg-zinc-100 disabled:text-zinc-400"
                                    >

                                    @error('items.'.$item->id)
                                    <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                                    @enderror
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm">
                <h2 class="text-lg font-semibold text-zinc-900">
                    Additional information
                </h2>

                <div class="mt-5 space-y-5">
                    <div>
                        <label for="reason" class="mb-2 block text-sm font-medium text-zinc-700">
                            Reason
                            <span class="text-zinc-400">(optional)</span>
                        </label>

                        <textarea
                            id="reason"
                            name="reason"
                            rows="3"
                            class="@error('reason') border-red-300 ring-red-100 @else border-zinc-300 @enderror block w-full rounded-xl border bg-white px-4 py-3 text-sm text-zinc-900 shadow-sm outline-none transition focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100"
                        >{{ old('reason') }}</textarea>

                        @error('reason')
                        <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="customer_note" class="mb-2 block text-sm font-medium text-zinc-700">
                            Message
                            <span class="text-zinc-400">(optional)</span>
                        </label>

                        <textarea
                            id="customer_note"
                            name="customer_note"
                            rows="3"
                            class="@error('customer_note') border-red-300 ring-red-100 @else border-zinc-300 @enderror block w-full rounded-xl border bg-white px-4 py-3 text-sm text-zinc-900 shadow-sm outline-none transition focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100"
                        >{{ old('customer_note') }}</textarea>

                        @error('customer_note')
                        <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="refund_note" class="mb-2 block text-sm font-medium text-zinc-700">
                            Refund note
                            <span class="text-zinc-400">(optional)</span>
                        </label>

                        <textarea
                            id="refund_note"
                            name="refund_note"
                            rows="3"
                            class="@error('refund_note') border-red-300 ring-red-100 @else border-zinc-300 @enderror block w-full rounded-xl border bg-white px-4 py-3 text-sm text-zinc-900 shadow-sm outline-none transition focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100"
                        >{{ old('refund_note') }}</textarea>

                        @error('refund_note')
                        <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label class="flex items-start gap-3 text-sm text-zinc-700">
                            <input
                                type="checkbox"
                                name="statement_confirmed"
                                value="1"
                                {{ old('statement_confirmed') ? 'checked' : '' }}
                                class="mt-1 h-4 w-4 rounded border-zinc-300 text-zinc-900 focus:ring-zinc-900"
                            >

                            <span>
                                I confirm that I want to withdraw from the contract for the selected item(s).
                            </span>
                        </label>

                        @error('statement_confirmed')
                        <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>
                </div>
            </div>

            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-end">
                <a
                    href="{{ $backUrl }}"
                    class="inline-flex items-center justify-center rounded-xl border border-zinc-300 px-5 py-3 text-sm font-semibold text-zinc-700 transition hover:bg-zinc-50"
                >
                    Cancel
                </a>

                <button
                    type="submit"
                    class="inline-flex items-center justify-center rounded-xl bg-zinc-900 px-5 py-3 text-sm font-semibold text-white transition hover:bg-zinc-800"
                >
                    Confirm withdrawal
                </button>
            </div>
        </form>
    </div>
@endsection
