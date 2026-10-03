# Pemeriksaan secret dan PII (T151)

NFR-004: tidak boleh ada `.env`, credential aktif, token, atau data PII di dalam repository
**maupun history-nya**.

Dijalankan pertama kali 2026-09-14; **diulang 2026-10-03** terhadap history 23 commit, termasuk
folder `specs/001-inventory-order-management/` yang kini ikut di-commit.

## Hasil

| Pemeriksaan | Hasil |
| --- | --- |
| `.env` ter-track git | **Tidak** — `git ls-files` tidak memuatnya |
| `.env` pernah ada di history | **Tidak** — ditelusuri `git log --all --name-only` |
| `.gitignore` menutup `.env` | **Ya** — `.env`, `.env.local`, `.env.*.local` |
| File bernama `*.pem`, `*.key`, `id_rsa`, `credential*`, `secret*` | **Tidak ada** yang ter-track (satu-satunya kecocokan `secret` adalah dokumen ini sendiri) |
| Pola token pada seluruh diff history (`PRIVATE KEY`, `AKIA…`, `ghp_…`, `sk-…`, `xox…`) | **Tidak ada** |
| Credential hardcoded di source | **Tidak ada** — dicari pola `password|secret|token|api_key` diikuti string literal di `app/`, `config/`, `public/`, `views/`, `scripts/`, `database/` |
| Image upload ter-track | **Tidak** — `/storage/uploads/*` di-ignore, hanya `.gitkeep` yang disimpan |
| PII sungguhan | **Tidak ada** — satu-satunya alamat email adalah akun demo berdomain `ioms.test`, domain yang memang tidak dapat dirutekan |

## Perintah yang dipakai

```bash
git ls-files | grep -iE "\.env$|\.pem$|\.key$|credential|secret|id_rsa"
git log --all --oneline --name-only | grep -E "^\.env$"
grep -rnE "(password|passwd|secret|token|api[_-]?key)\s*=\s*['\"][^'\"]{6,}" \
     app/ config/ public/ views/ scripts/ database/ specs/001-inventory-order-management/
git log --all -p | grep -E "^\+.*(PRIVATE KEY|AKIA[0-9A-Z]{16}|ghp_|sk-[A-Za-z0-9]{20,}|xox[bp]-)"
```

Pencarian credential mengecualikan penggunaan sah seperti `password_hash()`,
`password_verify()`, `PASSWORD_DEFAULT`, nama field form, dan `csrf_token`.

## Satu temuan, tingkat INFO

`.env.example` memuat `DB_PASSWORD=ioms_secret` dan `DB_ROOT_PASSWORD=root_secret`, dan
nilai yang sama menjadi nilai default di `compose.yaml` (`${DB_PASSWORD:-ioms_secret}`, termasuk
pada healthcheck). Nilai-nilai itu **memang yang dipakai** stack lokal setelah
`cp .env.example .env`.

**Bukan kebocoran, dan diterima**, karena:
- Nilainya hanya memberi akses ke MySQL container lokal. Port MySQL dipublikasikan **hanya ke
  `127.0.0.1`** (`127.0.0.1:${DB_HOST_PORT:-3307}:3306` di `compose.yaml`), sehingga tidak
  dapat dijangkau dari mesin lain di jaringan. Sebelum 2026-10-03 port ini terbuka di semua
  interface host; sudah diperbaiki.
- Tidak ada satu pun kredensial produksi di repository.
- Quickstart memang mengandalkan `cp` yang langsung berjalan tanpa pengisian manual.

**Wajib diganti sebelum deployment sungguhan**, dan disuntikkan lewat secret manager, bukan
lewat file di repository. Dicatat sebagai `tech-debt.md` TD-5.

## Catatan tentang cakupan history

Pemeriksaan 2026-10-03 mencakup seluruh **23 commit**. Tidak ada `.env`, credential, maupun
token yang pernah masuk history.

**Satu temuan, tingkat INFO.** File `Project Brief - Programmer.pdf` pernah ter-commit di
`specs/001-inventory-order-management/inputs/`, lalu terhapus dari tree oleh commit
pembersihan berikutnya. Isinya bukan secret maupun PII, melainkan materi brief dari trainer.
Tetapi itu bukan karya peserta, dan masih dapat diambil dari history. Kini `*.pdf` di-ignore.
Menghapusnya dari history menuntut penulisan ulang history (`git filter-repo`) dan force push,
jadi diputuskan oleh pemilik repository, bukan dilakukan diam-diam.

Ulangi pemeriksaan ini sesaat sebelum membuat tag rilis final.
