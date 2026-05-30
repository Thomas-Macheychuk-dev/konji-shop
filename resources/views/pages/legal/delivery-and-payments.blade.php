@extends('layouts.storefront')

@section('content')
    <div class="mx-auto max-w-4xl px-4 py-10 sm:px-6 lg:px-8">
        <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm sm:p-8">
            <p class="text-sm font-medium text-zinc-500">Checkout information</p>

            <h1 class="mt-2 text-3xl font-bold tracking-tight text-zinc-900">
                Delivery and Payments
            </h1>

            <p class="mt-6 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                Draft text. Review before production use.
            </p>

            <div class="prose prose-zinc mt-8 max-w-none">
                <h2>Delivery methods</h2>
                <p>
                    Available delivery methods may include courier delivery, InPost parcel locker delivery, and local pickup where enabled.
                </p>

                <h2>Delivery costs</h2>
                <p>
                    Delivery costs are calculated and displayed during checkout before the order is placed.
                </p>

                <h2>Payments</h2>
                <p>
                    Available payment methods are shown during checkout. Orders are processed after payment confirmation unless otherwise stated.
                </p>

                <h2>Order tracking</h2>
                <p>
                    Where shipment tracking is available, tracking details are displayed on the customer order page and may be sent by email.
                </p>
            </div>
        </div>
    </div>
@endsection
