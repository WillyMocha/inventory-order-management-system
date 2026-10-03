/**
 * Penyempurnaan drawer navigasi pada layar sempit.
 *
 * Drawer-nya sendiri BUKAN tanggung jawab modul ini: pembuka-tutupnya adalah
 * checkbox `#nav-toggle` yang digerakkan CSS, sehingga navigasi tetap dapat
 * dibuka walaupun JavaScript mati sama sekali — syarat yang ditetapkan brief.
 *
 * Yang ditambahkan di sini hanya dua hal yang memang tidak dapat dilakukan
 * CSS:
 *   1. menyelaraskan `aria-expanded` supaya screen reader tahu keadaannya
 *   2. menutup drawer dengan tombol Escape
 *
 * Kalau modul ini gagal dimuat, drawer tetap berfungsi penuh — hanya kedua
 * kenyamanan di atas yang hilang.
 */

'use strict';

export function initNavDrawer(root = document) {
    const toggle = root.querySelector('#nav-toggle');

    if (!(toggle instanceof HTMLInputElement)) {
        return;
    }

    const syncExpandedState = () => {
        toggle.setAttribute('aria-expanded', toggle.checked ? 'true' : 'false');
    };

    toggle.addEventListener('change', syncExpandedState);

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape' || !toggle.checked) {
            return;
        }

        toggle.checked = false;
        syncExpandedState();

        // Fokus dikembalikan ke tombol pembukanya, bukan dibiarkan menggantung
        // di dalam drawer yang sudah tertutup.
        const opener = root.querySelector('.topbar-toggle');

        if (opener instanceof HTMLElement) {
            opener.focus();
        }
    });

    syncExpandedState();
}
