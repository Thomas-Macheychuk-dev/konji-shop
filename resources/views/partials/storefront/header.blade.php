<header class="border-b border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
    <div class="mx-auto flex max-w-[1600px] items-center justify-between px-4 py-4 sm:px-6 lg:px-8 xl:px-10">
        <a href="{{ route('home') }}" class="text-lg font-semibold tracking-tight text-zinc-900 dark:text-white">
            Konji Shop
        </a>

        <nav class="flex items-center gap-6 text-sm">
            <a
                href="{{ route('home') }}"
                class="text-zinc-700 transition hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-white"
            >
                Home
            </a>

            <a
                href="#categories"
                class="text-zinc-700 transition hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-white"
            >
                Categories
            </a>

            <a
                href="#deals"
                class="text-zinc-700 transition hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-white"
            >
                Deals
            </a>

            @auth
                <a
                    href="{{ route('dashboard') }}"
                    class="inline-flex items-center rounded-lg bg-zinc-900 px-4 py-2 font-medium text-white transition hover:bg-zinc-800 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-200"
                >
                    Dashboard
                </a>
            @else
                <a
                    href="{{ route('login') }}"
                    class="text-zinc-700 transition hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-white"
                >
                    Login
                </a>

                <a
                    href="{{ route('register') }}"
                    class="inline-flex items-center rounded-lg bg-zinc-900 px-4 py-2 font-medium text-white transition hover:bg-zinc-800 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-200"
                >
                    Register
                </a>
            @endauth
            <div
                id="cart-widget"
                data-summary-url="{{ route('cart.summary') }}"
            ></div>
        </nav>
    </div>
</header>
