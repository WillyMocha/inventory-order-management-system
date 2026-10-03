# Kontrak HTTP Route (server-rendered)

**Feature**: `001-inventory-order-management`
**Tanggal**: 2026-09-10
**Pelengkap**: [`openapi.yaml`](./openapi.yaml) — JSON surface. Dokumen ini mencakup seluruh
route HTML.

Aplikasi ini server-rendered. Route HTML **tidak** diduplikasi sebagai REST resource; lihat
research R-009. Tabel di bawah adalah route table yang dieksekusi front controller, dan
merupakan sumber kebenaran bagi authorization guard.

## Aturan yang berlaku untuk seluruh route

- **Deny by default** (security standard §2). Route yang tidak mencantumkan role secara
  eksplisit tidak dapat diakses siapa pun. Tidak ada route yang mengandalkan
  "URL-nya tidak diketahui" sebagai proteksi.
- **Identitas** diambil dari session yang terverifikasi, tidak pernah dari request payload.
  `userId` dan `role` pada body atau query diabaikan sepenuhnya.
- **CSRF token** wajib pada seluruh method non-GET, diverifikasi dengan `hash_equals`.
- **Ownership scoping** masuk ke dalam `WHERE` clause query, bukan hanya filter di
  application layer.
- **404, bukan 403**, untuk resource yang ada tetapi berada di luar scope pemanggil (misalnya
  Sales membuka Sales Order milik Sales lain) — 403 akan membocorkan keberadaan record.
  403 dipakai ketika sebuah role memang tidak boleh menyentuh route tersebut sama sekali,
  karena hal itu tidak membocorkan apa pun.
- **Belum login** pada route HTML → redirect ke `/login`. Pada route `/api/*` → JSON 401
  (API-01).
- **Akun divalidasi ulang pada setiap request terautentikasi** (002 FR-012). Setelah guard,
  front controller memeriksa bahwa user di session masih ada, masih aktif, dan role-nya masih
  sama dengan yang tercatat di session (`AuthService::activeSessionUser`). Bila tidak, session
  diakhiri dan request diperlakukan seperti belum login (redirect `/login` atau JSON 401).
  Akun yang dinonaktifkan atau diganti role-nya oleh Admin kehilangan akses pada request
  berikutnya, bukan saat logout.

Singkatan role: **A** = Admin, **S** = Sales, **W** = WarehouseStaff.

---

## Authentication

| Method | Path | Auth | Role | Authorization / catatan |
| --- | --- | --- | --- | --- |
| GET | `/login` | publik | — | Sudah login → redirect ke dashboard |
| POST | `/login` | publik | — | Rate limit 5 kegagalan / 15 menit per (email, IP). Pesan error seragam. `session_regenerate_id(true)` setelah berhasil |
| POST | `/logout` | wajib | A S W | CSRF wajib. Session dihancurkan, cookie dikadaluarsakan |

Tidak ada route registrasi publik dan tidak ada password reset (spec A-012, USR-01).

## Dashboard

| Method | Path | Auth | Role | Authorization / catatan |
| --- | --- | --- | --- | --- |
| GET | `/` | wajib | A S W | Dialihkan ke dashboard sesuai role |
| GET | `/dashboard` | wajib | A S W | Isi berbeda per role. Sales hanya melihat ringkasan order miliknya — di-scope lewat `created_by` di dalam query (§1.2) |

## Profil sendiri (002-user-profile-page)

| Method | Path | Auth | Role | Authorization / catatan |
| --- | --- | --- | --- | --- |
| GET | `/profile` | wajib | A S W | Pemilik profil **selalu** dari session; `id`/`user_id` pada query diabaikan. Read-only kecuali password |
| POST | `/profile/password` | wajib | A S W | CSRF (gagal → 403). Password saat ini wajib (re-auth). Validasi gagal → 422 dengan pesan per field, password tidak pernah dikembalikan. Batas percobaan **berbagi counter dengan `POST /login`** (5 / 15 menit per email + IP) → 429 di halaman profil. Berhasil → session id diperbarui, 302 `/profile` dengan flash |

Rincian kontrak ada di
[`specs/002-user-profile-page/contracts/http-routes.md`](../../002-user-profile-page/contracts/http-routes.md).

## User management (USR-01)

| Method | Path | Auth | Role | Authorization / catatan |
| --- | --- | --- | --- | --- |
| GET | `/users` | wajib | **A** | S dan W → 403 |
| GET | `/users/create` | wajib | **A** | |
| POST | `/users` | wajib | **A** | CSRF. Email unik |
| GET | `/users/{id}/edit` | wajib | **A** | |
| POST | `/users/{id}` | wajib | **A** | CSRF |
| POST | `/users/{id}/toggle-active` | wajib | **A** | CSRF. Admin tidak dapat menonaktifkan dirinya sendiri |
| POST | `/users/{id}/password` | wajib | **A** | CSRF + **step-up re-auth**: Admin wajib memasukkan ulang password miliknya (security §7) |

## Master data

Seluruh route master data hanya dapat ditulis oleh Admin. S dan W memiliki akses baca
terbatas sesuai §1.2.

| Method | Path | Auth | Role | Authorization / catatan |
| --- | --- | --- | --- | --- |
| GET | `/products` | wajib | A S W | Search, filter, sort, pagination (FIND-01) |
| GET | `/products/{id}` | wajib | A S W | Detail + rincian stock per warehouse |
| GET | `/products/create` | wajib | **A** | |
| POST | `/products` | wajib | **A** | CSRF. Multipart bila ada image. Validasi tipe & ukuran (R-006) |
| GET | `/products/{id}/edit` | wajib | **A** | |
| POST | `/products/{id}` | wajib | **A** | CSRF |
| POST | `/products/{id}/toggle-active` | wajib | **A** | CSRF. Deactivate saja, tidak pernah hard delete |
| GET | `/products/{id}/image` | wajib | A S W | Menyajikan file dari luar document root dengan `Content-Type` eksplisit (R-006) |
| GET | `/categories` | wajib | **A** | |
| POST | `/categories`, `/categories/{id}`, `/categories/{id}/toggle-active` | wajib | **A** | CSRF |
| GET | `/warehouses` | wajib | A W | W hanya baca |
| POST | `/warehouses`, `/warehouses/{id}`, `/warehouses/{id}/toggle-active` | wajib | **A** | CSRF |
| GET | `/suppliers` | wajib | **A** | |
| POST | `/suppliers`, `/suppliers/{id}`, `/suppliers/{id}/toggle-active` | wajib | **A** | CSRF |
| GET | `/customers` | wajib | A S | S hanya baca — dibutuhkan saat membuat Sales Order |
| POST | `/customers`, `/customers/{id}`, `/customers/{id}/toggle-active` | wajib | **A** | CSRF |

## Purchase Order & goods receipt (PO-01)

| Method | Path | Auth | Role | Authorization / catatan |
| --- | --- | --- | --- | --- |
| GET | `/purchase-orders` | wajib | A W | S → 403. Search, filter status, sort tanggal, pagination |
| GET | `/purchase-orders/create` | wajib | A W | W boleh mengusulkan (§1.2) |
| POST | `/purchase-orders` | wajib | A W | CSRF. Minimal satu item. Status awal `Draft` |
| GET | `/purchase-orders/{id}` | wajib | A W | Detail + item + outstanding qty + riwayat movement |
| POST | `/purchase-orders/{id}/submit` | wajib | A W | CSRF. `Draft` → `Ordered` |
| POST | `/purchase-orders/{id}/cancel` | wajib | **A** | CSRF. Diizinkan sebelum `Received` (A-004) |
| GET | `/purchase-orders/{id}/receive` | wajib | A W | Form goods receipt |
| POST | `/purchase-orders/{id}/receive` | wajib | A W | CSRF. **Transaction**: tambah `product_stock`, tulis `stock_ledger` type `Receipt`. Ditolak bila melebihi outstanding qty (A-005) |

## Sales Order, approval & goods issue (SO-01)

Route paling sensitif di sistem ini. Aturan segregation of duties ditegakkan di Service
layer, bukan hanya oleh route table.

| Method | Path | Auth | Role | Authorization / catatan |
| --- | --- | --- | --- | --- |
| GET | `/sales-orders` | wajib | A S W | **S hanya melihat order miliknya** — `WHERE created_by = :sessionUserId`. W melihat antrean fulfillment |
| GET | `/sales-orders/create` | wajib | A S | W → 403 |
| POST | `/sales-orders` | wajib | A S | CSRF. `created_by` diambil dari session, tidak pernah dari payload. Status awal `Draft` |
| GET | `/sales-orders/{id}` | wajib | A S W | S mengakses order milik orang lain → **404**, bukan 403 |
| POST | `/sales-orders/{id}/submit` | wajib | A S | CSRF. `Draft` → `PendingApproval`. S hanya untuk order miliknya |
| POST | `/sales-orders/{id}/approve` | wajib | **A** | CSRF. S → **403 selalu**, termasuk untuk order miliknya sendiri (§1.2, FR-018). Service memeriksa `approved_by <> created_by` **dan** role Admin |
| POST | `/sales-orders/{id}/reject` | wajib | **A** | CSRF. `PendingApproval` → `Cancelled` |
| POST | `/sales-orders/{id}/cancel` | wajib | A S | CSRF. S hanya order miliknya, hanya sebelum `Fulfilled` |
| GET | `/sales-orders/{id}/issue` | wajib | A W | Form goods issue. Hanya untuk status `Approved` |
| POST | `/sales-orders/{id}/issue` | wajib | A W | CSRF. **Transaction + `SELECT ... FOR UPDATE`** (R-002): lock baris `product_stock` urut `product_id` lalu `warehouse_id`, verifikasi kecukupan, tulis `stock_ledger` type `Issue`, kurangi stock, commit. Ditolak bila stock tidak cukup |

## Report (REPORT-01)

| Method | Path | Auth | Role | Authorization / catatan |
| --- | --- | --- | --- | --- |
| GET | `/reports` | wajib | A S W | Form pemilihan rentang tanggal |
| GET | `/reports/stock-movement.csv` | wajib | A W | Rentang tanggal dibatasi maksimal 366 hari. Satu export berjalan per session (R-005). Streaming `fputcsv` |
| GET | `/reports/orders.csv` | wajib | A S W | **S: hanya order miliknya** — di-scope di `WHERE`. Batas rentang sama |
| GET | `/reports/purchase-orders.csv` | wajib | A W | Status Purchase Order beserta qty dipesan/diterima. Batas rentang sama. Tafsiran REPORT-01: `docs/planning/decisions.md` D-03 |

## JSON API

Didefinisikan lengkap pada [`openapi.yaml`](./openapi.yaml). Ringkasan:

| Method | Path | Auth | Role | Catatan |
| --- | --- | --- | --- | --- |
| GET | `/api/products/{sku}/availability` | wajib | A S W | 401 sebagai JSON, bukan redirect |
| GET | `/api/products/{productId}/warehouses/{warehouseId}/available` | wajib | A S W | Indikatif; binding check tetap di goods issue |
| GET | `/api/dashboard/low-stock` | wajib | **A W** | S → 403 |

## Error route

| Kondisi | Hasil |
| --- | --- |
| Belum login, route HTML | 302 ke `/login` |
| Belum login, route `/api/*` | 401 JSON |
| Role tidak berhak atas route | 403, halaman aman |
| Resource di luar scope pemanggil | **404**, agar keberadaan record tidak bocor |
| Route atau record tidak ada | 404 |
| CSRF token tidak valid | 403 |
| Rate limit login terlampaui | 429 dengan pesan seragam |
| Rate limit ganti password sendiri terlampaui | 429, halaman profil dirender ulang dengan pesan umum (counter yang sama dengan login) |
| Akun di session sudah tidak aktif, terhapus, atau role-nya berubah | Session diakhiri → redirect `/login` (HTML) atau JSON 401 (`/api/*`) |
| Exception tak tertangani | 500, halaman aman. Detail hanya ke server log — tidak pernah ke user (ERR-01) |

---

## Ringkasan matriks authorization

Dipakai `/rudis.implement` sebagai acuan security gate.

| Kemampuan | Admin | Sales | Warehouse Staff |
| --- | --- | --- | --- |
| Mengelola user | ✅ | ❌ 403 | ❌ 403 |
| Melihat profil & ganti password sendiri | ✅ miliknya | ✅ miliknya | ✅ miliknya |
| Menulis master data | ✅ | ❌ 403 | ❌ 403 |
| Melihat katalog product & stock | ✅ | ✅ | ✅ |
| Membuat Purchase Order | ✅ | ❌ 403 | ✅ |
| Goods receipt | ✅ | ❌ 403 | ✅ |
| Membuat Sales Order | ✅ | ✅ (miliknya) | ❌ 403 |
| Melihat Sales Order | ✅ semua | ✅ miliknya (lainnya → 404) | ✅ semua |
| **Approve Sales Order** | ✅ | ❌ **403 selalu** | ❌ 403 |
| Goods issue | ✅ | ❌ 403 | ✅ |
| Dashboard | ✅ seluruh data | ✅ order miliknya | ✅ stock & fulfillment |
| Export CSV | ✅ semua | ✅ order miliknya | ✅ report stock |
