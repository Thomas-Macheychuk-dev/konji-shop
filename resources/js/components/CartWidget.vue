<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { fetchCartSummary, removeCartItem, updateCartItem } from '../services/cart';

const props = defineProps({
    summaryUrl: {
        type: String,
        required: true,
    },
});

const MIN_QUANTITY = 1;
const MAX_QUANTITY = 50;

const isOpen = ref(false);
const isLoading = ref(false);
const errorMessage = ref('');
const processingItemIds = ref([]);
const summary = ref({
    count: 0,
    subtotal: '0.00 PLN',
    items: [],
    cart_url: '/cart',
    checkout_url: '/checkout',
});

const itemCount = computed(() => summary.value?.count ?? 0);
const items = computed(() => summary.value?.items ?? []);
const subtotal = computed(() => summary.value?.subtotal ?? '0.00 PLN');
const isEmpty = computed(() => itemCount.value < 1);

function isItemProcessing(itemId) {
    return processingItemIds.value.includes(itemId);
}

function startProcessing(itemId) {
    if (!processingItemIds.value.includes(itemId)) {
        processingItemIds.value.push(itemId);
    }
}

function stopProcessing(itemId) {
    processingItemIds.value = processingItemIds.value.filter((id) => id !== itemId);
}

function clampQuantity(quantity) {
    const numeric = Number(quantity);

    if (!Number.isFinite(numeric)) {
        return MIN_QUANTITY;
    }

    return Math.min(MAX_QUANTITY, Math.max(MIN_QUANTITY, Math.floor(numeric)));
}

async function loadSummary() {
    isLoading.value = true;
    errorMessage.value = '';

    try {
        summary.value = await fetchCartSummary(props.summaryUrl);
    } catch (error) {
        errorMessage.value = error?.message ?? 'Could not load cart.';
    } finally {
        isLoading.value = false;
    }
}

async function changeItemQuantity(item, nextQuantity) {
    const quantity = clampQuantity(nextQuantity);

    if (!item?.update_url) {
        errorMessage.value = 'Missing cart update URL.';
        return;
    }

    if (quantity === item.quantity) {
        return;
    }

    startProcessing(item.id);
    errorMessage.value = '';

    try {
        await updateCartItem(item.update_url, quantity);
        await loadSummary();
        window.dispatchEvent(new CustomEvent('cart:updated'));
    } catch (error) {
        errorMessage.value = error?.message ?? 'Could not update cart item.';
    } finally {
        stopProcessing(item.id);
    }
}

async function increaseQuantity(item) {
    await changeItemQuantity(item, item.quantity + 1);
}

async function decreaseQuantity(item) {
    await changeItemQuantity(item, item.quantity - 1);
}

async function deleteItem(item) {
    if (!item?.remove_url) {
        errorMessage.value = 'Missing cart remove URL.';
        return;
    }

    startProcessing(item.id);
    errorMessage.value = '';

    try {
        await removeCartItem(item.remove_url);
        await loadSummary();
        window.dispatchEvent(new CustomEvent('cart:updated'));
    } catch (error) {
        errorMessage.value = error?.message ?? 'Could not remove cart item.';
    } finally {
        stopProcessing(item.id);
    }
}

function closeCart() {
    isOpen.value = false;
}

async function toggleCart() {
    if (isOpen.value) {
        closeCart();
        return;
    }

    isOpen.value = true;
    await loadSummary();
}

function handleKeydown(event) {
    if (event.key === 'Escape') {
        closeCart();
    }
}

function handleCartUpdated(event) {
    const shouldOpen = Boolean(event?.detail?.open);

    if (shouldOpen) {
        isOpen.value = true;
    }

    loadSummary();
}

onMounted(() => {
    loadSummary();

    window.addEventListener('keydown', handleKeydown);
    window.addEventListener('cart:updated', handleCartUpdated);
});

onBeforeUnmount(() => {
    window.removeEventListener('keydown', handleKeydown);
    window.removeEventListener('cart:updated', handleCartUpdated);
});
</script>

<template>
    <div class="relative">
        <button
            type="button"
            class="relative inline-flex items-center gap-2 text-sm font-medium text-zinc-700 transition hover:text-zinc-900"
            :aria-label="isEmpty ? 'Open empty cart' : `Open cart with ${itemCount} item(s)`"
            @click="toggleCart"
        >
            <span>Cart</span>

            <span class="relative inline-flex h-6 w-6 items-center justify-center">
                <!-- Empty cart -->
                <svg
                    v-if="isEmpty"
                    xmlns="http://www.w3.org/2000/svg"
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke="currentColor"
                    stroke-width="1.8"
                    class="h-6 w-6"
                    aria-hidden="true"
                >
                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        d="M2.25 3h1.386c.51 0 .955.343 1.087.836L5.29 6.75m0 0h13.535c.75 0 1.304.7 1.158 1.435l-1.2 6A1.125 1.125 0 0 1 17.68 15H8.12a1.125 1.125 0 0 1-1.103-.908L5.29 6.75Zm0 0L4.5 3.75M9 19.5a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Zm9 0a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Z"
                    />
                </svg>

                <!-- Filled cart -->
                <svg
                    v-else
                    xmlns="http://www.w3.org/2000/svg"
                    viewBox="0 0 24 24"
                    fill="currentColor"
                    class="h-6 w-6"
                    aria-hidden="true"
                >
                    <path
                        d="M1.5 3.75A.75.75 0 0 1 2.25 3h1.386a1.875 1.875 0 0 1 1.818 1.41L5.82 6h13.11a1.875 1.875 0 0 1 1.838 2.246l-1.2 6A1.875 1.875 0 0 1 17.73 15.75H8.27a1.875 1.875 0 0 1-1.838-1.504L4.182 4.5H2.25a.75.75 0 0 1-.75-.75Zm6.75 15.75a1.5 1.5 0 1 0 0 3 1.5 1.5 0 0 0 0-3Zm8.25 0a1.5 1.5 0 1 0 0 3 1.5 1.5 0 0 0 0-3Z"
                    />
                </svg>

                <!-- Optional badge -->
                <span
                    v-if="itemCount > 0"
                    class="absolute -right-2 -top-2 inline-flex min-w-5 items-center justify-center rounded-full bg-zinc-900 px-1.5 py-0.5 text-[10px] font-semibold leading-none text-white"
                >
                    {{ itemCount }}
                </span>
            </span>
        </button>

        <teleport to="body">
            <div
                v-if="isOpen"
                class="fixed inset-0 z-40 bg-black/40"
                @click="closeCart"
            />

            <aside
                class="fixed right-0 top-0 z-50 flex h-full w-full max-w-md flex-col border-l border-zinc-200 bg-white shadow-2xl transition-transform duration-300"
                :class="isOpen ? 'translate-x-0' : 'translate-x-full'"
            >
                <div class="flex items-center justify-between border-b border-zinc-200 px-6 py-4">
                    <div>
                        <h2 class="text-lg font-semibold text-zinc-900">Your cart</h2>
                        <p class="text-sm text-zinc-500">{{ itemCount }} item(s)</p>
                    </div>

                    <button
                        type="button"
                        class="text-sm text-zinc-500 hover:text-zinc-900"
                        @click="closeCart"
                    >
                        Close
                    </button>
                </div>

                <div class="flex-1 overflow-y-auto px-6 py-4">
                    <div
                        v-if="isLoading"
                        class="text-sm text-zinc-500"
                    >
                        Loading cart…
                    </div>

                    <div
                        v-else-if="errorMessage"
                        class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"
                    >
                        {{ errorMessage }}
                    </div>

                    <div
                        v-else-if="isEmpty"
                        class="rounded-xl border border-zinc-200 bg-zinc-50 px-4 py-6 text-sm text-zinc-600"
                    >
                        Your cart is empty.
                    </div>

                    <ul
                        v-else
                        class="space-y-4"
                    >
                        <li
                            v-for="item in items"
                            :key="item.id"
                            class="flex gap-4 rounded-2xl border border-zinc-200 p-3"
                        >
                            <a
                                v-if="item.image_url && item.product_url"
                                :href="item.product_url"
                                class="block h-20 w-20"
                            >
                                <img
                                    :src="item.image_url"
                                    :alt="item.product_name"
                                    class="h-20 w-20 rounded-xl object-cover"
                                />
                            </a>

                            <img
                                v-else-if="item.image_url"
                                :src="item.image_url"
                                :alt="item.product_name"
                                class="h-20 w-20 rounded-xl object-cover"
                            />

                            <div
                                v-else
                                class="flex h-20 w-20 items-center justify-center rounded-xl bg-zinc-100 text-xs text-zinc-400"
                            >
                                No image
                            </div>

                            <div class="min-w-0 flex-1">
                                <a
                                    v-if="item.product_url"
                                    :href="item.product_url"
                                    class="line-clamp-2 text-sm font-medium text-zinc-900 hover:underline"
                                >
                                    {{ item.product_name }}
                                </a>

                                <p
                                    v-else
                                    class="line-clamp-2 text-sm font-medium text-zinc-900"
                                >
                                    {{ item.product_name }}
                                </p>

                                <p
                                    v-if="item.variant_name"
                                    class="mt-1 text-xs text-zinc-500"
                                >
                                    {{ item.variant_name }}
                                </p>

                                <div class="mt-3 flex items-center justify-between gap-3">
                                    <div class="flex items-center rounded-xl border border-zinc-300 bg-white shadow-sm">
                                        <button
                                            type="button"
                                            class="inline-flex h-7 w-7 items-center justify-center text-base font-semibold text-zinc-700 transition hover:bg-zinc-50 disabled:cursor-not-allowed disabled:opacity-40"
                                            :disabled="isItemProcessing(item.id) || item.quantity <= MIN_QUANTITY"
                                            @click="decreaseQuantity(item)"
                                        >
                                            −
                                        </button>

                                        <span class="inline-flex h-9 min-w-10 items-center justify-center border-x border-zinc-300 px-2 text-sm font-medium text-zinc-900">
                                            {{ item.quantity }}
                                        </span>

                                        <button
                                            type="button"
                                            class="inline-flex h-7 w-7 items-center justify-center text-base font-semibold text-zinc-700 transition hover:bg-zinc-50 disabled:cursor-not-allowed disabled:opacity-40"
                                            :disabled="isItemProcessing(item.id) || item.quantity >= MAX_QUANTITY"
                                            @click="increaseQuantity(item)"
                                        >
                                            +
                                        </button>
                                    </div>

                                    <span class="text-sm font-semibold text-zinc-900">
                                        {{ item.line_total }}
                                    </span>
                                </div>

                                <div class="mt-3 flex items-center justify-between">
                                    <span
                                        v-if="isItemProcessing(item.id)"
                                        class="text-xs text-zinc-500"
                                    >
                                        Updating…
                                    </span>

                                    <span
                                        v-else
                                        class="text-xs text-zinc-500"
                                    >
                                        Qty: {{ item.quantity }}
                                    </span>

                                    <button
                                        type="button"
                                        class="cursor-pointer text-xs font-medium text-red-600 transition hover:text-red-700 disabled:cursor-not-allowed disabled:opacity-40"                                        :disabled="isItemProcessing(item.id)"
                                        @click="deleteItem(item)"
                                    >
                                        Remove
                                    </button>
                                </div>
                            </div>
                        </li>
                    </ul>
                </div>

                <div class="border-t border-zinc-200 px-6 py-4">
                    <div class="mb-4 flex items-center justify-between text-sm">
                        <span class="text-zinc-600">Subtotal</span>
                        <span class="font-semibold text-zinc-900">{{ subtotal }}</span>
                    </div>

                    <div class="grid gap-3">
                        <a
                            :href="summary.cart_url"
                            class="inline-flex items-center justify-center rounded-xl border border-zinc-300 px-4 py-3 text-sm font-medium text-zinc-800 transition hover:bg-zinc-50"
                        >
                            View cart
                        </a>

                        <a
                            :href="summary.checkout_url"
                            class="inline-flex items-center justify-center rounded-xl bg-zinc-900 px-4 py-3 text-sm font-medium text-white transition hover:bg-zinc-800"
                        >
                            Checkout
                        </a>
                    </div>
                </div>
            </aside>
        </teleport>
    </div>
</template>
