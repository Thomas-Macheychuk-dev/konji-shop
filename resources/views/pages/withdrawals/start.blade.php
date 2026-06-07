@extends('layouts.storefront')

@section('content')
    <div class="mx-auto max-w-4xl px-4 py-10 sm:px-6 lg:px-8">
        <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm sm:p-8">
            <p class="text-sm font-medium text-zinc-500">
                Odstąpienie od umowy
            </p>

            <h1 class="mt-2 text-3xl font-bold tracking-tight text-zinc-900">
                Odstąp od umowy tutaj
            </h1>

            <p class="mt-5 text-sm leading-6 text-zinc-700">
                Możesz złożyć elektroniczne oświadczenie o odstąpieniu od umowy dla kwalifikującego się zamówienia internetowego.
                Aby kontynuować, otwórz zamówienie i wybierz pozycje, które chcesz objąć zgłoszeniem odstąpienia.
            </p>

            <div class="mt-8 grid gap-4 sm:grid-cols-2">
                @auth
                    <a
                        href="{{ route('account.orders.index') }}"
                        class="rounded-2xl border border-zinc-200 bg-zinc-50 p-5 transition hover:bg-zinc-100"
                    >
                        <h2 class="text-base font-semibold text-zinc-900">
                            Mam konto
                        </h2>

                        <p class="mt-2 text-sm text-zinc-600">
                            Przejdź do swoich zamówień i wybierz „Odstąp od umowy” przy odpowiednim zamówieniu.
                        </p>

                        <span class="mt-4 inline-flex text-sm font-semibold text-zinc-900">
                            Zobacz moje zamówienia →
                        </span>
                    </a>
                @else
                    <a
                        href="{{ route('login') }}"
                        class="rounded-2xl border border-zinc-200 bg-zinc-50 p-5 transition hover:bg-zinc-100"
                    >
                        <h2 class="text-base font-semibold text-zinc-900">
                            Mam konto
                        </h2>

                        <p class="mt-2 text-sm text-zinc-600">
                            Zaloguj się, otwórz zamówienie i wybierz „Odstąp od umowy”.
                        </p>

                        <span class="mt-4 inline-flex text-sm font-semibold text-zinc-900">
                            Zaloguj się →
                        </span>
                    </a>
                @endauth

                <a
                    href="{{ route('guest.orders.track.show') }}"
                    class="rounded-2xl border border-zinc-200 bg-zinc-50 p-5 transition hover:bg-zinc-100"
                >
                    <h2 class="text-base font-semibold text-zinc-900">
                        Zamawiałem jako gość
                    </h2>

                    <p class="mt-2 text-sm text-zinc-600">
                        Znajdź zamówienie przy użyciu numeru zamówienia i adresu e-mail, a następnie wybierz „Odstąp od umowy”.
                    </p>

                    <span class="mt-4 inline-flex text-sm font-semibold text-zinc-900">
                        Znajdź zamówienie gościa →
                    </span>
                </a>
            </div>

            <div class="mt-8 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                Nie musisz podawać powodu odstąpienia od umowy. Pole powodu w formularzu jest opcjonalne.
            </div>
        </div>
    </div>
@endsection
