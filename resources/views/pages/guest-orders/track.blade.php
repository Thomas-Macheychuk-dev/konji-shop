@extends('layouts.storefront')

@section('content')
    <div class="mx-auto max-w-3xl px-4 py-10 sm:px-6 lg:px-8">
        <div class="mb-8">
            <h1 class="text-3xl font-bold tracking-tight text-zinc-900">Track your order</h1>
            <p class="mt-2 text-sm text-zinc-600">
                Enter your order number and the email address used during checkout.
            </p>
        </div>

        <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm">
            <form method="POST" action="{{ route('guest.orders.track.lookup') }}" class="space-y-6">
                @csrf

                <div>
                    <label for="number" class="block text-sm font-medium text-zinc-900">
                        Order number
                    </label>
                    <input
                        id="number"
                        name="number"
                        type="text"
                        value="{{ old('number') }}"
                        placeholder="20260328-8172"
                        class="mt-2 block w-full rounded-xl border border-zinc-300 px-4 py-3 text-sm text-zinc-900 shadow-sm outline-none transition focus:border-zinc-900 focus:ring-0"
                        required
                    >
                    @error('number')
                    <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="email" class="block text-sm font-medium text-zinc-900">
                        Email address
                    </label>
                    <input
                        id="email"
                        name="email"
                        type="email"
                        value="{{ old('email') }}"
                        placeholder="you@example.com"
                        class="mt-2 block w-full rounded-xl border border-zinc-300 px-4 py-3 text-sm text-zinc-900 shadow-sm outline-none transition focus:border-zinc-900 focus:ring-0"
                        required
                    >
                    @error('email')
                    <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <button
                        type="submit"
                        class="inline-flex items-center rounded-xl bg-zinc-900 px-5 py-3 text-sm font-medium text-white transition hover:bg-zinc-800"
                    >
                        Check order status
                    </button>
                </div>
            </form>
        </div>
    </div>
@endsection
