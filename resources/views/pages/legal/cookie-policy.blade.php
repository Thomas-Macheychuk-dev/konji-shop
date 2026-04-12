@extends('layouts.storefront')

@section('content')
<div class="mx-auto max-w-4xl px-4 py-10 sm:px-6 lg:px-8">
    <div class="mb-8">
        <h1 class="text-3xl font-bold tracking-tight text-zinc-900 dark:text-zinc-100">
            {{ __('Polityka cookies') }}
        </h1>

        <p class="mt-3 text-sm leading-6 text-zinc-600 dark:text-zinc-400">
            {{ __('Niniejsza Polityka cookies wyjaśnia, czym są pliki cookies, w jakim celu są wykorzystywane w naszym sklepie internetowym oraz jakie prawa przysługują użytkownikowi w związku z ich stosowaniem.') }}
        </p>
    </div>

    <div class="space-y-8 text-sm leading-7 text-zinc-700 dark:text-zinc-300">
        <section>
            <h2 class="text-xl font-semibold text-zinc-900 dark:text-zinc-100">
                {{ __('1. Czym są pliki cookies?') }}
            </h2>

            <p class="mt-3">
                {{ __('Pliki cookies (tzw. ciasteczka) to niewielkie pliki tekstowe zapisywane na urządzeniu końcowym użytkownika podczas korzystania ze strony internetowej. Są one wykorzystywane w celu zapewnienia prawidłowego działania strony oraz ułatwienia korzystania z jej funkcjonalności.') }}
            </p>
        </section>

        <section>
            <h2 class="text-xl font-semibold text-zinc-900 dark:text-zinc-100">
                {{ __('2. Jakie pliki cookies wykorzystujemy?') }}
            </h2>

            <p class="mt-3">
                {{ __('Obecnie nasza strona wykorzystuje wyłącznie pliki cookies niezbędne do jej prawidłowego działania. Nie wykorzystujemy plików cookies analitycznych, marketingowych ani preferencyjnych wymagających zgody użytkownika.') }}
            </p>

            <div class="mt-4 overflow-hidden rounded-2xl border border-zinc-200 dark:border-zinc-800">
                <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-800">
                    <thead class="bg-zinc-50 dark:bg-zinc-900/50">
                    <tr>
                        <th class="px-4 py-3 text-left text-sm font-semibold text-zinc-900 dark:text-zinc-100">
                            {{ __('Rodzaj cookies') }}
                        </th>
                        <th class="px-4 py-3 text-left text-sm font-semibold text-zinc-900 dark:text-zinc-100">
                            {{ __('Cel') }}
                        </th>
                    </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 bg-white dark:divide-zinc-800 dark:bg-zinc-950">
                    <tr>
                        <td class="px-4 py-3 align-top">
                            {{ __('Niezbędne cookies sesyjne') }}
                        </td>
                        <td class="px-4 py-3 align-top">
                            {{ __('Umożliwiają prawidłowe działanie sklepu, w tym utrzymanie sesji użytkownika, obsługę formularzy, bezpieczeństwo strony, logowanie oraz funkcjonowanie koszyka i procesu składania zamówienia.') }}
                        </td>
                    </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section>
            <h2 class="text-xl font-semibold text-zinc-900 dark:text-zinc-100">
                {{ __('3. Podstawa stosowania cookies') }}
            </h2>

            <p class="mt-3">
                {{ __('Pliki cookies niezbędne do działania strony mogą być stosowane bez zgody użytkownika, ponieważ są konieczne do świadczenia usługi drogą elektroniczną, z której użytkownik chce skorzystać, oraz do zapewnienia bezpieczeństwa i prawidłowego funkcjonowania strony.') }}
            </p>
        </section>

        <section>
            <h2 class="text-xl font-semibold text-zinc-900 dark:text-zinc-100">
                {{ __('4. Zarządzanie plikami cookies') }}
            </h2>

            <p class="mt-3">
                {{ __('Użytkownik może zarządzać ustawieniami plików cookies z poziomu swojej przeglądarki internetowej, w tym ograniczyć lub całkowicie zablokować ich zapisywanie. Należy jednak pamiętać, że wyłączenie niezbędnych plików cookies może spowodować nieprawidłowe działanie sklepu lub uniemożliwić korzystanie z niektórych jego funkcji.') }}
            </p>
        </section>

        <section>
            <h2 class="text-xl font-semibold text-zinc-900 dark:text-zinc-100">
                {{ __('5. Dane osobowe i polityka prywatności') }}
            </h2>

            <p class="mt-3">
                {{ __('Szczegółowe informacje dotyczące przetwarzania danych osobowych, w tym danych związanych z korzystaniem z naszego sklepu, znajdują się w Polityce prywatności.') }}
            </p>

            <p class="mt-3">
                <a
                    href="#"
                    class="font-medium text-zinc-900 underline underline-offset-4 hover:text-zinc-700 dark:text-zinc-100 dark:hover:text-zinc-300"
                >
                    {{ __('Przejdź do Polityki prywatności') }}
                </a>
            </p>
        </section>

        <section>
            <h2 class="text-xl font-semibold text-zinc-900 dark:text-zinc-100">
                {{ __('6. Zmiany Polityki cookies') }}
            </h2>

            <p class="mt-3">
                {{ __('Niniejsza Polityka cookies może być okresowo aktualizowana, w szczególności w przypadku zmian technologicznych, prawnych lub organizacyjnych. Aktualna wersja dokumentu jest zawsze publikowana na tej stronie.') }}
            </p>
        </section>

        <section>
            <h2 class="text-xl font-semibold text-zinc-900 dark:text-zinc-100">
                {{ __('7. Kontakt') }}
            </h2>

            <p class="mt-3">
                {{ __('W sprawach dotyczących plików cookies oraz ochrony prywatności można skontaktować się z nami za pośrednictwem danych kontaktowych wskazanych w Polityce prywatności lub w zakładce kontaktowej dostępnej na stronie sklepu.') }}
            </p>
        </section>
    </div>
</div>
@endsection
