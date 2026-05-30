@extends('layouts.storefront')

@section('content')
    <div class="mx-auto max-w-4xl px-4 py-10 sm:px-6 lg:px-8">
        <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm sm:p-8">
            <p class="text-sm font-medium text-zinc-500">Personal data</p>

            <h1 class="mt-2 text-3xl font-bold tracking-tight text-zinc-900">
                Privacy Policy
            </h1>

            <p class="mt-2 text-sm text-zinc-500">
                Version: {{ config('legal.versions.privacy') }}
            </p>

            <p class="mt-6 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                Draft text. Review before production use.
            </p>

            <div class="prose prose-zinc mt-8 max-w-none">
                <h2>Data controller</h2>
                <p>
                    The data controller is {{ config('legal.seller.company_name') }},
                    {{ config('legal.seller.street') }},
                    {{ config('legal.seller.postcode') }} {{ config('legal.seller.city') }}.
                </p>

                <h2>Contact</h2>
                <p>
                    Privacy-related questions can be sent to
                    <a href="mailto:{{ config('legal.seller.email') }}">{{ config('legal.seller.email') }}</a>.
                </p>

                <h2>Data processed</h2>
                <p>
                    The shop may process customer account data, contact details, delivery and billing addresses, order history, payment information, and technical data necessary to operate the website.
                </p>

                <h2>Purpose of processing</h2>
                <p>
                    Data is processed to handle orders, payments, delivery, customer communication, complaints, returns, accounting obligations, security, and website operation.
                </p>

                <h2>Data recipients</h2>
                <p>
                    Data may be shared with service providers such as payment operators, delivery providers, hosting providers, email providers, and accounting/legal advisers where necessary.
                </p>

                <h2>Customer rights</h2>
                <p>
                    Customers may have rights to access, correct, delete, restrict, object to processing, and request data portability, subject to applicable law.
                </p>
            </div>
        </div>
    </div>
@endsection
