@extends('layouts.storefront')

@section('content')
    <div class="mx-auto max-w-7xl">
        <div
            id="product-configurator"
            data-product='@json($productPayload)'
        ></div>
    </div>
@endsection
