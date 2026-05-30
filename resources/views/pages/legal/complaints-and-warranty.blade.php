@extends('layouts.storefront')

@section('content')
    <div class="mx-auto max-w-4xl px-4 py-10 sm:px-6 lg:px-8">
        <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm sm:p-8">
            <p class="text-sm font-medium text-zinc-500">Customer support</p>

            <h1 class="mt-2 text-3xl font-bold tracking-tight text-zinc-900">
                Complaints and Warranty
            </h1>

            <p class="mt-6 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                Draft text. Review before production use.
            </p>

            <div class="prose prose-zinc mt-8 max-w-none">
                <h2>Submitting a complaint</h2>
                <p>
                    Complaints can be submitted by email to
                    <a href="mailto:{{ config('legal.seller.email') }}">{{ config('legal.seller.email') }}</a>.
                </p>

                <h2>Information to include</h2>
                <p>
                    Please include your order number, product name, description of the issue, photographs if relevant, and your preferred resolution.
                </p>

                <h2>Complaint handling</h2>
                <p>
                    We will review the complaint and contact you using the email address assigned to the order.
                </p>
            </div>
        </div>
    </div>
@endsection
