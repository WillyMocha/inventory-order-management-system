# Catatan keputusan atas requirement yang ambigu

Brief FAQ #12: *"Bagaimana jika requirement terasa ambigu? Ajukan pertanyaan sebelum mengubah
scope. Simpan jawaban trainer sebagai catatan keputusan pada docs/planning/."*

Setiap entri di bawah adalah **tafsiran peserta yang belum dikonfirmasi trainer**. Kolom
*Status* diperbarui setelah ada jawaban trainer. Bila jawabannya berbeda, bagian *Bila tafsiran
ditolak* menyebut apa yang harus diubah. Tujuannya supaya perubahan itu kecil dan sudah
diketahui sejak awal.

| ID | Topik | Status |
| --- | --- | --- |
| D-01 | Admin tidak boleh approve Sales Order buatannya sendiri | Tafsiran peserta, belum dikonfirmasi |
| D-02 | Arti "boleh mengusulkan" Purchase Order bagi Warehouse Staff | Tafsiran peserta, belum dikonfirmasi |
| D-03 | Cakupan "status order" pada export CSV (REPORT-01) | Tafsiran peserta, belum dikonfirmasi |
| D-04 | Edit order Draft — tambahan di luar brief | Keputusan owner (2026-10-04), belum dikonfirmasi trainer |

---

## D-01 — Admin tidak boleh approve Sales Order buatannya sendiri

**Yang ambigu.** §1.2 mengizinkan Admin *membuat* Sales Order. Tetapi aturan segregation of
duties dirumuskan dalam bentuk Sales: *"Sales yang membuat order tidak boleh menyetujui order
yang sama"*. Brief tidak menyebut apakah aturan itu juga berlaku bila pembuatnya Admin.

**Tafsiran.** Aturannya berlaku untuk **siapa pun**: `approved_by <> created_by`. Tujuan yang
dinyatakan §1.2 adalah *"agar tidak ada satu peran yang bisa membuat sekaligus menyetujui
transaksi yang sama"*, dan tujuan itu tidak tercapai bila pengecualiannya justru diberikan
pada role dengan hak terluas.

**Akibat yang harus ditangani.** Dengan satu akun Admin saja, Sales Order yang dibuat Admin
tidak akan pernah dapat disetujui siapa pun. Karena itu seed menyediakan **dua akun Admin**
(`admin@ioms.test`, `admin2@ioms.test`). §7.1 meminta "satu akun Admin" sebagai **minimum**,
jadi dua akun tetap memenuhinya.

**Ditegakkan di.** `SalesOrderService::requireApprovableOrder()`. Diuji oleh
`ApprovalAuthorizationTest::anAdminStillCannotApproveAnOrderTheyCreatedThemselves` dan
`ApprovalAuthorizationTest::anOrderCreatedByAnAdminIsApprovedByAnotherAdmin`.

**Bila tafsiran ditolak** (Admin boleh approve order miliknya): hapus syarat kedua di
`requireApprovableOrder()` untuk role Admin saja, lalu sesuaikan dua test di atas. Admin
kedua di seed boleh tetap ada.

---

## D-02 — Arti "boleh mengusulkan" Purchase Order bagi Warehouse Staff

**Yang ambigu.** Tabel §1.2 menulis Warehouse Staff *"Boleh mengusulkan"* Purchase Order,
sedangkan §1.1 langkah 5 dan PO-01 menulis *"Admin atau Warehouse Staff membuat Purchase
Order ke supplier"*. "Mengusulkan" bisa dibaca sebagai "hanya membuat Draft yang harus
diteruskan Admin".

**Tafsiran.** Warehouse Staff **membuat dan mengajukan** PO sampai berstatus Ordered, sama
seperti Admin. Alasannya, PO-01 adalah requirement wajib yang paling spesifik, dan PO-01
tidak menyebut tahap persetujuan PO. Satu-satunya aturan persetujuan di brief (§1.2) adalah
untuk **Sales** Order. Kalau tahap approval PO ditambahkan tanpa diminta, alurnya menjadi
lebih rumit tanpa dasar requirement (C-003).

Batas yang tetap dijaga: **cancel PO hanya Admin** (route `POST /purchase-orders/{id}/cancel`).
Warehouse Staff tidak dapat membatalkan pembelian yang sudah berjalan.

**Ditegakkan di.** `config/routes.php` (route `submit` untuk Admin + Warehouse Staff, `cancel`
untuk Admin saja) dan `PurchaseOrderService`.

**Bila tafsiran ditolak** (Warehouse Staff hanya boleh membuat Draft): ubah role route
`POST /purchase-orders/{id}/submit` menjadi `$adminOnly`, lalu tambahkan test guard untuk
Warehouse Staff di route itu. Service tidak perlu berubah.

---

## D-03 — Cakupan "status order" pada export CSV (REPORT-01)

**Yang ambigu.** REPORT-01 meminta *"Ekspor CSV pergerakan stok (StockLedger) dan status
order dalam rentang tanggal"*. Brief memakai kata "order" untuk Purchase Order maupun Sales
Order.

**Tafsiran.** "Status order" mencakup **keduanya**. Masing-masing diekspor sebagai file
terpisah karena kolomnya memang berbeda (customer + approver vs. supplier + qty diterima):

| Export | Route | Role | Scoping |
| --- | --- | --- | --- |
| Status Sales Order | `GET /reports/orders.csv` | Admin, Sales, Warehouse Staff | Sales hanya order miliknya (§1.2) |
| Status Purchase Order | `GET /reports/purchase-orders.csv` | Admin, Warehouse Staff | Tidak ada (PO tidak dimiliki Sales) |
| Stock movement | `GET /reports/stock-movement.csv` | Admin, Warehouse Staff | Tidak ada |

Export PO memuat *Ordered Qty* dan *Received Qty* per order, supaya sisa barang dari
penerimaan sebagian tetap terlihat (PO-01). Angka di halaman report dihitung dari baris yang
sama persis dengan isi file, sehingga layar dan file tidak dapat berbeda.

**Ditegakkan di.** `ReportService::purchaseOrders()`, `ReportController::purchaseOrdersCsv()`.
Diuji oleh `ReportServiceTest` (bagian *Purchase order export*) dan
`DashboardReportConsistencyTest` (kolom terhadap MySQL nyata dan role di route table).

**Bila tafsiran ditolak** (cukup Sales Order): hapus route, action, dan kartu "Purchase order
status report" di `views/reports/index.php`. Export Sales Order tidak terpengaruh.

---

## D-04 — Edit order Draft: tambahan di luar brief atas permintaan owner

**Yang ambigu.** Brief tidak menyebut edit order sama sekali. Alur status PO (`Draft → Ordered
→ …`) dan SO (`Draft → PendingApproval → …`) tidak menjelaskan apa yang boleh dilakukan selama
Draft, dan matriks §1.2 hanya memuat *create*, *submit*, *approve/reject*, *goods receipt*, dan
*goods issue* untuk order. Tanpa edit, order Draft yang salah isi hanya dapat dibatalkan lalu
dibuat ulang — dan konfirmasi submit justru sudah menjanjikan langkah edit yang tidak ada.

**Tafsiran.** Ini **tambahan yang disengaja**, bukan tafsiran brief: owner memintanya pada
2026-10-04 (spec `specs/004-edit-draft-orders`). Aturannya:

| Order | Boleh mengedit | Ditolak |
| --- | --- | --- |
| Sales Order Draft | **pembuatnya saja**, Sales maupun Admin | Sales lain → 404; Admin lain → 403; Warehouse Staff → 403 |
| Purchase Order Draft | Admin (PO siapa pun); Warehouse Staff **hanya PO buatannya** | Warehouse Staff lain → 403; Sales → 403 |
| Order selain Draft | tidak siapa pun | ditolak, tidak ada yang berubah |

Admin sengaja tidak boleh mengedit Sales Order buatan orang lain: kalau boleh, ia dapat
mengubah isi order lalu meng-approve-nya sendiri, dan aturan D-01 (`approved_by <>
created_by`) kehilangan arti. Warehouse Staff hanya mengendalikan PO usulannya sendiri,
sejalan dengan D-02.

Edit mengganti header dan seluruh line; nomor, status, pembuat, dan approver tidak pernah
berubah, harga diambil ulang dari katalog, dan stock tidak pernah tersentuh. Status Draft
diperiksa ulang **saat menyimpan** lewat `UPDATE … WHERE status = 'Draft'`, di transaction
yang sama dengan penggantian line.

**Ditegakkan di.** `SalesOrderService::update()/canEdit()/assertMayEdit()`,
`PurchaseOrderService::update()/canEdit()/assertMayEdit()`, `updateDraft()` dan
`replaceItems()` di kedua repository, empat route di `config/routes.php`. Diuji oleh
`SalesOrderServiceEditTest`, `PurchaseOrderServiceEditTest`, dan `EditDraftOrderTest`.

**Bila tafsiran ditolak** (order tidak boleh diedit): hapus empat route
`/sales-orders/{id}/edit`, `POST /sales-orders/{id}`, `/purchase-orders/{id}/edit`,
`POST /purchase-orders/{id}`, dan link **Edit** di kedua halaman detail. Method service dan
repository boleh tetap ada tanpa jalur HTTP; tidak ada perubahan schema yang perlu dibalik.
