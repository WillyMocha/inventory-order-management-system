/**
 * Validasi sisi client yang MENCERMINKAN aturan server (FR-029).
 *
 * Dua hal yang menentukan seluruh bentuk modul ini.
 *
 * Pertama: **server tetap satu-satunya sumber kebenaran.** Modul ini hanya
 * mempercepat umpan balik agar user tidak perlu menunggu satu perjalanan ke
 * server untuk mengetahui field yang kosong. Lolos di sini TIDAK BERARTI
 * diterima — server memvalidasi ulang setiap request, dan itulah yang
 * menentukan. Karena itu modul ini tidak pernah menandai form sebagai "valid",
 * tidak pernah menonaktifkan tombol submit, dan tidak pernah melewati
 * pemeriksaan apa pun di server.
 *
 * Kedua: aturannya dibaca dari ATRIBUT HTML yang sudah dirender server
 * (`required`, `maxlength`, `min`, `type`), bukan dari daftar aturan yang
 * ditulis ulang di JavaScript. Daftar kedua akan menyimpang dari servernya
 * cepat atau lambat; membaca atribut yang sama membuat keduanya bergerak
 * bersama.
 *
 * Form dirender dengan `novalidate`, sehingga pesan bawaan browser — yang
 * bahasanya mengikuti locale dan tidak dapat diselaraskan dengan pesan server —
 * digantikan pesan yang sama persis dengan milik `app/Support/Validator.php`.
 */

'use strict';

/** Menandai error buatan modul ini, agar error dari server tidak ikut terhapus. */
const CLIENT_ERROR_ATTR = 'data-client-error';

const ERROR_CLASS = 'field-error';

/**
 * Label field untuk pesan error. Server memakai label yang ditulis manusia
 * ("Selling price"), bukan nama field mentah ("selling_price"), jadi label
 * diambil dari elemen <label> yang memang sudah ada.
 *
 * @param {HTMLElement} field
 * @returns {string}
 */
function labelFor(field) {
    const id = field.getAttribute('id');
    const label = id ? document.querySelector(`label[for="${CSS.escape(id)}"]`) : null;
    const text = label?.textContent?.replace(/\*/g, '').trim();

    return text && text !== '' ? text : 'This field';
}

/**
 * Pesan pelanggaran pertama untuk satu field, atau null bila tidak ada.
 *
 * Urutan pemeriksaan sengaja disamakan dengan Validator di server, sehingga
 * pesan yang muncul lebih dulu juga sama.
 *
 * @param {HTMLInputElement|HTMLSelectElement|HTMLTextAreaElement} field
 * @returns {string|null}
 */
function violationOf(field) {
    const label = labelFor(field);
    const value = field.value.trim();

    if (field.hasAttribute('required') && value === '') {
        return `${label} is required.`;
    }

    // Field opsional yang dibiarkan kosong tidak diperiksa lebih jauh —
    // persis seperti server.
    if (value === '') {
        return null;
    }

    const maxLength = Number.parseInt(field.getAttribute('maxlength') ?? '', 10);

    if (Number.isInteger(maxLength) && value.length > maxLength) {
        return `${label} must not exceed ${maxLength} characters.`;
    }

    if (field.getAttribute('type') === 'email' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) {
        return `${label} must be a valid email address.`;
    }

    if (field.getAttribute('type') === 'number') {
        return numberViolation(field, label, value);
    }

    if (field.getAttribute('type') === 'date' && !isRealDate(value)) {
        return `${label} must be a valid date.`;
    }

    return null;
}

/**
 * @param {HTMLInputElement|HTMLSelectElement|HTMLTextAreaElement} field
 * @param {string} label
 * @param {string} value
 * @returns {string|null}
 */
function numberViolation(field, label, value) {
    const numeric = Number(value);

    if (!Number.isFinite(numeric)) {
        return `${label} must be a number.`;
    }

    // step="1" menandai integer, sejalan dengan integerMin() di server;
    // tanpa itu decimalMin() yang berlaku.
    if (field.getAttribute('step') === '1' && !Number.isInteger(numeric)) {
        return `${label} must be a whole number.`;
    }

    const min = Number(field.getAttribute('min'));

    if (field.hasAttribute('min') && Number.isFinite(min) && numeric < min) {
        return `${label} must be ${min} or greater.`;
    }

    return null;
}

/**
 * `new Date()` menerima 2026-02-30 dan diam-diam menggesernya ke 2 Maret.
 * Tanggal yang tidak ada di kalender harus ditolak, bukan digeser.
 *
 * @param {string} value
 * @returns {boolean}
 */
function isRealDate(value) {
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value);

    if (!match) {
        return false;
    }

    const [year, month, day] = [Number(match[1]), Number(match[2]), Number(match[3])];
    const date = new Date(Date.UTC(year, month - 1, day));

    return date.getUTCFullYear() === year
        && date.getUTCMonth() === month - 1
        && date.getUTCDate() === day;
}

/** @param {HTMLElement} field */
function clearClientError(field) {
    field.removeAttribute('aria-invalid');

    const existing = field.parentElement?.querySelector(`.${ERROR_CLASS}[${CLIENT_ERROR_ATTR}]`);

    existing?.remove();
}

/**
 * @param {HTMLElement} field
 * @param {string} message
 */
function showClientError(field, message) {
    clearClientError(field);

    const paragraph = document.createElement('p');
    paragraph.className = ERROR_CLASS;
    paragraph.setAttribute(CLIENT_ERROR_ATTR, '');
    paragraph.textContent = message;

    field.setAttribute('aria-invalid', 'true');
    field.parentElement?.append(paragraph);
}

/**
 * @param {HTMLFormElement} form
 * @returns {HTMLElement[]}
 */
function fieldsOf(form) {
    return Array.from(form.querySelectorAll('input, select, textarea')).filter((field) => {
        const type = field.getAttribute('type');

        // File diverifikasi server dari ISI file-nya (finfo), bukan dari nama
        // maupun ukuran yang dilaporkan client — tidak ada gunanya ditiru di sini.
        return type !== 'hidden' && type !== 'file' && type !== 'submit';
    });
}

export function initValidation(root = document) {
    const forms = root.querySelectorAll('form[method="post"]');

    for (const form of forms) {
        form.addEventListener('submit', (event) => {
            let firstInvalid = null;

            for (const field of fieldsOf(form)) {
                clearClientError(field);

                const violation = violationOf(field);

                if (violation !== null) {
                    showClientError(field, violation);
                    firstInvalid ??= field;
                }
            }

            if (firstInvalid === null) {
                // Tidak ada pelanggaran yang TERLIHAT dari sini. Form tetap
                // dikirim dan server tetap memvalidasi ulang seluruhnya —
                // lolos di sini bukan penerimaan.
                return;
            }

            event.preventDefault();
            firstInvalid.focus();
        });

        // Memperbaiki isian langsung menghapus pesan yang dimunculkan modul ini.
        // Pesan dari server dibiarkan sampai form dikirim ulang.
        form.addEventListener('input', (event) => {
            const target = event.target;

            if (target instanceof HTMLElement && target.hasAttribute('aria-invalid')) {
                clearClientError(target);
            }
        });
    }
}
