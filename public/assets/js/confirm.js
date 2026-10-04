/**
 * Konfirmasi eksplisit sebelum aksi yang memindahkan stock, mengubah status
 * order, atau menonaktifkan record.
 *
 * Konfirmasi ditampilkan sebagai modal `<dialog>` bawaan browser — bukan
 * window.confirm(), yang tampilannya tidak dapat diatur dan memuat nama host
 * ("localhost:8080 says"). Tanpa library: `<dialog>` sudah menyediakan focus
 * trap, tombol Escape, dan `::backdrop`.
 *
 * Progressive enhancement: tanpa JavaScript form tetap terkirim, dan server
 * tetap menjadi penegak aturan yang sesungguhnya. Browser yang belum mengenal
 * `<dialog>` kembali memakai window.confirm().
 *
 * Pemakaian: <form data-confirm="Pertanyaan? Penjelasan efeknya.">
 */

'use strict';

/** Satu modal dipakai bersama oleh seluruh form; dibuat saat pertama dibutuhkan. */
let modal = null;

/** Form yang sedang menunggu jawaban modal, beserta tombol yang menekannya. */
let pending = null;

/**
 * Memecah pesan data-confirm menjadi judul (kalimat tanya pertama) dan isi
 * (penjelasan efeknya). Pesan tanpa penjelasan menjadi judul seluruhnya.
 */
export function splitMessage(message) {
    const text = message.trim();
    const match = text.match(/^(.+?\?)\s+(.+)$/s);

    if (match === null) {
        return { title: text, body: '' };
    }

    return { title: match[1], body: match[2] };
}

/**
 * Label dan varian tombol konfirmasi meniru tombol submit aslinya, supaya
 * "Reject" tetap merah dan "Approve" tetap biru di dalam modal.
 */
export function confirmButtonOf(submitter) {
    const label = submitter?.textContent.replace(/\s+/g, ' ').trim() ?? '';
    const isDanger = submitter?.classList.contains('btn--danger') ?? false;

    return {
        label: label === '' ? 'Confirm' : label,
        className: isDanger ? 'btn btn--danger' : 'btn btn--primary',
    };
}

function ensureModal() {
    if (modal !== null) {
        return modal;
    }

    modal = document.createElement('dialog');
    modal.className = 'modal';
    modal.setAttribute('aria-labelledby', 'confirm-modal-title');
    modal.setAttribute('aria-describedby', 'confirm-modal-body');

    // Cancel sengaja diletakkan pertama: ia yang menerima fokus awal, sehingga
    // menekan Enter secara refleks tidak menjalankan aksi yang tidak dapat
    // dibatalkan.
    modal.innerHTML = `
        <form method="dialog" class="modal-panel">
            <h2 class="modal-title" id="confirm-modal-title"></h2>
            <p class="modal-body" id="confirm-modal-body"></p>
            <div class="modal-actions">
                <button type="submit" class="btn" value="cancel">Cancel</button>
                <button type="submit" value="confirm" data-modal-confirm></button>
            </div>
        </form>`;

    // Menekan latar gelap di luar panel sama dengan Cancel. Dialog tidak
    // memiliki padding, jadi klik yang target-nya dialog itu sendiri pasti
    // berasal dari ::backdrop.
    modal.addEventListener('click', (event) => {
        if (event.target === modal) {
            modal.close('cancel');
        }
    });

    modal.addEventListener('close', () => {
        if (pending === null) {
            return;
        }

        const { form, submitter } = pending;
        pending = null;

        if (modal.returnValue !== 'confirm') {
            submitter?.focus();
            return;
        }

        // Dikirim ulang lewat requestSubmit() — bukan form.submit() — agar
        // listener submit lain (validasi sisi klien) tetap berjalan.
        form.dataset.confirmed = 'true';
        form.requestSubmit(submitter ?? undefined);
    });

    document.body.append(modal);

    return modal;
}

function openModal(form, submitter, message) {
    const dialog = ensureModal();
    const { title, body } = splitMessage(message);
    const button = confirmButtonOf(submitter);

    dialog.querySelector('.modal-title').textContent = title;

    const bodyElement = dialog.querySelector('.modal-body');
    bodyElement.textContent = body;
    bodyElement.hidden = body === '';

    const confirmButton = dialog.querySelector('[data-modal-confirm]');
    confirmButton.textContent = button.label;
    confirmButton.className = button.className;

    pending = { form, submitter };
    dialog.returnValue = '';
    dialog.showModal();
}

export function initConfirmations(root = document) {
    const supportsDialog = typeof HTMLDialogElement === 'function';

    root.querySelectorAll('form[data-confirm]').forEach((form) => {
        if (form.dataset.confirmBound === 'true') {
            return;
        }

        form.dataset.confirmBound = 'true';
        form.addEventListener('submit', (event) => {
            const message = form.getAttribute('data-confirm');

            if (!message) {
                return;
            }

            // Kiriman ulang setelah pengguna menekan tombol konfirmasi.
            if (form.dataset.confirmed === 'true') {
                delete form.dataset.confirmed;
                return;
            }

            if (!supportsDialog) {
                if (!window.confirm(message)) {
                    event.preventDefault();
                }
                return;
            }

            event.preventDefault();
            openModal(form, event.submitter ?? null, message);
        });
    });
}
