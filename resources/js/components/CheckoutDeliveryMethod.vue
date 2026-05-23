<template>
    <div class="mt-5 grid grid-cols-1 gap-5 sm:grid-cols-2">
        <input type="hidden" name="delivery_provider" value="polkurier">
        <input type="hidden" name="delivery_carrier" :value="carrier">
        <input type="hidden" name="delivery_service" :value="service">
        <input type="hidden" name="delivery_locker_code" :value="lockerCode">

        <div>
            <label class="mb-2 block text-sm font-medium text-zinc-700">
                Carrier
            </label>

            <select
                v-model="carrier"
                :disabled="service === 'pickup'"
                class="block w-full rounded-xl border border-zinc-300 bg-white px-4 py-3 text-sm text-zinc-900 shadow-sm outline-none transition focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100 disabled:cursor-not-allowed disabled:bg-zinc-100 disabled:text-zinc-500"
            >
                <option value="inpost">InPost</option>
                <option value="ups">UPS</option>
                <option value="dpd">DPD</option>
            </select>

            <p v-if="service === 'pickup'" class="mt-2 text-xs text-zinc-500">
                Carrier is not needed for shop pickup.
            </p>
        </div>

        <div>
            <label class="mb-2 block text-sm font-medium text-zinc-700">
                Service
            </label>

            <select
                v-model="service"
                class="block w-full rounded-xl border border-zinc-300 bg-white px-4 py-3 text-sm text-zinc-900 shadow-sm outline-none transition focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100"
            >
                <option value="parcel_locker">InPost parcel locker</option>
                <option value="courier">Courier delivery</option>
                <option value="pickup">Pickup from shop — Prusa 20, Poznań</option>
            </select>
        </div>

        <div
            v-if="service === 'parcel_locker'"
            class="sm:col-span-2"
        >
            <div class="flex items-center justify-between">
                <label class="block text-sm font-medium text-zinc-700">
                    Parcel locker
                </label>

                <span
                    v-if="lockerCode"
                    class="rounded-full bg-green-100 px-2.5 py-1 text-xs font-medium text-green-700"
                >
                    Selected
                </span>
            </div>

            <div class="relative mt-2">
                <input
                    v-model="query"
                    type="text"
                    autocomplete="off"
                    class="w-full rounded-xl border border-zinc-300 bg-white px-4 py-3 pr-24 text-sm text-zinc-900 shadow-sm outline-none transition focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100"
                    placeholder="Search by city, street, postcode or locker code..."
                    @input="search"
                >

                <button
                    v-if="query || lockerCode"
                    type="button"
                    class="absolute right-3 top-1/2 -translate-y-1/2 rounded-lg px-2 py-1 text-xs font-medium text-zinc-500 transition hover:bg-zinc-100 hover:text-zinc-900"
                    @click="clearLocker"
                >
                    Clear
                </button>
            </div>

            <div v-if="loading" class="mt-2 text-sm text-zinc-500">
                Searching parcel lockers...
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
                    No parcel lockers found.
                </div>
            </div>

            <p class="mt-2 text-xs text-zinc-500">
                Select one result before placing the order.
            </p>
        </div>

        <div
            v-if="service === 'pickup'"
            class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4 text-sm text-zinc-700 sm:col-span-2"
        >
            <p class="font-medium text-zinc-900">
                Pickup from shop
            </p>

            <p class="mt-1">
                Your order will be available for collection at:
            </p>

            <p class="mt-2 font-medium text-zinc-900">
                Prusa 20, Poznań
            </p>

            <p class="mt-2 text-zinc-500">
                No courier shipment will be created.
            </p>
        </div>
    </div>
</template>

<script setup>
import { ref, watch } from 'vue';

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
});

const carrier = ref(props.initialCarrier || 'inpost');
const service = ref(props.initialService || 'parcel_locker');
const lockerCode = ref(props.initialLockerCode || '');
const query = ref(props.initialLockerCode || '');
const lockers = ref([]);
const loading = ref(false);
const showResults = ref(false);

let timeout = null;

watch(service, (value) => {
    if (value === 'pickup') {
        carrier.value = 'local_pickup';
        clearLocker();
        return;
    }

    if (value === 'parcel_locker') {
        carrier.value = 'inpost';
        return;
    }

    if (carrier.value === 'local_pickup') {
        carrier.value = 'inpost';
    }

    clearLocker();
});

function search() {
    clearTimeout(timeout);

    lockerCode.value = '';

    const value = query.value.trim();

    if (value.length < 2) {
        lockers.value = [];
        showResults.value = false;
        return;
    }

    timeout = setTimeout(fetchLockers, 300);
}

async function fetchLockers() {
    loading.value = true;
    showResults.value = true;

    try {
        const response = await fetch(
            `/checkout/inpost-parcel-lockers?query=${encodeURIComponent(query.value.trim())}`
        );

        lockers.value = await response.json();
    } catch (error) {
        lockers.value = [];
    } finally {
        loading.value = false;
    }
}

function selectLocker(locker) {
    lockerCode.value = locker.code;
    query.value = locker.label;
    showResults.value = false;
}

function clearLocker() {
    lockerCode.value = '';
    query.value = '';
    lockers.value = [];
    showResults.value = false;
}

function lockerAddress(locker) {
    return String(locker.label || '').replace(`${locker.code} — `, '');
}
</script>
