<script setup>
import { computed, ref, watch } from 'vue';

const props = defineProps({
    product: {
        type: Object,
        required: true,
    },
});

const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';

const optionGroups = computed(() => {
    const groups = (props.product.option_groups ?? []).map((group) => ({
        ...group,
        values: [...(group.values ?? [])].sort((a, b) => {
            const aSort = Number(a.sort_order ?? 999999);
            const bSort = Number(b.sort_order ?? 999999);

            return aSort - bSort;
        }),
    }));

    return groups;
});

const variants = computed(() => props.product.variants ?? []);
const baseImages = computed(() => props.product.base_images ?? []);

const currentImage = ref(0);
const selectedOptionValueIds = ref({});

const defaultVariant = computed(() => {
    return (
        variants.value.find(
            (variant) => variant.id === props.product.default_variant_id
        ) ??
        variants.value.find((variant) => variant.is_default) ??
        variants.value[0] ??
        null
    );
});

const lastSelectedValueId = ref(null);
const quantity = ref(1);
const selectionError = ref('');

watch(quantity, (value) => {
    const numeric = Number(value);

    if (!Number.isFinite(numeric) || numeric < 1) {
        quantity.value = 1;
        return;
    }

    if (numeric > 50) {
        quantity.value = 50;
        return;
    }

    quantity.value = Math.floor(numeric);
});

const missingOptionGroups = computed(() => {
    return optionGroups.value.filter((group) => {
        return !selectedOptionValueIds.value[group.code];
    });
});

function initializeSelection() {
    const nextSelection = {};

    for (const group of optionGroups.value) {
        if ((group.values ?? []).length === 1) {
            nextSelection[group.code] = group.values[0].id;
        }
    }

    selectedOptionValueIds.value = nextSelection;
}

function variantMatchesSelection(variant, selection) {
    return Object.values(selection).every((selectedValueId) => {
        return selectedValueId == null || variant.option_value_ids.includes(selectedValueId);
    });
}

const selectedIds = computed(() => {
    return Object.values(selectedOptionValueIds.value).filter(Boolean);
});

const selectedVariant = computed(() => {
    if (!variants.value.length) {
        return null;
    }

    if (!selectedIds.value.length) {
        return defaultVariant.value;
    }

    const matchingVariants = variants.value.filter((variant) =>
        variantMatchesSelection(variant, selectedOptionValueIds.value)
    );

    if (!matchingVariants.length) {
        return defaultVariant.value;
    }

    const exactMatch = matchingVariants.find((variant) => {
        return variant.option_value_ids.length === selectedIds.value.length
            && selectedIds.value.every((id) => variant.option_value_ids.includes(id));
    });

    if (exactMatch) {
        return exactMatch;
    }

    if (lastSelectedValueId.value) {
        const matchingLastSelected = matchingVariants.find((variant) =>
            variant.option_value_ids.includes(lastSelectedValueId.value)
        );

        if (matchingLastSelected) {
            return matchingLastSelected;
        }
    }

    const defaultMatchingVariant = matchingVariants.find(
        (variant) => variant.id === defaultVariant.value?.id
    );

    if (defaultMatchingVariant) {
        return defaultMatchingVariant;
    }

    return matchingVariants[0];
});

const exactSelectedVariant = computed(() => {
    if (!variants.value.length) {
        return null;
    }

    if (missingOptionGroups.value.length > 0) {
        return null;
    }

    return variants.value.find((variant) => {
        return variant.option_value_ids.length === selectedIds.value.length
            && selectedIds.value.every((id) => variant.option_value_ids.includes(id));
    }) ?? null;
});

function canSelectValue(groupCode, valueId) {
    const tentativeSelection = {
        ...selectedOptionValueIds.value,
        [groupCode]: valueId,
    };

    return variants.value.some((variant) =>
        variantMatchesSelection(variant, tentativeSelection)
    );
}

function findFirstAvailableValue(groupCode, selection) {
    const group = optionGroups.value.find((item) => item.code === groupCode);

    if (!group) {
        return null;
    }

    const matchingValue = group.values.find((value) => {
        const tentativeSelection = {
            ...selection,
            [groupCode]: value.id,
        };

        return variants.value.some((variant) =>
            variantMatchesSelection(variant, tentativeSelection)
        );
    });

    return matchingValue?.id ?? null;
}

function normalizeSelection(selection, changedGroupCode = null) {
    let normalized = { ...selection };

    for (const group of optionGroups.value) {
        const currentValueId = normalized[group.code];

        if (!currentValueId) {
            continue;
        }

        const isStillValid = variants.value.some((variant) =>
            variantMatchesSelection(variant, normalized)
        );

        if (isStillValid) {
            continue;
        }

        if (group.code === changedGroupCode) {
            continue;
        }

        normalized[group.code] = findFirstAvailableValue(group.code, normalized);
    }

    return normalized;
}

function selectOption(groupCode, valueId) {
    const isCurrentlySelected = selectedOptionValueIds.value[groupCode] === valueId;

    const nextValueId = isCurrentlySelected ? null : valueId;

    const nextSelection = {
        ...selectedOptionValueIds.value,
        [groupCode]: nextValueId,
    };

    selectedOptionValueIds.value = normalizeSelection(nextSelection, groupCode);
    selectionError.value = '';
    lastSelectedValueId.value = nextValueId;
}

function handleAddToCartSubmit(event) {
    if (missingOptionGroups.value.length > 0) {
        selectionError.value = `Please select: ${missingOptionGroups.value.map((group) => group.label).join(', ')}`;
        event.preventDefault();
        return;
    }

    if (!exactSelectedVariant.value?.id) {
        selectionError.value = 'Please select a valid product variant.';
        event.preventDefault();
        return;
    }

    if (exactSelectedVariant.value.stock_status === 'out_of_stock') {
        selectionError.value = 'This variant is out of stock.';
        event.preventDefault();
    }
}

const galleryImages = computed(() => {
    if (selectedVariant.value?.images?.length) {
        return selectedVariant.value.images;
    }

    if (baseImages.value?.length) {
        return baseImages.value;
    }

    return [];
});

const mainImage = computed(() => {
    return galleryImages.value[currentImage.value] ?? galleryImages.value[0] ?? null;
});

function selectImage(index) {
    currentImage.value = index;
}

function formatPrice(price, currency = 'PLN') {
    if (price === null || price === undefined) {
        return 'Price unavailable';
    }

    return new Intl.NumberFormat('pl-PL', {
        style: 'currency',
        currency,
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(price / 100);
}

function formatStockStatus(status) {
    if (!status) {
        return '';
    }

    const normalized = status.replaceAll('_', ' ');

    return normalized.charAt(0).toUpperCase() + normalized.slice(1);
}

function isSelected(groupCode, valueId) {
    return selectedOptionValueIds.value[groupCode] === valueId;
}

function isImageSelected(index) {
    return currentImage.value === index;
}

const canAddToCart = computed(() => {
    return exactSelectedVariant.value?.stock_status !== 'out_of_stock';
});

initializeSelection();

watch(
    () => props.product,
    () => {
        initializeSelection();
        currentImage.value = 0;
        selectionError.value = '';
    },
    { deep: true }
);

watch(selectedVariant, () => {
    currentImage.value = 0;
});

window.dispatchEvent(new CustomEvent('cart:updated', {
    detail: { open: true },
}));
</script>

<template>
    <div class="grid grid-cols-1 gap-10 lg:grid-cols-2">
        <section>
            <div class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm">
                <template v-if="mainImage">
                    <img
                        :src="mainImage.url"
                        :alt="mainImage.alt || product.name"
                        class="h-auto w-full object-cover"
                    />
                </template>

                <template v-else>
                    <div class="flex aspect-[4/5] items-center justify-center bg-zinc-100 text-sm text-zinc-500">
                        No image available
                    </div>
                </template>
            </div>

            <div
                v-if="galleryImages.length > 1"
                class="mt-4 grid grid-cols-4 gap-3 sm:grid-cols-5"
            >
                <button
                    v-for="(image, index) in galleryImages"
                    :key="image.id ?? index"
                    type="button"
                    class="overflow-hidden rounded-xl border bg-white transition"
                    :class="{
                        'border-zinc-900 ring-2 ring-zinc-900/10': isImageSelected(index),
                        'border-zinc-200': !isImageSelected(index),
                    }"
                    @click="selectImage(index)"
                >
                    <img
                        :src="image.url"
                        :alt="image.alt || product.name"
                        class="aspect-square h-full w-full object-cover"
                    />
                </button>
            </div>
        </section>

        <section>
            <div class="space-y-6">
                <div>
                    <h1 class="mt-2 text-3xl font-bold tracking-tight text-zinc-900">
                        {{ product.name }}
                    </h1>
                </div>

                <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm">
                    <form
                        method="POST"
                        action="/cart/items"
                        class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between"
                        @submit="handleAddToCartSubmit"
                    >
                        <input type="hidden" name="_token" :value="csrfToken">
                        <input
                            type="hidden"
                            name="product_variant_id"
                            :value="exactSelectedVariant?.id ?? ''"
                        >

                        <div class="min-w-0">
                            <div class="text-3xl font-semibold text-zinc-900">
                                {{ formatPrice(selectedVariant?.price, selectedVariant?.currency || 'PLN') }}
                            </div>

                            <div
                                v-if="selectedVariant?.stock_status"
                                class="mt-3 inline-flex rounded-full bg-zinc-100 px-3 py-1 text-sm font-medium text-zinc-700"
                            >
                                {{ formatStockStatus(selectedVariant.stock_status) }}
                            </div>
                        </div>

                        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-end">
                            <div class="flex items-center rounded-xl border border-zinc-300 bg-white shadow-sm">
                                <button
                                    type="button"
                                    class="inline-flex h-11 w-11 items-center justify-center text-lg font-semibold text-zinc-700 transition hover:bg-zinc-50 disabled:cursor-not-allowed disabled:opacity-40"
                                    :disabled="quantity <= 1"
                                    @click="quantity = Math.max(1, quantity - 1)"
                                >
                                    −
                                </button>

                                <input
                                    id="quantity"
                                    v-model.number="quantity"
                                    type="number"
                                    name="quantity"
                                    min="1"
                                    max="50"
                                    class="h-11 w-16 border-x border-zinc-300 bg-transparent text-center text-sm font-medium text-zinc-900 focus:outline-none [appearance:textfield] [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none"
                                >

                                <button
                                    type="button"
                                    class="inline-flex h-11 w-11 items-center justify-center text-lg font-semibold text-zinc-700 transition hover:bg-zinc-50 disabled:cursor-not-allowed disabled:opacity-40"
                                    :disabled="quantity >= 50"
                                    @click="quantity = Math.min(50, quantity + 1)"
                                >
                                    +
                                </button>
                            </div>

                            <button
                                type="submit"
                                class="inline-flex items-center justify-center rounded-xl bg-zinc-900 px-5 py-3 text-sm font-semibold text-white transition hover:bg-zinc-800"
                            >
                                Add to cart
                            </button>
                        </div>
                    </form>

                    <p
                        v-if="selectionError"
                        class="mt-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"
                    >
                        {{ selectionError }}
                    </p>
                </div>

                <div
                    v-for="group in optionGroups"
                    :key="group.code"
                    class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm"
                >
                    <h2 class="text-lg font-semibold text-zinc-900">
                        {{ group.label }}
                    </h2>

                    <div class="mt-4 flex flex-wrap gap-3">
                        <button
                            v-for="value in group.values"
                            :key="value.id"
                            type="button"
                            class="rounded-xl border px-4 py-2 text-sm transition"
                            :class="{
                                'border-zinc-900 bg-zinc-900 text-white': isSelected(group.code, value.id),
                                'border-zinc-200 bg-white text-zinc-900': !isSelected(group.code, value.id),
                                'cursor-not-allowed opacity-40': !canSelectValue(group.code, value.id),
                            }"
                            :disabled="!canSelectValue(group.code, value.id)"
                            @click="selectOption(group.code, value.id)"
                        >
                        <span class="inline-flex items-center gap-2">
                            <template v-if="value.swatch?.type === 'color' && value.swatch?.value">
                                <span
                                    class="inline-block h-5 w-5 rounded-full border border-zinc-300"
                                    :style="{ backgroundColor: value.swatch.value }"
                                />
                            </template>

                            <template v-else-if="value.swatch?.type === 'image' && value.swatch?.image_url">
                                <span class="inline-block h-5 w-5 overflow-hidden rounded-full border border-zinc-300">
                                    <img
                                        :src="value.swatch.image_url"
                                        :alt="value.label"
                                        class="h-full w-full object-cover"
                                    />
                                </span>
                            </template>

                            <span>{{ value.label }}</span>
                        </span>
                        </button>
                    </div>
                </div>
                <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm sm:p-8">
                    <div
                        class="product-description overflow-x-auto"
                        v-html="product.description"
                    />
                </div>
            </div>
        </section>
    </div>
</template>
<style scoped>
.product-description :deep(*) {
    box-sizing: border-box;
}

.product-description :deep(p) {
    text-align: justify;
}

.product-description :deep(h1),
.product-description :deep(h2),
.product-description :deep(h3),
.product-description :deep(h4) {
    margin-top: 1.5rem;
    margin-bottom: 0.75rem;
    font-weight: 700;
    line-height: 1.25;
    color: rgb(24 24 27);
}

.product-description :deep(h1) {
    font-size: 1.5rem;
}

.product-description :deep(h2) {
    font-size: 1.25rem;
}

.product-description :deep(h3) {
    font-size: 1.125rem;
}

.product-description :deep(p) {
    margin: 0.75rem 0;
    line-height: 1.75;
    color: rgb(39 39 42);
}

.product-description :deep(strong) {
    font-weight: 700;
    color: rgb(9 9 11);
}

.product-description :deep(br) {
    line-height: 1.75;
}

.product-description :deep(ul),
.product-description :deep(ol) {
    margin: 1rem 0;
    padding-left: 1.25rem;
    color: rgb(39 39 42);
}

.product-description :deep(li) {
    margin: 0.375rem 0;
    line-height: 1.7;
}

.product-description :deep(table) {
    width: 100%;
    margin-top: 1rem;
    margin-bottom: 1rem;
    border-collapse: collapse;
    border: 1px solid rgb(212 212 216);
    font-size: 0.95rem;
}

.product-description :deep(thead) {
    background-color: rgb(244 244 245);
}

.product-description :deep(th) {
    padding: 0.875rem 0.75rem;
    border: 1px solid rgb(212 212 216);
    text-align: left;
    vertical-align: top;
    font-weight: 600;
    color: rgb(24 24 27);
}

.product-description :deep(td) {
    padding: 0.875rem 0.75rem;
    border: 1px solid rgb(212 212 216);
    vertical-align: top;
    color: rgb(39 39 42);
}

.product-description :deep(a) {
    color: rgb(24 24 27);
    text-decoration: underline;
    text-underline-offset: 2px;
}

.product-description :deep(img) {
    display: block;
    max-width: 100%;
    height: auto;
    margin: 1rem auto;
    border-radius: 0.75rem;
}

.product-description :deep(hr) {
    margin: 1.5rem 0;
    border: 0;
    border-top: 1px solid rgb(228 228 231);
}
</style>
