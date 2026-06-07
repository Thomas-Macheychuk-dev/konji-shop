<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\VatRate;
use App\Models\Cart;
use App\Services\Cart\CartGuestTokenResolver;
use App\Services\Cart\CartPricingService;
use App\Services\Cart\CartService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CheckoutShowController extends Controller
{
    public function __invoke(
        Request $request,
        CartService $cartService,
        CartGuestTokenResolver $guestTokenResolver,
        CartPricingService $cartPricingService
    ): View|RedirectResponse {
        $guestToken = $request->user() ? null : $request->cookie(CartGuestTokenResolver::COOKIE_NAME);

        $cart = $cartService->findActiveCart(
            $request->user(),
            $guestToken
        );

        if (! $cart || $cart->items()->count() === 0) {
            return redirect()
                ->route('cart.show')
                ->with('error', 'Twój koszyk jest pusty.');
        }

        $cart->load([
            'items.product',
            'items.variant.attributeValues.attribute',
            'items.variant.attributeValues.productImage',
        ]);

        $totals = $cartPricingService->calculate($cart);

        $taxBreakdown = $this->taxBreakdownForCart(
            cart: $cart,
            shippingGross: $totals->shipping,
            discount: $totals->discount,
        );

        $user = $request->user();

        return view('pages.checkout.show', [
            'cart' => $cart,
            'subtotal' => $totals->subtotal,
            'shipping' => $totals->shipping,
            'discount' => $totals->discount,
            'total' => $totals->total,

            'itemsNet' => $taxBreakdown['items_net_amount'],
            'itemsTax' => $taxBreakdown['items_tax_amount'],
            'itemsGross' => $taxBreakdown['items_gross_amount'],

            'shippingNet' => $taxBreakdown['shipping_net_amount'],
            'shippingTax' => $taxBreakdown['shipping_tax_amount'],
            'shippingGross' => $taxBreakdown['shipping_gross_amount'],

            'taxAmount' => $taxBreakdown['tax_amount'],
            'totalGross' => $taxBreakdown['total_gross_amount'],
            'hasTaxBreakdown' => $taxBreakdown['has_tax_breakdown'],

            'shippingAddressDefaults' => $user?->checkoutShippingAddressDefaults() ?? [],
            'companyBillingAddressDefaults' => $user?->checkoutCompanyBillingAddressDefaults() ?? [],
            'hasCompanyBillingAddress' => $user?->hasCompanyAddress() ?? false,
            'countries' => config('countries', []),
        ]);
    }

    /**
     * @return array{
     *     items_net_amount: int,
     *     items_tax_amount: int,
     *     items_gross_amount: int,
     *     shipping_net_amount: int,
     *     shipping_tax_amount: int,
     *     shipping_gross_amount: int,
     *     discount_amount: int,
     *     tax_amount: int,
     *     total_gross_amount: int,
     *     has_tax_breakdown: bool
     * }
     */
    private function taxBreakdownForCart(Cart $cart, int $shippingGross, int $discount): array
    {
        $itemsNet = 0;
        $itemsTax = 0;
        $itemsGross = 0;

        foreach ($cart->items as $item) {
            $lineGross = (int) ($item->currentLineTotalAmount() ?? 0);

            if ($lineGross <= 0) {
                continue;
            }

            $vatRate = $item->variant?->vat_rate;

            if (! $vatRate instanceof VatRate) {
                $itemsNet += $lineGross;
                $itemsGross += $lineGross;

                continue;
            }

            $lineNet = $vatRate->netFromGross($lineGross);
            $lineTax = max(0, $lineGross - $lineNet);

            $itemsNet += $lineNet;
            $itemsTax += $lineTax;
            $itemsGross += $lineGross;
        }

        $shipping = $this->grossBreakdown($shippingGross, $this->shippingVatRate());

        return [
            'items_net_amount' => $itemsNet,
            'items_tax_amount' => $itemsTax,
            'items_gross_amount' => $itemsGross,

            'shipping_net_amount' => $shipping['net'],
            'shipping_tax_amount' => $shipping['tax'],
            'shipping_gross_amount' => $shippingGross,

            'discount_amount' => $discount,
            'tax_amount' => $itemsTax + $shipping['tax'],
            'total_gross_amount' => max(0, $itemsGross + $shippingGross - $discount),

            'has_tax_breakdown' => $itemsTax > 0 || $shipping['tax'] > 0,
        ];
    }

    /**
     * @return array{net: int, tax: int}
     */
    private function grossBreakdown(int $grossAmount, VatRate $vatRate): array
    {
        if ($grossAmount <= 0) {
            return [
                'net' => 0,
                'tax' => 0,
            ];
        }

        $net = $vatRate->netFromGross($grossAmount);

        return [
            'net' => $net,
            'tax' => max(0, $grossAmount - $net),
        ];
    }

    private function shippingVatRate(): VatRate
    {
        return VatRate::VAT_23;
    }
}
