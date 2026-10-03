/**
 * Available stock di samping setiap line Sales Order (API-01, FR-028).
 *
 * Progressive enhancement murni. Angka yang ditampilkan modul ini bersifat
 * PANDUAN, bukan keputusan: kecukupan stock yang mengikat diperiksa server di
 * dalam transaction goods issue dengan SELECT ... FOR UPDATE (ARCH-02). Karena
 * itu modul ini tidak pernah menonaktifkan tombol submit maupun menghalangi
 * order dibuat — angkanya bisa sudah basi sedetik kemudian, dan form tetap
 * harus dapat dikirim tanpa JavaScript sama sekali.
 */

'use strict';

/** Jeda sebelum request dikirim, agar mengganti pilihan cepat tidak membanjiri server. */
const DEBOUNCE_MS = 250;

const ENDPOINT_PREFIX = '/api/products';

/** Teks saat warehouse asal belum dipilih — tanpa itu tidak ada yang bisa ditanyakan. */
const NEEDS_WAREHOUSE = 'Select a warehouse';

/**
 * Meminta available quantity untuk satu pasangan product dan warehouse.
 *
 * @param {string} productId
 * @param {string} warehouseId
 * @param {AbortSignal} signal
 * @returns {Promise<number|null>} null bila tidak dapat ditentukan
 */
async function fetchAvailable(productId, warehouseId, signal) {
    const url = `${ENDPOINT_PREFIX}/${encodeURIComponent(productId)}`
        + `/warehouses/${encodeURIComponent(warehouseId)}/available`;

    const response = await fetch(url, {
        signal,
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
    });

    if (!response.ok) {
        // 401, 403 dan 404 semuanya berarti hal yang sama bagi panduan ini:
        // tidak ada angka untuk ditampilkan. Endpoint-nya selalu membalas JSON,
        // tetapi modul ini memang tidak perlu membaca isi error-nya.
        return null;
    }

    const body = await response.json();
    const quantity = body?.availableQuantity;

    return typeof quantity === 'number' ? quantity : null;
}

/**
 * @param {HTMLElement} cell
 * @param {number|null} quantity
 * @param {number} requested
 */
function render(cell, quantity, requested) {
    cell.classList.remove('stock-hint--low', 'stock-hint--ok');

    if (quantity === null) {
        cell.textContent = '—';
        return;
    }

    cell.textContent = `${quantity} available`;

    // Penandaan ini semata-mata panduan visual. Order tetap boleh dibuat:
    // stock bisa bertambah sebelum goods issue dijalankan.
    cell.classList.add(requested > quantity ? 'stock-hint--low' : 'stock-hint--ok');
}

/**
 * @param {HTMLTableRowElement} row
 * @param {HTMLSelectElement} warehouseSelect
 */
function updateRow(row, warehouseSelect) {
    const cell = row.querySelector('.stock-hint');
    const productSelect = row.querySelector('select[name*="[product_id]"]');
    const quantityInput = row.querySelector('input[name*="[quantity]"]');

    if (!(cell instanceof HTMLElement) || !(productSelect instanceof HTMLSelectElement)) {
        return;
    }

    const productId = productSelect.value;
    const warehouseId = warehouseSelect.value;

    if (!productId) {
        cell.textContent = '—';
        cell.classList.remove('stock-hint--low', 'stock-hint--ok');
        return;
    }

    if (!warehouseId) {
        cell.textContent = NEEDS_WAREHOUSE;
        cell.classList.remove('stock-hint--low', 'stock-hint--ok');
        return;
    }

    // Request sebelumnya untuk baris ini dibatalkan, sehingga jawaban yang
    // datang terlambat tidak menimpa jawaban yang lebih baru.
    row.stockLookupController?.abort();

    const controller = new AbortController();
    row.stockLookupController = controller;

    const requested = quantityInput instanceof HTMLInputElement
        ? Number.parseInt(quantityInput.value, 10) || 0
        : 0;

    cell.textContent = 'Checking…';

    fetchAvailable(productId, warehouseId, controller.signal)
        .then((quantity) => render(cell, quantity, requested))
        .catch((error) => {
            if (error.name === 'AbortError') {
                return;
            }

            // Jaringan gagal: form tetap dapat dikirim, panduannya saja yang
            // tidak tersedia.
            render(cell, null, requested);
        });
}

export function initStockLookup(root = document) {
    const table = root.querySelector('#line-table');
    const warehouseSelect = root.querySelector('#warehouse_id');

    if (!table || !(warehouseSelect instanceof HTMLSelectElement)) {
        return;
    }

    const body = table.querySelector('tbody');

    if (!body) {
        return;
    }

    let timer = null;

    /** @param {Element|null} row */
    const scheduleFor = (row) => {
        if (!(row instanceof HTMLTableRowElement)) {
            return;
        }

        window.clearTimeout(timer);
        timer = window.setTimeout(() => updateRow(row, warehouseSelect), DEBOUNCE_MS);
    };

    const refreshEveryRow = () => {
        body.querySelectorAll('.line-row').forEach((row) => {
            if (row instanceof HTMLTableRowElement) {
                updateRow(row, warehouseSelect);
            }
        });
    };

    // Satu listener di tbody, bukan satu per baris: baris yang baru ditambah
    // order-lines.js langsung ikut berfungsi tanpa dipasangi listener lagi.
    body.addEventListener('change', (event) => {
        const target = event.target;

        if (target instanceof Element && target.closest('select, input')) {
            scheduleFor(target.closest('.line-row'));
        }
    });

    body.addEventListener('input', (event) => {
        const target = event.target;

        if (target instanceof HTMLInputElement && target.name.includes('[quantity]')) {
            scheduleFor(target.closest('.line-row'));
        }
    });

    // Mengganti warehouse asal mengubah jawaban untuk SETIAP baris.
    warehouseSelect.addEventListener('change', refreshEveryRow);

    // Form yang dikembalikan server setelah validasi gagal sudah berisi
    // pilihan user; angkanya diisi sekali saat halaman dibuka.
    refreshEveryRow();
}
