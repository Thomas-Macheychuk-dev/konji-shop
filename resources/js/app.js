import { createApp, h } from 'vue';
import Alpine from 'alpinejs';
import ProductConfigurator from './components/ProductConfigurator.vue';
import CartWidget from './components/CartWidget.vue';
import './order-cancel-confirmation';
import CheckoutDeliveryMethod from './components/CheckoutDeliveryMethod.vue';
import AdminPolkurierPickupSelector from './components/AdminPolkurierPickupSelector.vue';

window.Alpine = Alpine;
Alpine.start();

const productConfiguratorEl = document.getElementById('product-configurator');

if (productConfiguratorEl) {
    const product = JSON.parse(productConfiguratorEl.dataset.product || '{}');

    createApp({
        render: () => h(ProductConfigurator, { product }),
    }).mount(productConfiguratorEl);
}

const cartWidgetEl = document.getElementById('cart-widget');

if (cartWidgetEl) {
    createApp(CartWidget, {
        summaryUrl: cartWidgetEl.dataset.summaryUrl,
    }).mount(cartWidgetEl);
}

const checkoutDeliveryMethod = document.getElementById('checkout-delivery-method');

if (checkoutDeliveryMethod) {
    createApp(CheckoutDeliveryMethod, {
        initialCarrier: checkoutDeliveryMethod.dataset.initialCarrier,
        initialService: checkoutDeliveryMethod.dataset.initialService,
        initialLockerCode: checkoutDeliveryMethod.dataset.initialLockerCode,
        shippingQuoteUrl: checkoutDeliveryMethod.dataset.shippingQuoteUrl,
        currency: checkoutDeliveryMethod.dataset.currency || 'PLN',
    }).mount(checkoutDeliveryMethod);
}

const adminPolkurierPickupSelector = document.getElementById('admin-polkurier-pickup-selector');

if (adminPolkurierPickupSelector) {
    createApp(AdminPolkurierPickupSelector, {
        pickupTimesUrl: adminPolkurierPickupSelector.dataset.pickupTimesUrl,
        initialNoCourierOrder: adminPolkurierPickupSelector.dataset.initialNoCourierOrder !== '0',
    }).mount(adminPolkurierPickupSelector);
}
