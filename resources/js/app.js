import { createApp, h } from 'vue';
import Alpine from 'alpinejs';
import ProductConfigurator from './components/ProductConfigurator.vue';
import CartWidget from './components/CartWidget.vue';

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
