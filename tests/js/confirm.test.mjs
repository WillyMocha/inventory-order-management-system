/**
 * Test confirm.js dengan test runner bawaan Node (`node --test`), tanpa
 * dependency dan tanpa build step.
 *
 * Yang diuji adalah dua bagian yang memuat keputusan: splitMessage() — cara
 * pesan data-confirm dipecah menjadi judul dan isi modal — serta
 * confirmButtonOf() — label dan varian tombol konfirmasi. Perkabelan
 * `<dialog>` di initConfirmations() tetap diperiksa lewat browser.
 *
 *   docker run --rm -v "$PWD":/app -w /app node:22-alpine node --test "tests/js/*.test.mjs"
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { splitMessage, confirmButtonOf } from '../../public/assets/js/confirm.js';

/** Pengganti tombol submit secukupnya: textContent dan classList saja. */
function fakeButton(text, classes) {
    return {
        textContent: text,
        classList: { contains: (name) => classes.includes(name) },
    };
}

test('splitMessage memisahkan pertanyaan pertama sebagai judul', () => {
    const result = splitMessage(
        'Deactivate this customer? Existing orders keep their history, but it cannot be chosen on new ones.',
    );

    assert.deepEqual(result, {
        title: 'Deactivate this customer?',
        body: 'Existing orders keep their history, but it cannot be chosen on new ones.',
    });
});

test('splitMessage menjadikan pesan tanpa penjelasan sebagai judul saja', () => {
    assert.deepEqual(splitMessage('  Create this sales order as a draft?  '), {
        title: 'Create this sales order as a draft?',
        body: '',
    });
});

test('splitMessage hanya memecah pada tanda tanya pertama', () => {
    const result = splitMessage('Record this receipt? Stock in Main. Warehouse will increase? Yes.');

    assert.equal(result.title, 'Record this receipt?');
    assert.equal(result.body, 'Stock in Main. Warehouse will increase? Yes.');
});

test('splitMessage tanpa tanda tanya mengembalikan seluruh pesan sebagai judul', () => {
    assert.deepEqual(splitMessage('Proceed with this action.'), {
        title: 'Proceed with this action.',
        body: '',
    });
});

test('confirmButtonOf meniru label dan varian danger tombol aslinya', () => {
    assert.deepEqual(confirmButtonOf(fakeButton('Reject', ['btn', 'btn--danger'])), {
        label: 'Reject',
        className: 'btn btn--danger',
    });
});

test('confirmButtonOf memakai primary untuk tombol non-danger dan merapikan whitespace', () => {
    assert.deepEqual(confirmButtonOf(fakeButton('\n   Issue   goods\n ', ['btn', 'btn--sm'])), {
        label: 'Issue goods',
        className: 'btn btn--primary',
    });
});

test('confirmButtonOf jatuh ke label Confirm bila tombol tidak diketahui', () => {
    assert.deepEqual(confirmButtonOf(null), {
        label: 'Confirm',
        className: 'btn btn--primary',
    });
});
