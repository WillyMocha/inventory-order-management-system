# Refactor log

Catatan perbaikan pada kode yang **sudah ada**, bukan pada fitur yang sedang dikerjakan
(DESIGN-03, Boy Scout Rule). Setiap entri menyebut smell-nya, teknik yang dipakai, dan kutipan
sebelum/sesudah.

---

## R-1 — `adjust()` memakai satu statement yang tidak dapat bekerja bersama CHECK constraint

**Smell**: *Hidden coupling to database semantics* — SQL yang tampak benar, tetapi
bergantung pada anggapan keliru tentang urutan evaluasi MySQL.

**Teknik**: memecah satu statement menjadi dua langkah yang niatnya eksplisit.

**Sebelum**

```php
$this->run(
    'INSERT INTO product_stock (product_id, warehouse_id, quantity, updated_at)
          VALUES (:product_id, :warehouse_id, :delta, NOW())
     ON DUPLICATE KEY UPDATE
          quantity = quantity + VALUES(quantity),
          updated_at = NOW()',
    ['product_id' => $productId, 'warehouse_id' => $warehouseId, 'delta' => $delta],
);
```

**Sesudah**

```php
$keys = ['product_id' => $productId, 'warehouse_id' => $warehouseId];

$this->run(
    'INSERT INTO product_stock (product_id, warehouse_id, quantity, updated_at)
          VALUES (:product_id, :warehouse_id, 0, NOW())
     ON DUPLICATE KEY UPDATE id = id',
    $keys,
);

$this->run(
    'UPDATE product_stock
        SET quantity = quantity + :delta, updated_at = NOW()
      WHERE product_id = :product_id AND warehouse_id = :warehouse_id',
    $keys + ['delta' => $delta],
);
```

**Mengapa**: MySQL memeriksa CHECK `quantity >= 0` terhadap **baris kandidat INSERT** lebih
dulu, sebelum jatuh ke cabang `ON DUPLICATE KEY UPDATE`. Goods issue mengirim delta negatif,
sehingga constraint menolaknya walaupun nilai akhirnya tidak pernah negatif. Akibatnya
**setiap goods issue gagal** — di test maupun di aplikasi sungguhan.

`ON DUPLICATE KEY UPDATE id = id` dipilih ketimbang `INSERT IGNORE`, karena IGNORE menurunkan
pelanggaran foreign key menjadi warning sehingga product atau warehouse tidak sah akan lolos
diam-diam.

Susunan baru ini **mengembalikan** CHECK ke peran yang dimaksudkan: jaring pengaman atas HASIL
perubahan, bukan penolak setiap delta negatif.

**Bukti**: `tests/Integration/StockAdjustmentTest.php` (8 test). Sudah diverifikasi
benar-benar gagal pada kode lama — SQL lama dikembalikan sementara menghasilkan 4 error.

---

## R-2 — Fake in-memory mengembalikan bentuk baris yang lebih miskin daripada produksinya

**Smell**: *Test double yang berbohong* — fake yang memungkinkan test lulus untuk bentuk data
yang produksinya tidak pernah hasilkan.

**Teknik**: menyetarakan kontrak test double dengan implementasi produksi.

**Sebelum**

```php
public function movementsBetween(string $startDate, string $endDate): array
{
    // Fake tidak menyimpan timestamp; unit test memeriksa isi ledger lewat
    // all(), sedangkan rentang tanggal diuji di integration test.
    return array_map(
        static fn (StockLedger $e): array => [
            'movement_type' => $e->movementType->value,
            'quantity'      => $e->quantity,
            'product_id'    => $e->productId,
            'warehouse_id'  => $e->warehouseId,
        ],
        $this->entries,
    );
}
```

**Sesudah** — sembilan kolom yang sama persis dengan query MySQL-nya, plus `recordAt()` agar
pemfilteran rentang tanggal dapat diuji secara deterministik:

```php
$result[] = [
    'created_at'        => $createdAt,
    'sku'               => $this->labelFor($this->productSkus, $entry->productId, 'SKU'),
    'product_name'      => $this->labelFor($this->productNames, $entry->productId, 'Product'),
    'warehouse_name'    => $this->labelFor($this->warehouseNames, $entry->warehouseId, 'Warehouse'),
    'movement_type'     => $entry->movementType->value,
    'quantity'          => $entry->quantity,
    'reference_type'    => $entry->referenceType->value,
    'reference_id'      => $entry->referenceId,
    'performed_by_name' => $this->labelFor($this->userNames, $entry->performedBy, 'User'),
];
```

**Mengapa**: `ReportService` bisa lulus seluruh unit test sambil membaca kolom yang tidak
pernah dikembalikan query sungguhan. Komentar "diuji di integration test" adalah janji yang —
seperti terbukti kemudian — tidak pernah ditepati, karena integration suite-nya sendiri belum
pernah dijalankan.

---

## R-3 — Placeholder bernama sama dipakai dua kali dalam satu statement

**Smell**: *Broken window* pada fixture bersama yang dipakai banyak test.

**Teknik**: memberi nama berbeda pada dua tempat yang memang berbeda.

**Sebelum**

```php
'INSERT INTO product_stock (product_id, warehouse_id, quantity)
      VALUES (:product_id, :warehouse_id, :quantity)
 ON DUPLICATE KEY UPDATE quantity = :quantity',
```

**Sesudah**

```php
'INSERT INTO product_stock (product_id, warehouse_id, quantity, updated_at)
      VALUES (:product_id, :warehouse_id, :quantity, NOW())
 ON DUPLICATE KEY UPDATE quantity = :new_quantity, updated_at = NOW()',
```

**Mengapa**: `ATTR_EMULATE_PREPARES = false` membuat PDO meneruskan statement apa adanya ke
MySQL, dan satu nama placeholder hanya boleh muncul sekali → `SQLSTATE[HY093] Invalid parameter
number`. Sekaligus `updated_at` — kolom `NOT NULL` tanpa default — yang tidak pernah diisi
fixture padahal production code mengisinya dengan `NOW()`.

Dua cacat ini saja menyumbang **54 dari 67** error pada eksekusi pertama integration suite.

---

## R-4 — Helper pembersihan fixture terkunci di dalam satu test class

**Smell**: *Misplaced responsibility* — helper yang membersihkan data milik trait justru
tinggal sebagai `private` di salah satu pemakainya.

**Teknik**: Move Method ke tempat yang benar, sekalian dilengkapi.

**Sebelum**: `ConcurrentGoodsIssueTest::cleanUpFixtures()` — `private`, dan **tidak menghapus**
`purchase_order`, `purchase_order_item`, maupun `supplier`.

**Sesudah**: `SalesOrderFixtures::cleanUpSalesOrderFixtures()` — `protected`, menghapus seluruh
dua belas tabel yang di-seed trait itu, urut menghormati foreign key.

**Mengapa**: helper itu membersihkan persis apa yang di-seed trait, jadi tempatnya memang di
trait. Setiap test yang mematikan pembungkus transaction memakai helper yang sama — bukan
menyalinnya, dan bukan pula mewarisi versi yang tidak lengkap. Saat ini hanya
`ConcurrentGoodsIssueTest` (dua connection). `GoodsReceiptTest` dulu juga memakainya, tetapi
tidak lagi memerlukannya sejak transaction bersarang memakai SAVEPOINT (`tech-debt.md` TD-1).

---

## R-5 — Format label product diduplikasi di dua pesan penolakan `StockService`

**Smell**: *Duplicate Code* — `insufficientMessage()` (goods issue) dan
`overReceiptMessage()` (goods receipt) sama-sama mencari product lalu menyusun label
`"Nama (SKU)"` dengan fallback `"product #id"`, ditulis dua kali dengan bentuk yang sedikit
berbeda.

**Teknik**: Extract Method → `productLabel(int $productId)`.

**Sebelum** (muncul di kedua method)

```php
$product = $this->products->findById($productId);
$name = $product === null ? 'product #' . $productId : $product->name . ' (' . $product->sku . ')';
```

**Sesudah**

```php
private function productLabel(int $productId): string
{
    $product = $this->products->findById($productId);

    return $product === null
        ? 'product #' . $productId
        : $product->name . ' (' . $product->sku . ')';
}
```

**Mengapa**: pesan penolakan issue dan receipt harus menyebut product dengan cara yang sama.
Dengan dua salinan, mengubah format label (misalnya menambah unit) mudah hanya mengenai satu
alur. Perilaku tidak berubah — unit test pesan penolakan di `StockServiceTest` tetap hijau
tanpa diubah.

---

## R-6 — State query string disalin di enam controller

**Smell**: *Duplicate Code*. `queryState()` ditulis sebagai method private di **enam**
controller (Customer, Supplier, User, Product, PurchaseOrder, SalesOrder), identik kecuali
daftar key-nya. `sortCriteriaFrom()` disalin di tiga controller, berbeda hanya pada allowlist
dan arah default. `critique.md` sempat menyebutnya "tiga salinan, di bawah ambang"; validasi
ulang menunjukkan ambang itu sudah lama terlewati.

**Teknik**: Move Method ke `App\Support\Request`, yang memang pemilik data query string.
Bagian yang berbeda per controller menjadi argument (Parameterize Method).

**Sebelum** (enam kali, hanya daftar key yang berubah)

```php
/** @return array<string, string> */
private function queryState(Request $request): array
{
    $state = [];

    foreach (['search', 'status', 'sort', 'direction'] as $key) {
        if ($request->queryString($key) !== '') {
            $state[$key] = $request->queryString($key);
        }
    }

    return $state;
}
```

**Sesudah**

```php
// Controller hanya menyatakan key miliknya.
private const array FILTER_KEYS = ['search', 'status', 'sort', 'direction'];

$filters = $request->queryState(self::FILTER_KEYS);
$sorted = $criteria + $request->sortCriteria(self::SORT_KEYS, 'desc');
```

**Mengapa**: mengubah cara state filter dibawa ke link pagination (FIND-01) dulu berarti
mengubah enam tempat yang mudah tidak sinkron. Kini perilakunya terkunci di satu tempat dan
teruji langsung oleh `RequestTest`: key kosong dibuang, nilai non-string diabaikan, sort di
luar allowlist ditolak, dan arah selain lawan dari default jatuh ke default. Perilaku halaman
tidak berubah: seluruh suite tetap hijau, dan link halaman 2 diperiksa end-to-end tetap
membawa filter dan sort yang sama.

---

## R-7 — Aturan panjang minimum password terkunci di `UserService`

**Smell**: *Duplicate Code* (risiko) dan aturan domain yang salah tempat. Panjang minimum
password adalah `private const` milik `UserService`. Fitur ganti password sendiri
(002-user-profile-page) menempatkan aturan yang sama di `AuthService`. Menyalin angka `8`
berarti dua jalur (Admin menyetel password user lain, dan user mengganti password sendiri) dapat
diam-diam berbeda aturan.

**Teknik**: Move Field → `User::MIN_PASSWORD_LENGTH`. Aturan tentang password akun adalah
milik entity akun, dan konstanta publik di sana dapat dibaca kedua Service.

**Sebelum** (`UserService`)

```php
/** Panjang minimum password. Cukup untuk demo, tidak melemahkan hashing. */
private const int MIN_PASSWORD_LENGTH = 8;

->minLength('password', 'Password', self::MIN_PASSWORD_LENGTH)
```

**Sesudah**

```php
// app/Entity/User.php
public const int MIN_PASSWORD_LENGTH = 8;

// UserService dan AuthService
->minLength('password', 'Password', User::MIN_PASSWORD_LENGTH)
->minLength('new_password', 'New password', User::MIN_PASSWORD_LENGTH)
```

**Mengapa**: mengubah kebijakan password kini cukup di satu tempat, dan kedua jalur pasti
ikut berubah. Petunjuk "At least 8 characters." di halaman profil juga membaca konstanta yang
sama. Perilaku tidak berubah: `UserServiceTest` tetap hijau **tanpa diubah**, dan unit test
`changeOwnPassword` memeriksa pesannya menyebut panjang dari konstanta itu.

---

## R-8 — Langkah "pastikan baris ada" terkubur di dalam `adjust()`

**Smell**: langkah yang dibutuhkan pemanggil kedua tersembunyi di dalam satu method. Koreksi
stock (spec 003) perlu memastikan baris `product_stock` ada **sebelum** menguncinya, tetapi
`INSERT ... ON DUPLICATE KEY UPDATE` yang melakukannya hanya ada sebagai bagian pertama
`MysqlProductStockRepository::adjust()`. Menyalinnya ke tempat lain berarti dua salinan SQL yang
sama, termasuk alasan rumit mengapa nilai kandidatnya harus 0.

**Teknik**: Extract Method → `ensureRow(int $productId, int $warehouseId)`, dinaikkan ke
`ProductStockRepositoryInterface` (dengan implementasi fake-nya).

**Sebelum**

```php
public function adjust(int $productId, int $warehouseId, int $delta): void
{
    $keys = ['product_id' => $productId, 'warehouse_id' => $warehouseId];

    $this->run('INSERT INTO product_stock (...) VALUES (:product_id, :warehouse_id, 0, NOW())
                ON DUPLICATE KEY UPDATE id = id', $keys);
    $this->run('UPDATE product_stock SET quantity = quantity + :delta ...', $keys + ['delta' => $delta]);
}
```

**Sesudah**

```php
public function adjust(int $productId, int $warehouseId, int $delta): void
{
    $this->ensureRow($productId, $warehouseId);
    $this->run('UPDATE product_stock SET quantity = quantity + :delta ...', [...]);
}

public function ensureRow(int $productId, int $warehouseId): void
{
    $this->run('INSERT INTO product_stock (...) VALUES (:product_id, :warehouse_id, 0, NOW())
                ON DUPLICATE KEY UPDATE id = id', [...]);
}
```

**Mengapa**: satu tempat untuk SQL yang sensitif terhadap CHECK `quantity >= 0` (ADR-002,
catatan bug lama). Perilaku `adjust()` tidak berubah: `StockAdjustmentTest`, seluruh test goods
issue/receipt, dan `LedgerReconciliationTest` tetap hijau tanpa diubah; `ensureRow()` sendiri
dieksekusi MySQL di `RepositoryCoverageTest`.

---

## R-9 — Aksi pergerakan stock menumpang di controller order

**Smell**: Large Class dan tanggung jawab bercampur. `SalesOrderController` (27 method, 557
baris) menampung goods issue di samping pengelolaan order, dan `PurchaseOrderController`
(26 method, 544 baris) menampung goods receipt. Goods issue dan receipt adalah **pergerakan
stock**, bukan pengelolaan order — project sudah punya preseden controller tersendiri untuk itu
(`StockAdjustmentController`, spec 003). Sonar S1448 (> 20 method) menandai keduanya (TD-11).

**Teknik**: Extract Class → `GoodsIssueController` (`issueForm`, `issue`) dan
`GoodsReceiptController` (`receiveForm`, `receive`, `receivedQuantitiesFrom`). Hanya nama
controller di `config/routes.php` yang berubah; URL dan role tidak.

**Sebelum**

```php
$router->add('GET', '/sales-orders/{id}/issue', 'SalesOrderController', 'issueForm', $adminWarehouse);
$router->add('GET', '/purchase-orders/{id}/receive', 'PurchaseOrderController', 'receiveForm', $adminWarehouse);
```

**Sesudah**

```php
$router->add('GET', '/sales-orders/{id}/issue', 'GoodsIssueController', 'issueForm', $adminWarehouse);
$router->add('GET', '/purchase-orders/{id}/receive', 'GoodsReceiptController', 'receiveForm', $adminWarehouse);
```

**Mengapa aman**: method dipindah apa adanya — pesan, status HTTP, dan redirect sama.
Diverifikasi lewat HTTP: form issue/receive 200, order yang salah status ditolak dengan pesan
yang sama, Sales tetap 403; `GoodsIssueTest`, `GoodsReceiptTest`, dan `ConcurrentGoodsIssueTest`
hijau tanpa diubah.

---

## R-10 — Segregation of duties tercampur dengan alur draft

**Smell**: Large Class. `SalesOrderService` (24 method) memuat create, edit, submit, cancel,
scoping kepemilikan, **dan** approve/reject — padahal approve/reject adalah aturan yang CLAUDE.md
tandai sebagai area paling kritikal. Aturan yang harus diaudit tersebar di class yang besar.

**Teknik**: Extract Class → `SalesOrderApprovalService` (`approve`, `reject`,
`requireApprovableOrder`) dan `SalesOrderApprovalController`. Visibilitas order tetap memakai
`SalesOrderService::requireVisibleOrder()` (bukan disalin), sehingga Sales pada order orang lain
tetap mendapat 404. Dua static factory pesan error di `SalesOrderService` diganti konstanta.

**Sebelum**

```php
$this->salesOrders->approve($id, $user);   // SalesOrderService, 24 method
```

**Sesudah**

```php
$this->approvals->approve($id, $user);     // SalesOrderApprovalService, 4 method
```

**Mengapa aman**: kedua syarat (Admin, approver ≠ creator) dan urutan pemeriksaannya disalin
utuh; seluruh test approval (`SalesOrderServiceTest`, `ApprovalAuthorizationTest`) hanya berganti
objek yang dipanggil dan tetap hijau. HTTP: approve/reject pada order Fulfilled ditolak dengan
pesan yang sama persis.

---

## R-11 — Pembulatan total line diduplikasi di dua controller

**Smell**: Duplicated Code. `rupiah()` dan `lineTotal()` identik di `SalesOrderController` dan
`PurchaseOrderController`, lengkap dengan komentar yang sama tentang bcmath.

**Teknik**: Move Method → `Money::lineTotal(int $quantity, string $unitPrice)`, di samping
`Money::format()` yang sudah memakai aturan pembulatan yang sama. Diuji `MoneyTest`.

**Sebelum**

```php
'lineTotal' => $this->lineTotal($item->quantity, $item->sellingPrice),   // salinan di 2 controller
```

**Sesudah**

```php
'lineTotal' => Money::lineTotal($item->quantity, $item->sellingPrice),
```

---

## Catatan audit SRP

**`StockService` — satu class, dua alur, dan itu benar.**

Rancangan awal sempat mempertimbangkan `GoodsIssueService` dan `GoodsReceiptService` terpisah.
Keduanya digabung, dan alasannya bukan kemalasan: CLAUDE.md menetapkan stock hanya boleh
berubah lewat satu jalur yang sekaligus menulis `stock_ledger` dalam transaction yang sama
(ARCH-02). Dua class berarti dua tempat yang dapat mengubah stock, dan invariant NFR-002
menjadi kesepakatan antar-class alih-alih dijaga satu class.

Tanggung jawabnya karena itu tunggal: **"satu-satunya jalan stock boleh berubah"**. Receipt
dan issue adalah dua arah dari tanggung jawab yang sama, keduanya memakai pola dua fase yang
identik (verifikasi seluruhnya, baru menulis).

**Yang justru DIPECAH**: `MasterDataService` sempat hendak menampung Category, Warehouse,
Supplier, dan Customer sekaligus. Supplier dan Customer dipindahkan ke `PartyService` karena
keduanya entity terpisah dengan repository terpisah (data-model.md) — relasinya berbeda
(Supplier ke Purchase Order, Customer ke Sales Order) dan tidak ada endpoint yang
memperlakukan keduanya sebagai satu collection. Yang dibagi hanyalah aturan validasinya,
karena bentuk field-nya memang sama; datanya tidak pernah bercampur.
