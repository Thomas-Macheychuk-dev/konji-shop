export async function fetchCartSummary(summaryUrl) {
    const response = await fetch(summaryUrl, {
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
    });

    if (!response.ok) {
        throw new Error('Failed to load cart summary.');
    }

    return await response.json();
}

export async function updateCartItem(updateUrl, quantity) {
    const token = document
        .querySelector('meta[name="csrf-token"]')
        ?.getAttribute('content');

    const response = await fetch(updateUrl, {
        method: 'PATCH',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': token,
            'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
        body: JSON.stringify({ quantity }),
    });

    if (!response.ok) {
        throw new Error('Failed to update cart item.');
    }

    return await response.json();
}

export async function removeCartItem(removeUrl) {
    const token = document
        .querySelector('meta[name="csrf-token"]')
        ?.getAttribute('content');

    const response = await fetch(removeUrl, {
        method: 'DELETE',
        headers: {
            Accept: 'application/json',
            'X-CSRF-TOKEN': token,
            'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
    });

    if (!response.ok) {
        throw new Error('Failed to remove cart item.');
    }

    return await response.json();
}
