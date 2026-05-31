@extends('layouts.storefront')

@section('content')
    <div class="mx-auto max-w-4xl px-4 py-10 sm:px-6 lg:px-8">
        <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm sm:p-8">
            <p class="text-sm font-medium text-zinc-500">
                Contract withdrawal
            </p>

            <h1 class="mt-2 text-3xl font-bold tracking-tight text-zinc-900">
                Withdraw from contract here
            </h1>

            <p class="mt-5 text-sm leading-6 text-zinc-700">
                You can submit an electronic withdrawal statement for an eligible online order.
                To continue, open your order and choose the item(s) you want to include in the withdrawal request.
            </p>

            <div class="mt-8 grid gap-4 sm:grid-cols-2">
                @auth
                    <a
                        href="{{ route('account.orders.index') }}"
                        class="rounded-2xl border border-zinc-200 bg-zinc-50 p-5 transition hover:bg-zinc-100"
                    >
                        <h2 class="text-base font-semibold text-zinc-900">
                            I have an account
                        </h2>

                        <p class="mt-2 text-sm text-zinc-600">
                            Go to your orders and select “Withdraw from contract” on the relevant order.
                        </p>

                        <span class="mt-4 inline-flex text-sm font-semibold text-zinc-900">
                            View my orders →
                        </span>
                    </a>
                @else
                    <a
                        href="{{ route('login') }}"
                        class="rounded-2xl border border-zinc-200 bg-zinc-50 p-5 transition hover:bg-zinc-100"
                    >
                        <h2 class="text-base font-semibold text-zinc-900">
                            I have an account
                        </h2>

                        <p class="mt-2 text-sm text-zinc-600">
                            Log in, open your order, and select “Withdraw from contract”.
                        </p>

                        <span class="mt-4 inline-flex text-sm font-semibold text-zinc-900">
                            Log in →
                        </span>
                    </a>
                @endauth

                <a
                    href="{{ route('guest.orders.track.show') }}"
                    class="rounded-2xl border border-zinc-200 bg-zinc-50 p-5 transition hover:bg-zinc-100"
                >
                    <h2 class="text-base font-semibold text-zinc-900">
                        I ordered as a guest
                    </h2>

                    <p class="mt-2 text-sm text-zinc-600">
                        Find your order using your order number and email address, then select “Withdraw from contract”.
                    </p>

                    <span class="mt-4 inline-flex text-sm font-semibold text-zinc-900">
                        Find guest order →
                    </span>
                </a>
            </div>

            <div class="mt-8 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                You do not need to provide a reason for withdrawal. The reason field in the form is optional.
            </div>
        </div>
    </div>
@endsection
