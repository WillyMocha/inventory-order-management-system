/**
 * Entry point frontend.
 *
 * Vanilla JavaScript, ES module, tanpa build step dan tanpa framework
 * (brief §4, research R-004).
 *
 * Seluruh perilaku di sini bersifat progressive enhancement — aplikasi tetap
 * berfungsi penuh tanpa JavaScript, karena validasi dan authorization adalah
 * tanggung jawab server.
 */

'use strict';

import { initConfirmations } from './confirm.js';
import { initNavDrawer } from './nav-drawer.js';
import { initFilters } from './filters.js';
import { initOrderLines } from './order-lines.js';
import { initStockLookup } from './stock-lookup.js';
import { initValidation } from './validation.js';

/**
 * Menandai link navigasi yang sedang aktif bila server belum menandainya.
 * Murni kosmetik.
 */
function markActiveNav() {
    const links = document.querySelectorAll('.nav-link');
    const path = window.location.pathname;

    for (const link of links) {
        if (link.hasAttribute('aria-current')) {
            return;
        }

        const href = link.getAttribute('href');

        if (href && href !== '/' && path.startsWith(href)) {
            link.setAttribute('aria-current', 'page');
            return;
        }
    }
}

document.addEventListener('DOMContentLoaded', () => {
    markActiveNav();
    initNavDrawer();
    initConfirmations();
    initOrderLines();
    initFilters();
    initStockLookup();
    initValidation();
});
