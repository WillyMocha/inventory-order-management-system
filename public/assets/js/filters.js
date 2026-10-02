/**
 * Filter daftar lewat query string (FIND-01, FR-023, FR-025).
 *
 * Progressive enhancement. Tanpa JavaScript, toolbar tetap sebuah form GET
 * dengan tombol Apply dan berfungsi penuh — modul ini hanya menghilangkan
 * satu klik dengan mengirim form segera setelah filter diubah.
 *
 * Dua hal yang harus benar, dan keduanya alasan modul ini ada:
 *
 *   1. Sort TIDAK boleh hilang saat filter berubah. Sort tinggal di query
 *      string, bukan di dalam form, jadi tanpa bantuan ia akan lenyap begitu
 *      form dikirim. Modul ini menyalinnya ke hidden input.
 *
 *   2. `page` HARUS hilang saat filter berubah. Hasil filter yang baru punya
 *      jumlah halaman yang berbeda, dan bertahan di halaman 5 dari hasil yang
 *      kini hanya 2 halaman akan menampilkan halaman kosong (FR-025).
 */

'use strict';

/** Parameter yang dikelola form itu sendiri, jadi tidak perlu disalin ulang. */
const FORM_OWNED_PARAMS = new Set(['page']);

/**
 * Menyalin parameter query string yang tidak dimiliki form ke hidden input,
 * sehingga ikut terkirim saat form disubmit.
 *
 * @param {HTMLFormElement} form
 */
function preserveQueryState(form) {
    const params = new URLSearchParams(window.location.search);

    for (const [name, value] of params) {
        if (FORM_OWNED_PARAMS.has(name)) {
            continue;
        }

        // Field yang sudah ada di form adalah sumber kebenarannya — nilai dari
        // URL tidak boleh menimpa apa yang sedang dipilih user.
        if (form.elements.namedItem(name)) {
            continue;
        }

        const hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = name;
        hidden.value = value;
        form.append(hidden);
    }
}

/**
 * Membuang input kosong sebelum submit, supaya URL-nya tetap bersih:
 * `/products` bukan `/products?search=&category=&stock=`.
 *
 * @param {HTMLFormElement} form
 */
function disableEmptyFields(form) {
    const restore = [];

    for (const element of form.elements) {
        const isValued = element instanceof HTMLInputElement
            || element instanceof HTMLSelectElement;

        if (isValued && !element.disabled && element.value === '') {
            element.disabled = true;
            restore.push(element);
        }
    }

    // Kalau navigasi dibatalkan (misalnya user menekan Esc), form harus
    // kembali dapat dipakai.
    window.setTimeout(() => {
        for (const element of restore) {
            element.disabled = false;
        }
    }, 0);
}

export function initFilters(root = document) {
    const forms = root.querySelectorAll('form.toolbar[method="get"]');

    forms.forEach((form) => {
        if (!(form instanceof HTMLFormElement) || form.dataset.filtersBound === 'true') {
            return;
        }

        form.dataset.filtersBound = 'true';

        preserveQueryState(form);

        form.addEventListener('submit', () => disableEmptyFields(form));

        // Select dikirim langsung saat diubah; input teks tidak, karena
        // mengirim di setiap ketikan akan memuat ulang halaman terus-menerus.
        // Untuk input teks, Enter dan tombol Apply sudah cukup.
        form.querySelectorAll('select').forEach((select) => {
            select.addEventListener('change', () => {
                form.requestSubmit();
            });
        });
    });
}
