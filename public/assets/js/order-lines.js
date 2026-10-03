/**
 * Baris line order yang dapat ditambah dan dihapus (SO-01, PO-01).
 *
 * Progressive enhancement: form sudah menyediakan tiga baris kosong dari
 * server, jadi tanpa JavaScript order tetap dapat dibuat. Modul ini hanya
 * menambah kenyamanan.
 *
 * Nama field harus tetap berurutan items[0], items[1], ... setelah baris
 * dihapus, karena itulah bentuk yang dibaca PHP.
 */

'use strict';

/** Baris terakhir tidak boleh ikut terhapus — form harus selalu punya satu. */
const MINIMUM_ROWS = 1;

/**
 * Menomori ulang seluruh name dan id setelah baris ditambah atau dihapus.
 *
 * @param {HTMLTableSectionElement} body
 */
function reindexRows(body) {
    const rows = body.querySelectorAll('.line-row');

    rows.forEach((row, index) => {
        row.querySelectorAll('[name]').forEach((field) => {
            const name = field.getAttribute('name');

            if (!name) {
                return;
            }

            // items[3][quantity] -> items[<index>][quantity]
            field.setAttribute('name', name.replace(/items\[\d+]/, `items[${index}]`));
        });

        // Label memakai for/id, jadi keduanya harus ikut dinomori ulang agar
        // label tetap menunjuk ke input yang benar.
        row.querySelectorAll('select[id], input[id]').forEach((field) => {
            const oldId = field.getAttribute('id');

            if (!oldId) {
                return;
            }

            const newId = oldId.replace(/items-\d+/, `items-${index}`);
            const label = row.querySelector(`label[for="${oldId}"]`);

            field.setAttribute('id', newId);

            if (label) {
                label.setAttribute('for', newId);
            }
        });
    });
}

/**
 * @param {HTMLTableSectionElement} body
 */
function addRow(body) {
    const rows = body.querySelectorAll('.line-row');

    if (rows.length === 0) {
        return;
    }

    const clone = /** @type {HTMLTableRowElement} */ (rows[rows.length - 1].cloneNode(true));

    // Baris baru selalu kosong, termasuk pesan error yang menempel pada baris
    // yang disalin.
    clone.querySelectorAll('select, input').forEach((field) => {
        field.value = '';
    });
    clone.querySelectorAll('.field-error').forEach((error) => {
        error.remove();
    });

    // Available stock milik baris yang disalin tidak berlaku untuk baris baru
    // yang masih kosong; stock-lookup.js mengisinya lagi setelah product
    // dipilih.
    clone.querySelectorAll('.stock-hint').forEach((hint) => {
        hint.textContent = '—';
        hint.classList.remove('stock-hint--low', 'stock-hint--ok');
    });

    body.append(clone);
    reindexRows(body);
}

export function initOrderLines(root = document) {
    const table = root.querySelector('#line-table');
    const addButton = root.querySelector('#add-line');

    if (!table || !addButton) {
        return;
    }

    const body = table.querySelector('tbody');

    if (!body) {
        return;
    }

    addButton.addEventListener('click', () => addRow(body));

    // Satu listener di tbody, bukan satu per tombol: baris yang baru ditambah
    // langsung ikut berfungsi tanpa perlu dipasangi listener lagi.
    body.addEventListener('click', (event) => {
        const target = event.target;

        if (!(target instanceof Element)) {
            return;
        }

        const button = target.closest('.remove-line');

        if (!button) {
            return;
        }

        const rows = body.querySelectorAll('.line-row');

        if (rows.length <= MINIMUM_ROWS) {
            // Baris terakhir dikosongkan saja, tidak dihapus.
            button.closest('.line-row')?.querySelectorAll('select, input').forEach((field) => {
                field.value = '';
            });

            return;
        }

        button.closest('.line-row')?.remove();
        reindexRows(body);
    });
}
