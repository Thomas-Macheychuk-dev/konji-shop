<footer class="border-t border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
    <div class="mx-auto max-w-[1600px] px-4 py-12 sm:px-6 lg:px-8 xl:px-10">
        <div class="grid grid-cols-1 gap-10 md:grid-cols-2 xl:grid-cols-4">
            <div>
                <h3 class="text-lg font-semibold text-zinc-900 dark:text-white">
                    Konji Shop Sp. z o.o.
                </h3>

                <div class="mt-4 space-y-1 text-sm text-zinc-600 dark:text-zinc-300">
                    <p>ul. Example Street 12</p>
                    <p>00-000 Poznań</p>
                </div>

                <div class="mt-6 space-y-1 text-sm text-zinc-600 dark:text-zinc-300">
                    <p>NIP: 000-000-00-00</p>
                    <p>REGON: 000000000</p>
                    <p>KRS: 0000000000</p>
                </div>

                <div class="mt-6">
                    <h4 class="text-sm font-semibold uppercase tracking-wide text-zinc-900 dark:text-white">
                        Returns address
                    </h4>

                    <div class="mt-2 space-y-1 text-sm text-zinc-600 dark:text-zinc-300">
                        <p>Konji Shop Sp. z o.o.</p>
                        <p>ul. Returns 5</p>
                        <p>00-000 Poznań</p>
                    </div>
                </div>
            </div>

            <div>
                <h3 class="text-lg font-semibold text-zinc-900 dark:text-white">
                    Shopping
                </h3>

                <nav class="mt-4 flex flex-col gap-3 text-sm">
                    <a href="#" class="text-zinc-600 transition hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-white">
                        Delivery & payments
                    </a>
                    <a href="#" class="text-zinc-600 transition hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-white">
                        Guest order tracking
                    </a>
                    <a href="#" class="text-zinc-600 transition hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-white">
                        Cart reminders
                    </a>
                    <a href="#" class="text-zinc-600 transition hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-white">
                        Returns & refunds
                    </a>
                    <a href="#" class="text-zinc-600 transition hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-white">
                        Complaints
                    </a>
                    <a href="#" class="text-zinc-600 transition hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-white">
                        Contact
                    </a>
                    <a href="#" class="text-zinc-600 transition hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-white">
                        About us
                    </a>
                </nav>
            </div>

            <div>
                <h3 class="text-lg font-semibold text-zinc-900 dark:text-white">
                    Legal
                </h3>

                <nav class="mt-4 flex flex-col gap-3 text-sm">
                    <a href="#" class="text-zinc-600 transition hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-white">
                        Terms & conditions
                    </a>
                    <a href="{{ route('legal.cookie-policy') }}" class="text-zinc-600 transition hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-white">
                        {{ __('Cookie policy') }}
                    </a>
                    <a href="#" class="text-zinc-600 transition hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-white">
                        Privacy policy
                    </a>
                    <a href="#" class="text-zinc-600 transition hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-white">
                        Electronic services terms
                    </a>
                    <a href="#" class="text-zinc-600 transition hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-white">
                        Accessibility
                    </a>
                    <a href="#" class="text-zinc-600 transition hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-white">
                        MDR
                    </a>
                    <a href="#" class="text-zinc-600 transition hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-white">
                        Sitemap
                    </a>
                </nav>
            </div>

            <div>
                <h3 class="text-lg font-semibold text-zinc-900 dark:text-white">
                    Contact
                </h3>

                <div class="mt-4 space-y-3 text-sm text-zinc-600 dark:text-zinc-300">
                    <p>
                        <a href="tel:+48123456789" class="transition hover:text-zinc-900 dark:hover:text-white">
                            +48 123 456 789
                        </a>
                    </p>

                    <p>
                        <a href="mailto:kontakt@konjishop.pl" class="transition hover:text-zinc-900 dark:hover:text-white">
                            kontakt@konjishop.pl
                        </a>
                    </p>
                </div>

                <div class="mt-8">
                    <h4 class="text-sm font-semibold uppercase tracking-wide text-zinc-900 dark:text-white">
                        Follow us
                    </h4>

                    <div class="mt-3 flex items-center gap-4 text-sm">
                        <a href="#" class="text-zinc-600 transition hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-white">
                            Facebook
                        </a>
                        <a href="#" class="text-zinc-600 transition hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-white">
                            Instagram
                        </a>
                        <a href="#" class="text-zinc-600 transition hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-white">
                            YouTube
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <div class="mt-10 border-t border-zinc-200 pt-6 text-sm text-zinc-500 dark:border-zinc-800 dark:text-zinc-400">
            © {{ now()->year }} Konji Shop. All rights reserved.
        </div>
    </div>
</footer>
