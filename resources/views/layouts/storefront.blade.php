<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
        @stack('styles')
    </head>
    <body class="min-h-screen bg-[#f7f9fc] text-slate-900 antialiased">
        @include('partials.storefront.header')

        <main id="app" class="min-h-[60vh]">
            @yield('content')
        </main>

        @include('partials.storefront.footer')

        @stack('scripts')
        @fluxScripts
    </body>
</html>
