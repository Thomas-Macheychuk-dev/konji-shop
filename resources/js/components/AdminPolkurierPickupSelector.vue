<template>
    <div class="mb-4 grid gap-3 rounded-2xl border border-zinc-200 bg-zinc-50 p-4 sm:grid-cols-3">
        <input
            type="hidden"
            name="polkurier_no_courier_order"
            :value="noCourierOrder ? '1' : '0'"
        >

        <input
            type="hidden"
            name="polkurier_pickup_date"
            :value="selectedPickup?.date || ''"
        >

        <input
            type="hidden"
            name="polkurier_pickup_time_from"
            :value="selectedPickup?.time_from || ''"
        >

        <input
            type="hidden"
            name="polkurier_pickup_time_to"
            :value="selectedPickup?.time_to || ''"
        >

        <div class="sm:col-span-3">
            <label class="flex items-center gap-2 text-sm text-zinc-700">
                <input
                    v-model="noCourierOrder"
                    type="checkbox"
                    class="rounded border-zinc-300 text-zinc-900 focus:ring-zinc-900"
                >

                Samodzielnie zamówię odbiór kurierski
            </label>

            <p class="mt-1 text-xs text-zinc-500">
                Odznacz tę opcję, jeśli Polkurier ma zamówić odbiór kurierski.
            </p>
        </div>

        <div v-if="!noCourierOrder" class="sm:col-span-3">
            <div class="flex flex-wrap items-center gap-3">
                <button
                    type="button"
                    class="rounded-xl border border-zinc-300 bg-white px-4 py-2 text-sm font-semibold text-zinc-900 hover:bg-zinc-100 disabled:cursor-not-allowed disabled:opacity-60"
                    :disabled="loading"
                    @click="loadPickupTimes"
                >
                    {{ loading ? 'Ładowanie terminów odbioru...' : 'Wczytaj dostępne terminy odbioru' }}
                </button>

                <p v-if="errorMessage" class="text-sm text-red-700">
                    {{ errorMessage }}
                </p>
            </div>
        </div>

        <div v-if="!noCourierOrder" class="sm:col-span-3">
            <label class="mb-1 block text-xs font-medium text-zinc-600">
                Dostępne okno odbioru
            </label>

            <select
                v-model="selectedKey"
                class="block w-full rounded-xl border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 shadow-sm outline-none focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100"
            >
                <option value="">
                    Wybierz okno odbioru
                </option>

                <option
                    v-for="option in pickupTimes"
                    :key="optionKey(option)"
                    :value="optionKey(option)"
                >
                    {{ option.label }}
                </option>
            </select>

            <p v-if="pickupTimes.length === 0 && !loading" class="mt-2 text-xs text-zinc-500">
                Wczytaj dostępne terminy odbioru przed utworzeniem odbioru kurierskiego.
            </p>

            <p v-if="selectedPickup" class="mt-2 text-xs text-zinc-500">
                Wybrany odbiór:
                <span class="font-medium text-zinc-700">
                    {{ selectedPickup.label }}
                </span>
            </p>
        </div>
    </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue';

const props = defineProps({
    pickupTimesUrl: {
        type: String,
        required: true,
    },
    initialNoCourierOrder: {
        type: Boolean,
        default: true,
    },
});

const noCourierOrder = ref(props.initialNoCourierOrder);
const pickupTimes = ref([]);
const selectedKey = ref('');
const loading = ref(false);
const errorMessage = ref('');

const selectedPickup = computed(() => {
    return pickupTimes.value.find((option) => optionKey(option) === selectedKey.value) ?? null;
});

watch(noCourierOrder, async (value) => {
    if (!value && pickupTimes.value.length === 0 && !loading.value) {
        await loadPickupTimes();
    }

    if (value) {
        selectedKey.value = '';
    }
});

function optionKey(option) {
    return [
        option.date || '',
        option.time_from || '',
        option.time_to || '',
    ].join('|');
}

async function loadPickupTimes() {
    loading.value = true;
    errorMessage.value = '';

    try {
        const response = await fetch(props.pickupTimesUrl, {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
        });

        if (!response.ok) {
            throw new Error('Nie udało się wczytać terminów odbioru.');
        }

        const payload = await response.json();

        pickupTimes.value = Array.isArray(payload.data) ? payload.data : [];

        if (pickupTimes.value.length > 0 && !selectedKey.value) {
            selectedKey.value = optionKey(pickupTimes.value[0]);
        }
    } catch (error) {
        errorMessage.value = error?.message ?? 'Nie udało się wczytać terminów odbioru.';
    } finally {
        loading.value = false;
    }
}
</script>
