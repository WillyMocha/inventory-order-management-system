# Pemeriksaan secret dan PII (T151)

NFR-004: tidak boleh ada `.env`, credential aktif, token, atau data PII di dalam repository
**maupun history-nya**.

Dijalankan 2026-09-14.

## Hasil

| Pemeriksaan | Hasil |
| --- | --- |
| `.env` ter-track git | **Tidak** — `git ls-files` tidak memuatnya |
| `.env` pernah ada di history | **Tidak** — ditelusuri `git log --all --name-only` |
| `.gitignore` menutup `.env` | **Ya** — `.env`, `.env.local`, `.env.*.local` |
| File bernama `*.pem`, `*.key`, `id_rsa`, `credential*`, `secret*` | **Tidak ada** yang ter-track |
| Credential hardcoded di source | **Tidak ada** — dicari pola `password|secret|token|api_key` diikuti string literal di `app/`, `config/`, `public/`, `views/`, `scripts/`, `database/` |
| Image upload ter-track | **Tidak** — `/storage/uploads/*` di-ignore, hanya `.gitkeep` yang disimpan |
| PII sungguhan | **Tidak ada** — satu-satunya alamat email adalah akun demo berdomain `ioms.test`, domain yang memang tidak dapat dirutekan |

## Perintah yang dipakai

```bash
git ls-files | grep -iE "\.env$|\.pem$|\.key$|credential|secret|id_rsa"
git log --all --oneline --name-only | grep -E "^\.env$"
grep -rnE "(password|passwd|secret|token|api[_-]?key)\s*=\s*['\"][^'\"]{6,}" \
     app/ config/ public/ views/ scripts/ database/
```

Pencarian credential mengecualikan penggunaan sah seperti `password_hash()`,
`password_verify()`, `PASSWORD_DEFAULT`, nama field form, dan `csrf_token`.

## Satu temuan, tingkat INFO

`.env.example` memuat `DB_PASSWORD=ioms_secret` dan `DB_ROOT_PASSWORD=root_secret`, dan
nilai-nilai itu **memang yang dipakai** stack lokal setelah `cp .env.example .env`.

**Bukan kebocoran, dan diterima**, karena: nilainya hanya memberi akses ke MySQL di dalam
container yang tidak terekspos di luar host; tidak ada satu pun kredensial produksi di
repository; dan quickstart memang mengandalkan `cp` yang langsung berjalan tanpa pengisian
manual.

**Wajib diganti sebelum deployment sungguhan**, dan disuntikkan lewat secret manager, bukan
lewat file di repository. Dicatat sebagai `tech-debt.md` TD-5.

## Catatan tentang cakupan history

History repository saat ini hanya berisi **satu commit** (`54fd119`, template awal); seluruh
pekerjaan masih berupa perubahan yang belum di-commit. Karena itu pemeriksaan history di atas
memang belum banyak yang diperiksa.

**Pemeriksaan ini wajib diulang setelah pekerjaan di-commit**, sebelum menandai rilis final —
saat itulah history yang sesungguhnya terbentuk.
