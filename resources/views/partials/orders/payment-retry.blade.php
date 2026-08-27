@if ($order->canRetryPaymentInitialization())
    <div class="rounded-2xl border border-amber-200 bg-amber-50 p-5">
        <h2 class="text-base font-semibold text-amber-950">Płatność wymaga ponowienia</h2>
        <p class="mt-2 text-sm text-amber-900">
            Zamówienie jest zapisane, ale płatność nie została jeszcze rozpoczęta. Możesz bezpiecznie spróbować ponownie — nie utworzy to drugiego zamówienia.
        </p>

        <form method="POST" action="{{ route('payments.retry', $order) }}" class="mt-4">
            @csrf
            <button
                type="submit"
                class="inline-flex items-center justify-center rounded-xl bg-zinc-900 px-5 py-3 text-sm font-semibold text-white transition hover:bg-zinc-800"
            >
                Ponów płatność
            </button>
        </form>
    </div>
@endif
