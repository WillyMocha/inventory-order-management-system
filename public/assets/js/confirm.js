/**
 * Konfirmasi eksplisit sebelum aksi yang memindahkan stock, mengubah status
 * order, atau menonaktifkan record.
 *
 * Progressive enhancement: tanpa JavaScript form tetap terkirim, dan server
 * tetap menjadi penegak aturan yang sesungguhnya.
 *
 * Pemakaian: <form data-confirm="Pesan yang menjelaskan efeknya">
 */

'use strict';

export function initConfirmations(root = document) {
    root.querySelectorAll('form[data-confirm]').forEach((form) => {
        if (form.dataset.confirmBound === 'true') {
            return;
        }

        form.dataset.confirmBound = 'true';
        form.addEventListener('submit', (event) => {
            const message = form.getAttribute('data-confirm');

            if (message && !window.confirm(message)) {
                event.preventDefault();
            }
        });
    });
}
