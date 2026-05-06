@extends('layouts.storefront')

@section('content')
    <div class="max-w-2xl mx-auto mt-12 px-4 text-center">

        @if($isSuccess)
            <div class="text-green-600">
                <h1 class="text-4xl font-bold">Zamówienie przyjęte!</h1>
                <p class="mt-6 text-xl">{{ $message }}</p>
            </div>
        @else
            <div class="text-red-600">
                <h1 class="text-4xl font-bold">Coś poszło nie tak</h1>
                <p class="mt-6 text-xl">{{ $message }}</p>
            </div>
        @endif

        @if($order)
            <div class="mt-10 bg-gray-100 rounded-2xl p-8">
                <p class="text-lg">Numer zamówienia: <strong>{{ $order->number }}</strong></p>
                @if($payment)
                    <p class="mt-2">Status płatności:
                        <span class="font-semibold">{{ $payment->status }}</span>
                    </p>
                @endif
            </div>
        @endif

        <div class="mt-12 flex flex-col sm:flex-row gap-4 justify-center">
            <a href="{{ route('home') }}"
               class="px-8 py-4 bg-black text-white rounded-2xl font-medium hover:bg-gray-800">
                Wróć do sklepu
            </a>
            @auth
                @if($order)
                    <a href="{{ route('account.orders.show', $order) }}"
                       class="px-8 py-4 border border-gray-300 rounded-2xl font-medium hover:bg-gray-50">
                        View order details
                    </a>
                @endif
            @endauth
        </div>
    </div>
@endsection
