@extends('layouts.storefront')

@section('content')
    <div class="mx-auto max-w-4xl px-4 py-10 sm:px-6 lg:px-8">
        <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm sm:p-8">
            <p class="text-sm font-medium text-zinc-500">Obsługa klienta</p>
            <h1 class="mt-2 text-3xl font-bold tracking-tight text-zinc-900">{{ $page->title }}</h1>
            <div class="mt-3 max-w-2xl text-base leading-7 text-zinc-600">
                {!! $page->intro !!}
            </div>

            {!! $page->content !!}

            @include('partials.storefront.contact-form')
        </div>
    </div>
@endsection
