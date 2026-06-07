<template>
    <div class="mt-5 grid grid-cols-1 gap-5 sm:grid-cols-2">
        <input type="hidden" name="delivery_provider" value="polkurier">
        <input type="hidden" name="delivery_carrier" :value="carrier">
        <input type="hidden" name="delivery_service" :value="service">
        <input type="hidden" name="delivery_locker_code" :value="lockerCode">

        <div class="sm:col-span-2">
            <label class="mb-2 block text-sm font-medium text-zinc-700">
                Metoda dostawy
            </label>

            <select
                v-model="deliveryMethod"
                class="block w-full rounded-xl border border-zinc-300 bg-white px-4 py-3 text-sm text-zinc-900 shadow-sm outline-none transition focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100"
            >
                <option value="inpost_parcel_locker">Paczkomat InPost</option>
                <option value="inpost_courier">Kurier InPost</option>
                <option value="ups_courier">Kurier UPS</option>
                <option value="dpd_courier">Kurier DPD</option>
                <option value="local_pickup">Odbiór osobisty — Prusa 20, Poznań</option>
            </select>

            <p class="mt-2 text-xs text-zinc-500">
                Wybierz sposób odbioru zamówienia.
            </p>

            <div class="mt-4 rounded-2xl border border-zinc-200 bg-zinc-50 p-4 text-sm sm:col-span-2">
                <div class="flex items-center justify-between gap-4">
                    <span class="font-medium text-zinc-700">
                        Cena dostawy
                    </span>

                    <span class="font-semibold text-zinc-900">
                        <template v-if="quoteLoading">
                            Obliczanie...
                        </template>

                        <template v-else-if="quote">
                            {{ quote.formatted }}
                        </template>

                        <template v-else>
                            —
                        </template>
                    </span>
                </div>

                <p v-if="quoteError" class="mt-2 text-xs text-red-600">
                    {{ quoteError }}
                </p>

                <p v-else-if="quote?.source === 'fallback'" class="mt-2 text-xs text-zinc-500">
                    Szacowana cena dostawy.
                </p>

                <p v-else-if="quote?.source === 'polkurier_order_valuation_v2'" class="mt-2 text-xs text-zinc-500">
                    Aktualna cena dostawy z Polkurier.
                </p>
            </div>
        </div>

        <div
            v-if="service === 'parcel_locker'"
            class="sm:col-span-2"
        >
            <div class="flex items-center justify-between">
                <label class="block text-sm font-medium text-zinc-700">
                    Paczkomat
                </label>

                <span
                    v-if="lockerCode"
                    class="rounded-full bg-green-100 px-2.5 py-1 text-xs font-medium text-green-700"
                >
                    Wybrano
                </span>
            </div>

            <div class="relative mt-2">
                <input
                    v-model="query"
                    type="text"
                    autocomplete="off"
                    class="w-full rounded-xl border border-zinc-300 bg-white px-4 py-3 pr-24 text-sm text-zinc-900 shadow-sm outline-none transition focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100"
                    placeholder="Szukaj po mieście, ulicy, kodzie pocztowym lub kodzie paczkomatu..."
                    @input="search"
                >

                <button
                    v-if="query || lockerCode"
                    type="button"
                    class="absolute right-3 top-1/2 -translate-y-1/2 rounded-lg px-2 py-1 text-xs font-medium text-zinc-500 transition hover:bg-zinc-100 hover:text-zinc-900"
                    @click="clearLocker"
                >
                    Wyczyść
                </button>
            </div>

            <div v-if="loading" class="mt-2 text-sm text-zinc-500">
                Wyszukiwanie paczkomatów...
            </div>

            <div
                v-if="showResults"
                class="mt-2 max-h-80 overflow-y-auto rounded-2xl border border-zinc-200 bg-white shadow-xl"
            >
                <button
                    v-for="locker in lockers"
                    :key="locker.code"
                    type="button"
                    class="block w-full border-b border-zinc-100 px-4 py-3 text-left transition hover:bg-zinc-50 last:border-b-0"
                    @click="selectLocker(locker)"
                >
                    <div class="font-medium text-zinc-900">
                        {{ locker.code }}
                    </div>

                    <div class="mt-1 text-xs text-zinc-500">
                        {{ lockerAddress(locker) }}
                    </div>
                </button>

                <div
                    v-if="!loading && lockers.length === 0"
                    class="px-4 py-6 text-center text-sm text-zinc-500"
                >
                    Nie znaleziono paczkomatów.
                </div>
            </div>

            <p v-if="lockerCode" class="mt-2 text-xs text-green-700">
                Wybrany paczkomat zostanie użyty do dostawy.
            </p>

            <p v-else class="mt-2 text-xs text-zinc-500">
                Wybierz jeden wynik przed złożeniem zamówienia.
            </p>
        </div>

        <div
            v-if="service === 'local_pickup'"
            class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4 text-sm text-zinc-700 sm:col-span-2"
        >
            <p class="font-medium text-zinc-900">
                Odbiór osobisty
            </p>

            <p class="mt-1">
                Zamówienie będzie gotowe do odbioru pod adresem:
            </p>

            <p class="mt-2 font-medium text-zinc-900">
                Prusa 20, Poznań
            </p>

            <p class="mt-2 text-zinc-500">
                Przesyłka kurierska nie zostanie utworzona.
            </p>
        </div>
    </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

const props = defineProps({
    initialCarrier: {
        type: String,
        default: 'inpost',
    },
    initialService: {
        type: String,
        default: 'parcel_locker',
    },
    initialLockerCode: {
        type: String,
        default: '',
    },
    shippingQuoteUrl: {
        type: String,
        default: '',
    },
    currency: {
        type: String,
        default: 'PLN',
    },
});

const deliveryOptions = {
    inpost_parcel_locker: {
        carrier: 'inpost',
        service: 'parcel_locker',
    },
    inpost_courier: {
        carrier: 'inpost',
        service: 'courier',
    },
    ups_courier: {
        carrier: 'ups',
        service: 'courier',
    },
    dpd_courier: {
        carrier: 'dpd',
        service: 'courier',
    },
    local_pickup: {
        carrier: 'local_pickup',
        service: 'local_pickup',
    },
};

const deliveryMethod = ref(resolveInitialDeliveryMethod());
const lockerCode = ref(props.initialLockerCode || '');
const selectedLocker = ref(null);
const query = ref(props.initialLockerCode || '');
const lockers = ref([]);
const loading = ref(false);
const showResults = ref(false);

const quote = ref(null);
const quoteLoading = ref(false);
const quoteError = ref('');

let timeout = null;
let quoteTimeout = null;
let quoteRequestId = 0;

const selectedOption = computed(() => {
    return deliveryOptions[deliveryMethod.value] ?? deliveryOptions.inpost_parcel_locker;
});

const carrier = computed(() => selectedOption.value.carrier);
const service = computed(() => selectedOption.value.service);

watch(
    deliveryMethod,
    () => {
        if (service.value !== 'parcel_locker') {
            clearLocker(false);
        }

        scheduleQuote();
    },
    { immediate: true }
);

onMounted(() => {
    document.addEventListener('input', handleCheckoutInput);
    document.addEventListener('change', handleCheckoutInput);

    scheduleQuote();
});

onBeforeUnmount(() => {
    document.removeEventListener('input', handleCheckoutInput);
    document.removeEventListener('change', handleCheckoutInput);

    clearTimeout(timeout);
    clearTimeout(quoteTimeout);
});

function resolveInitialDeliveryMethod() {
    const initialCarrier = props.initialCarrier || 'inpost';
    const initialService = props.initialService || 'parcel_locker';

    if (initialCarrier === 'local_pickup' || initialService === 'local_pickup') {
        return 'local_pickup';
    }

    if (initialService === 'parcel_locker') {
        return 'inpost_parcel_locker';
    }

    if (initialService === 'courier') {
        if (initialCarrier === 'ups') {
            return 'ups_courier';
        }

        if (initialCarrier === 'dpd') {
            return 'dpd_courier';
        }

        return 'inpost_courier';
    }

    return 'inpost_parcel_locker';
}

function search() {
    clearTimeout(timeout);

    lockerCode.value = '';
    selectedLocker.value = null;

    const value = query.value.trim();

    if (value.length < 2) {
        lockers.value = [];
        showResults.value = false;
        scheduleQuote();

        return;
    }

    timeout = setTimeout(fetchLockers, 300);
}

async function fetchLockers() {
    loading.value = true;
    showResults.value = true;

    try {
        const response = await fetch(
            `/checkout/inpost-parcel-lockers?query=${encodeURIComponent(query.value.trim())}`,
            {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
            },
        );

        lockers.value = await response.json();
    } catch (error) {
        lockers.value = [];
    } finally {
        loading.value = false;
    }
}

function selectLocker(locker) {
    selectedLocker.value = locker;
    lockerCode.value = locker.code;
    query.value = locker.label;
    showResults.value = false;

    scheduleQuote();
}

function clearLocker(refreshQuote = true) {
    if (typeof refreshQuote !== 'boolean') {
        refreshQuote = true;
    }

    lockerCode.value = '';
    selectedLocker.value = null;
    query.value = '';
    lockers.value = [];
    showResults.value = false;

    if (refreshQuote) {
        scheduleQuote();
    }
}

function handleCheckoutInput(event) {
    const fieldName = event.target?.name;

    if (![
        'shipping_postcode',
        'shipping_country_code',
    ].includes(fieldName)) {
        return;
    }

    scheduleQuote();
}

function scheduleQuote() {
    clearTimeout(quoteTimeout);
    quoteRequestId++;

    if (!props.shippingQuoteUrl) {
        return;
    }

    if (service.value === 'local_pickup') {
        quote.value = {
            amount: 0,
            formatted: 'Bezpłatnie',
            currency: props.currency,
            source: 'local_pickup',
        };
        quoteError.value = '';
        quoteLoading.value = false;

        return;
    }

    const postcode = quotePostcode();

    if (!postcode || postcode.trim().length < 2) {
        quote.value = null;
        quoteError.value = service.value === 'parcel_locker'
            ? 'Wybierz paczkomat, aby obliczyć cenę dostawy.'
            : 'Wpisz kod pocztowy, aby obliczyć cenę dostawy.';
        quoteLoading.value = false;

        return;
    }

    quoteTimeout = setTimeout(fetchQuote, 350);
}

async function fetchQuote() {
    const requestId = ++quoteRequestId;

    quoteLoading.value = true;
    quoteError.value = '';

    try {
        const response = await fetch(props.shippingQuoteUrl, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            body: JSON.stringify({
                delivery_provider: 'polkurier',
                delivery_carrier: carrier.value,
                delivery_service: service.value,
                delivery_locker_code: lockerCode.value,
                shipping_postcode: quotePostcode(),
                shipping_country_code: quoteCountryCode(),
                currency: props.currency,
            }),
        });

        const data = await response.json();

        if (requestId !== quoteRequestId) {
            return;
        }

        if (!response.ok) {
            quote.value = null;
            quoteError.value = firstValidationMessage(data) || 'Nie udało się obliczyć ceny dostawy.';

            return;
        }

        quote.value = data;
        quoteError.value = '';
    } catch (error) {
        if (requestId !== quoteRequestId) {
            return;
        }

        quote.value = null;
        quoteError.value = 'Nie udało się obliczyć ceny dostawy.';
    } finally {
        if (requestId === quoteRequestId) {
            quoteLoading.value = false;
        }
    }
}

function quotePostcode() {
    if (service.value === 'parcel_locker') {
        return selectedLocker.value?.postcode
            || extractPostcode(selectedLocker.value?.label)
            || extractPostcode(query.value)
            || fieldValue('shipping_postcode');
    }

    return fieldValue('shipping_postcode');
}

function quoteCountryCode() {
    if (service.value === 'parcel_locker') {
        return selectedLocker.value?.country_code
            || selectedLocker.value?.country
            || fieldValue('shipping_country_code')
            || 'PL';
    }

    return fieldValue('shipping_country_code') || 'PL';
}

function fieldValue(name) {
    return document.querySelector(`[name="${name}"]`)?.value ?? '';
}

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content
        ?? document.querySelector('input[name="_token"]')?.value
        ?? '';
}

function firstValidationMessage(data) {
    const errors = data?.errors ?? {};

    for (const messages of Object.values(errors)) {
        if (Array.isArray(messages) && messages.length > 0) {
            return messages[0];
        }
    }

    return data?.message ?? '';
}

function lockerAddress(locker) {
    return String(locker.label || '').replace(`${locker.code} — `, '');
}

function extractPostcode(value) {
    const match = String(value || '').match(/\b\d{2}-\d{3}\b/);

    return match?.[0] ?? '';
}
</script>
