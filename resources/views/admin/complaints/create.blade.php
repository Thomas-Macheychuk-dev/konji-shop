@extends('layouts.storefront')

@section('content')
    <div class="mx-auto max-w-4xl px-4 py-10 sm:px-6 lg:px-8">
        <a href="{{ route('admin.complaints.index') }}" class="text-sm font-medium text-zinc-500 hover:text-zinc-700">
            ← Wróć do reklamacji
        </a>

        <h1 class="mt-3 text-3xl font-bold tracking-tight text-zinc-900">Zarejestruj reklamację</h1>

        <p class="mt-2 text-sm text-zinc-600">
            Wpisz reklamację w dniu jej otrzymania (e-mail, list, telefon lub osobiście). Termin odpowiedzi zostanie wyliczony automatycznie.
        </p>

        <form method="POST" action="{{ route('admin.complaints.store') }}" class="mt-8 space-y-6">
            @csrf

            @include('admin.complaints._form', ['complaint' => null])

            <button type="submit" class="inline-flex items-center justify-center rounded-xl bg-zinc-900 px-5 py-3 text-sm font-semibold text-white transition hover:bg-zinc-800">
                Zarejestruj reklamację
            </button>
        </form>
    </div>
@endsection
