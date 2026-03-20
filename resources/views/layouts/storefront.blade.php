<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white text-zinc-900 dark:bg-zinc-950 dark:text-white">
        @include('partials.storefront.header')

        <div class="px-4 py-8 sm:px-6 lg:px-8 xl:px-10">
            <div class="grid grid-cols-1 gap-8 lg:grid-cols-[220px_minmax(0,1fr)] lg:items-start">
                <aside>
                    @include('partials.storefront.category-sidebar')
                </aside>

                <main>
                    @yield('content')
                </main>
            </div>
        </div>

        @include('partials.storefront.footer')

        @fluxScripts
    </body>
</html>
