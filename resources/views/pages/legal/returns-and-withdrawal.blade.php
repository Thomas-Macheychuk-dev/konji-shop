@extends('layouts.storefront')

@section('content')
    <div class="mx-auto max-w-4xl px-4 py-10 sm:px-6 lg:px-8">
        <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm sm:p-8">
            <p class="text-sm font-medium text-zinc-500">Consumer rights</p>

            <h1 class="mt-2 text-3xl font-bold tracking-tight text-zinc-900">
                Returns and Withdrawal
            </h1>

            <p class="mt-2 text-sm text-zinc-500">
                Version: {{ config('legal.versions.returns') }}
            </p>

            <p class="mt-6 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                Draft text. Review before production use.
            </p>

            <div class="prose prose-zinc mt-8 max-w-none">
                <h2>Withdrawal period</h2>
                <p>
                    Consumers may withdraw from a distance contract within {{ config('legal.returns.withdrawal_days') }} days, subject to statutory exceptions.
                </p>

                <h2>How to withdraw</h2>
                <p>
                    To withdraw from the contract, contact us by email:
                    <a href="mailto:{{ config('legal.returns.contact_email') }}">{{ config('legal.returns.contact_email') }}</a>.
                    Include your order number and contact details.
                </p>

                <h2>Return address</h2>
                <p>
                    {{ config('legal.returns.return_address') }}
                </p>

                <h2>Returned goods</h2>
                <p>
                    Returned goods should be sent back without undue delay and should be protected from damage during transport.
                </p>

                <h2>Refunds</h2>
                <p>
                    Refunds are processed using the same payment method where possible, unless agreed otherwise.
                </p>
            </div>
        </div>
    </div>
@endsection
