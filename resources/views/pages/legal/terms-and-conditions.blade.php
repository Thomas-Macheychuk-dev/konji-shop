@extends('layouts.storefront')

@section('content')
    <div class="mx-auto max-w-4xl px-4 py-10 sm:px-6 lg:px-8">
        <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm sm:p-8">
            <p class="text-sm font-medium text-zinc-500">Shop rules</p>

            <h1 class="mt-2 text-3xl font-bold tracking-tight text-zinc-900">
                Terms and Conditions
            </h1>

            <p class="mt-2 text-sm text-zinc-500">
                Version: {{ config('legal.versions.terms') }}
            </p>

            <p class="mt-6 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                Draft text. Review before production use.
            </p>

            <div class="prose prose-zinc mt-8 max-w-none">
                <h2>Seller</h2>
                <p>
                    The online shop is operated by {{ config('legal.seller.company_name') }},
                    {{ config('legal.seller.street') }},
                    {{ config('legal.seller.postcode') }} {{ config('legal.seller.city') }},
                    {{ config('legal.seller.country') }}.
                </p>

                <p>
                    Contact:
                    <a href="mailto:{{ config('legal.seller.email') }}">{{ config('legal.seller.email') }}</a>,
                    {{ config('legal.seller.phone') }}.
                </p>

                <h2>Orders</h2>
                <p>
                    Orders may be placed through the checkout form. Before placing an order, the customer can review product prices, VAT, delivery costs, delivery method, and the total amount payable.
                </p>

                <h2>Prices and payments</h2>
                <p>
                    Product prices are shown as gross prices in PLN unless stated otherwise. The checkout shows the VAT breakdown, delivery cost, and total gross amount before the order is placed.
                </p>

                <h2>Delivery</h2>
                <p>
                    Available delivery methods and costs are displayed during checkout. Delivery may be provided through courier services, parcel lockers, or local pickup where available.
                </p>

                <h2>Right of withdrawal</h2>
                <p>
                    Consumers may have the right to withdraw from a distance contract within 14 days, subject to statutory exceptions. Details are provided on the Returns and Withdrawal page.
                </p>

                <h2>Complaints</h2>
                <p>
                    Complaints can be submitted by email to
                    <a href="mailto:{{ config('legal.seller.email') }}">{{ config('legal.seller.email') }}</a>.
                    Details are provided on the Complaints and Warranty page.
                </p>
            </div>
        </div>
    </div>
@endsection
