# Class Diagram — Initial (sebelum implementasi)

**Feature**: Inventory & Order Management System
**Tanggal**: 2026-09-10
**Status**: Dibuat **sebelum** penulisan application code, sesuai DESIGN-01.

Diagram ini adalah rancangan awal. Diagram *as-built* dibuat terpisah di
`docs/architecture/class-diagram-as-built.md` setelah implementasi selesai, beserta
penjelasan apa yang berubah dan alasannya.

## Arah dependency

```
Controller  ──▶  Service  ──▶  RepositoryInterface  ◀── Mysql*Repository
                                       ▲
                                       └── InMemory*Repository (dipakai unit test)
```

Dependency hanya mengalir ke satu arah. Repository tidak pernah mengenal Service maupun
Controller. Service bergantung pada **interface**, bukan pada implementasi konkret — inilah
Dependency Inversion pada boundary repository (ARCH-01).

## Diagram

```mermaid
classDiagram
    direction LR

    class SalesOrderController {
        -SalesOrderService service
        +index(Request) Response
        +show(Request) Response
        +store(Request) Response
        +submit(Request) Response
        +approve(Request) Response
        +issue(Request) Response
    }

    class PurchaseOrderController {
        -PurchaseOrderService service
        -StockService stockService
        +index(Request) Response
        +store(Request) Response
        +receive(Request) Response
    }

    class SalesOrderService {
        -SalesOrderRepositoryInterface orders
        -ProductStockRepositoryInterface stocks
        -ClockInterface clock
        +create(User actor, array data) SalesOrder
        +submit(User actor, int id) void
        +approve(User actor, int id) void
        +reject(User actor, int id) void
        +cancel(User actor, int id) void
    }

    class StockService {
        -ProductStockRepositoryInterface stocks
        -StockLedgerRepositoryInterface ledger
        -Database db
        +receiveGoods(User actor, int poId, array qty) void
        +issueGoods(User actor, int soId) void
    }

    class SalesOrderRepositoryInterface {
        <<interface>>
        +findById(int) SalesOrder
        +save(SalesOrder) int
        +search(criteria, Paginator) array
    }

    class ProductStockRepositoryInterface {
        <<interface>>
        +findFor(int productId, int warehouseId) ProductStock
        +lockForUpdate(int productId, int warehouseId) ProductStock
        +adjust(int productId, int warehouseId, int delta) void
    }

    class MysqlSalesOrderRepository {
        -PDO pdo
    }
    class InMemorySalesOrderRepository {
        -array rows
    }
    class MysqlProductStockRepository {
        -PDO pdo
        +lockForUpdate() ProductStock
    }
    class InMemoryProductStockRepository {
        -array rows
    }

    class SalesOrder {
        +int id
        +string orderNumber
        +int createdBy
        +int approvedBy
        +SalesOrderStatus status
        +canTransitionTo(SalesOrderStatus) bool
    }
    class SalesOrderItem {
        +int productId
        +int quantity
        +string sellingPrice
    }
    class ProductStock {
        +int productId
        +int warehouseId
        +int quantity
    }
    class StockLedger {
        +MovementType movementType
        +int quantity
        +int referenceId
    }

    SalesOrderController --> SalesOrderService
    SalesOrderController --> StockService
    PurchaseOrderController --> PurchaseOrderService
    PurchaseOrderController --> StockService

    SalesOrderService ..> SalesOrderRepositoryInterface
    SalesOrderService ..> ProductStockRepositoryInterface
    StockService ..> ProductStockRepositoryInterface
    StockService ..> StockLedgerRepositoryInterface

    SalesOrderRepositoryInterface <|.. MysqlSalesOrderRepository
    SalesOrderRepositoryInterface <|.. InMemorySalesOrderRepository
    ProductStockRepositoryInterface <|.. MysqlProductStockRepository
    ProductStockRepositoryInterface <|.. InMemoryProductStockRepository

    SalesOrder "1" *-- "1..*" SalesOrderItem
    SalesOrderService ..> SalesOrder
    StockService ..> StockLedger
    StockService ..> ProductStock
```

Garis putus-putus (`..>`) menandakan dependency ke **interface**. Garis segitiga kosong
(`<|..`) menandakan implementasi. Inilah yang membuat setiap use case dapat di-unit-test
tanpa database.

## Rencana yang paling mungkin berubah

Tiga hal berikut sengaja dicatat sekarang agar perbandingan dengan diagram as-built jujur:

1. `StockService` mungkin perlu dipecah bila method `receiveGoods` dan `issueGoods` tumbuh
   melewati batas kompleksitas — kandidat pemisahan menjadi `GoodsReceiptService` dan
   `GoodsIssueService`.
2. Signature `ProductStockRepositoryInterface::adjust()` mungkin berubah setelah mekanisme
   `SELECT ... FOR UPDATE` diimplementasikan, karena lock dan update perlu berada di dalam
   satu transaction yang sama.
3. Pembuatan `orderNumber` masih ditempatkan di Service; bila ternyata butuh penanganan
   collision, kemungkinan pindah ke repository atau ke sebuah generator tersendiri.
