# Class Diagram — As-built (setelah implementasi)

**Tanggal**: 2026-09-14 · **Pasangannya**: [`../planning/class-diagram-initial.md`](../planning/class-diagram-initial.md)

Diagram ini menggambarkan kode yang **benar-benar ada**, bukan rancangan awalnya. Perbedaannya
terhadap diagram awal dicatat di bagian akhir.

## Arah dependency

```
Controller  ──▶  Service  ──▶  RepositoryInterface  ◀── Mysql*Repository
                                       ▲
                                       └── InMemory*Repository (unit test)
```

Dependency mengalir satu arah. Repository tidak pernah mengenal Service maupun Controller.

**Membedakan dependency pada interface dari dependency pada class konkret** — inilah yang
membuktikan Dependency Inversion benar-benar ada, bukan sekadar dinamai demikian:

| Notasi | Arti |
| --- | --- |
| `..>` garis putus | bergantung pada **interface** (dapat ditukar, dapat di-fake) |
| `-->` garis penuh | bergantung pada **class konkret** |

## Diagram

```mermaid
classDiagram
    direction LR

    class SalesOrderService {
        +create(array, User) int
        +submit(int, User) void
        +approve(int, User) void
        +reject(int, User) void
        +cancel(int, User) void
    }
    class StockService {
        +issueGoods(int, User) void
        +receiveGoods(int, array, User) void
        +availableFor(int, int) int
    }
    class DashboardService {
        +forRole(Role, int) array
        +adminFigures() array
        +salesFigures(int) array
        +warehouseFigures() array
    }
    class ReportService {
        +scopeFor(Role, int) ?int
        +validateRange(string, string) array
        +salesOrders(string, string, ?int) array
        +statusTotals(array) array
    }

    class SalesOrderRepositoryInterface {
        <<interface>>
    }
    class ProductStockRepositoryInterface {
        <<interface>>
        +lockForUpdate(int, int) ?ProductStock
        +adjust(int, int, int) void
    }
    class StockLedgerRepositoryInterface {
        <<interface>>
        +append(StockLedger) int
    }
    class TransactionRunner {
        <<interface>>
        +transaction(callable) mixed
    }
    class ClockInterface {
        <<interface>>
        +now() DateTimeImmutable
    }

    class MysqlSalesOrderRepository
    class MysqlProductStockRepository
    class MysqlStockLedgerRepository
    class InMemorySalesOrderRepository
    class InMemoryProductStockRepository
    class Database
    class SystemClock

    SalesOrderService ..> SalesOrderRepositoryInterface
    SalesOrderService ..> ClockInterface
    StockService ..> SalesOrderRepositoryInterface
    StockService ..> ProductStockRepositoryInterface
    StockService ..> StockLedgerRepositoryInterface
    StockService ..> TransactionRunner
    DashboardService ..> SalesOrderRepositoryInterface
    ReportService ..> StockLedgerRepositoryInterface
    ReportService ..> ClockInterface

    MysqlSalesOrderRepository ..|> SalesOrderRepositoryInterface
    MysqlProductStockRepository ..|> ProductStockRepositoryInterface
    MysqlStockLedgerRepository ..|> StockLedgerRepositoryInterface
    InMemorySalesOrderRepository ..|> SalesOrderRepositoryInterface
    InMemoryProductStockRepository ..|> ProductStockRepositoryInterface
    Database ..|> TransactionRunner
    SystemClock ..|> ClockInterface
```

### Controller bergantung pada Service konkret

```mermaid
classDiagram
    direction LR
    class DashboardController
    class ReportController
    class StockApiController
    class DashboardService
    class ReportService
    class ProductService
    class View
    class Session

    DashboardController --> DashboardService
    DashboardController --> View
    DashboardController --> Session
    ReportController --> ReportService
    StockApiController --> ProductService
```

Ini **disengaja**. Service adalah class final tanpa interface: tidak ada implementasi kedua,
dan menambahkan interface hanya demi simetri adalah lapisan tanpa masalah nyata (constitution
Principle I, spec C-003). Dependency inversion diminta ARCH-01 pada **boundary repository** —
di sanalah ada dua implementasi sungguhan, dan di sanalah interface-nya ada.

`StockApiController` sengaja **tidak** menerima `Session`: authentication dan authorization
sudah selesai di route table dan guard sebelum request sampai ke controller, sehingga bentuk
responsnya dapat di-unit-test penuh tanpa session.

## Yang berubah dari diagram awal, dan mengapa

**1. Service memerlukan lebih banyak collaborator daripada yang digambar.**
`SalesOrderService` dirancang dengan dua dependency (`orders`, `stocks`, `clock`); nyatanya
lima — `orders`, `customers`, `warehouses`, `products`, `clock`. Penyebabnya validasi: membuat
order menuntut pembuktian bahwa customer, warehouse, dan setiap product benar-benar ada dan
aktif. Rancangan awal memperlakukan itu sebagai urusan controller; ternyata itu aturan bisnis,
dan tempatnya di Service.

`StockService` justru **kehilangan** `stocks` sebagai satu-satunya sumber dan memperoleh
`TransactionRunner`. Transaction bukan detail implementasi repository — ia adalah batas
tempat ARCH-02 ditegakkan, sehingga harus terlihat pada signature Service.

**2. Dua Service baru yang tidak ada di rancangan awal.**
`DashboardService` dan `ReportService` lahir dari FR-026 dan FR-027. Keduanya memanggil
**method repository yang sama**, karena FR-027 menuntut angka dashboard dan isi file export
sepakat — cara menjaganya bukan mencocokkan dua perhitungan, melainkan menghapus kemungkinan
keduanya berbeda (research R-008).

**3. `TransactionRunner` dipisahkan dari `Database`.**
Awalnya `StockService` akan menerima `Database`. Memisahkan interface `TransactionRunner`
membuat unit test dapat menyuntikkan `ImmediateTransactionRunner`, dan — lebih penting —
membuat kebutuhan transaction terbaca dari signature-nya sendiri.
