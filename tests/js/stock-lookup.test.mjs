/**
 * Test stock-lookup.js dengan test runner bawaan Node (`node --test`), tanpa
 * dependency dan tanpa build step (tech-debt TD-4).
 *
 * Yang diuji adalah dua bagian yang memuat keputusan: fetchAvailable() — URL,
 * credentials, dan cara menanggapi error — serta render() — teks dan penanda
 * low/ok. Perkabelan event DOM di initStockLookup() tetap diperiksa lewat
 * pemeriksaan terender di browser (docs/testing/responsive-accessibility.md).
 *
 * Dijalankan lewat Docker agar tidak bergantung pada Node di komputer peserta:
 *   docker run --rm -v "$PWD":/app -w /app node:22-alpine node --test "tests/js/*.test.mjs"
 */

import { test, afterEach } from 'node:test';
import assert from 'node:assert/strict';
import { fetchAvailable, render } from '../../public/assets/js/stock-lookup.js';

const realFetch = globalThis.fetch;

afterEach(() => {
    globalThis.fetch = realFetch;
});

/** Pengganti fetch yang mencatat pemanggilannya dan membalas sesuai skenario. */
function fakeFetch(status, body) {
    const calls = [];

    globalThis.fetch = async (url, options) => {
        calls.push({ url, options });

        return {
            ok: status >= 200 && status < 300,
            status,
            json: async () => body,
        };
    };

    return calls;
}

/** Sel tabel minimal: hanya textContent dan classList yang dipakai render(). */
function fakeCell() {
    const classes = new Set(['stock-hint--ok']);

    return {
        textContent: '',
        classList: {
            add: (...names) => names.forEach((n) => classes.add(n)),
            remove: (...names) => names.forEach((n) => classes.delete(n)),
            contains: (name) => classes.has(name),
        },
        classes,
    };
}

// ---------------------------------------------------------- fetchAvailable

test('memanggil endpoint availability dengan id ter-encode dan session yang sama', async () => {
    const calls = fakeFetch(200, { availableQuantity: 7 });

    const quantity = await fetchAvailable('12', '3/x', new AbortController().signal);

    assert.equal(quantity, 7);
    assert.equal(calls.length, 1);
    assert.equal(calls[0].url, '/api/products/12/warehouses/3%2Fx/available');
    assert.equal(calls[0].options.credentials, 'same-origin');
    assert.equal(calls[0].options.headers.Accept, 'application/json');
});

test('nol adalah angka yang sah, bukan "tidak diketahui"', async () => {
    fakeFetch(200, { availableQuantity: 0 });

    assert.equal(await fetchAvailable('1', '1', new AbortController().signal), 0);
});

for (const status of [401, 403, 404, 500]) {
    test(`status ${status} menjadi null, tanpa membaca isi error`, async () => {
        fakeFetch(status, { error: { code: 'x' } });

        assert.equal(await fetchAvailable('1', '1', new AbortController().signal), null);
    });
}

test('respons tanpa angka yang sah menjadi null', async () => {
    fakeFetch(200, { availableQuantity: '7' });
    assert.equal(await fetchAvailable('1', '1', new AbortController().signal), null);

    fakeFetch(200, {});
    assert.equal(await fetchAvailable('1', '1', new AbortController().signal), null);
});

test('pembatalan request diteruskan ke pemanggil sebagai AbortError', async () => {
    globalThis.fetch = async (url, { signal }) => {
        signal.throwIfAborted();
        return { ok: true, json: async () => ({ availableQuantity: 1 }) };
    };

    const controller = new AbortController();
    controller.abort();

    await assert.rejects(fetchAvailable('1', '1', controller.signal), { name: 'AbortError' });
});

// ------------------------------------------------------------------ render

test('quantity tidak diketahui ditampilkan sebagai tanda pisah tanpa penanda', () => {
    const cell = fakeCell();

    render(cell, null, 5);

    assert.equal(cell.textContent, '—');
    assert.equal(cell.classes.size, 0);
});

test('permintaan melebihi stock ditandai low, tanpa menghalangi apa pun', () => {
    const cell = fakeCell();

    render(cell, 3, 5);

    assert.equal(cell.textContent, '3 available');
    assert.ok(cell.classList.contains('stock-hint--low'));
    assert.ok(!cell.classList.contains('stock-hint--ok'));
});

test('permintaan tepat sebesar stock masih ok', () => {
    const cell = fakeCell();

    render(cell, 5, 5);

    assert.ok(cell.classList.contains('stock-hint--ok'));
    assert.ok(!cell.classList.contains('stock-hint--low'));
});
