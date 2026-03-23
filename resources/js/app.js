import { createApp, h } from 'vue';
import ProductConfigurator from './components/ProductConfigurator.vue';
import Alpine from 'alpinejs';

window.Alpine = Alpine;

Alpine.start();
const el = document.getElementById('product-configurator');

if (el) {
    const product = JSON.parse(el.dataset.product || '{}');

    createApp({
        render: () => h(ProductConfigurator, { product }),
    }).mount(el);
}

