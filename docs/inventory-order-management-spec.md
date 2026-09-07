# Inventory & Order Management System — Project Specification

> Berdasarkan **Intermediate Programmer - Final Project Brief: Inventory & Order Management System**.

## 1. Project Overview

### 1.1 Project Name

**Inventory & Order Management System**

### 1.2 Objective

Membangun aplikasi web untuk:

- mengelola produk dan master data;
- mengelola stok produk pada banyak gudang;
- membuat dan menerima Purchase Order;
- membuat, menyetujui, dan memenuhi Sales Order;
- mencatat seluruh perubahan stok melalui Stock Ledger;
- menyediakan dashboard berdasarkan role;
- menyediakan laporan CSV;
- menjaga integritas stok saat terjadi operasi concurrent.

Sistem harus memperlihatkan penerapan nyata:

- OOP;
- layered architecture;
- Dependency Inversion;
- transaction management;
- authorization;
- segregation of duties;
- Clean Code;
- testing;
- refactoring;
- dokumentasi keputusan arsitektur.

---

## 2. Technical Constraints

### 2.1 Mandatory Technology Stack

#### Frontend

```text
HTML5
CSS custom
Vanilla JavaScript
Fetch API
```

Dilarang:

```text
React
Vue
Angular
jQuery
Bootstrap
Tailwind
CSS Framework
Admin template
```

#### Backend

```text
PHP 8.4.x only
Native PHP
OOP
Composer autoload
PDO
```

Arsitektur minimum:

```text
Controller
    ↓
Service
    ↓
Repository Interface
    ↓
Repository Implementation
    ↓
PDO / MySQL
```

Dilarang:

```text
PHP 8.5 atau lebih baru
PHP 8.3 atau lebih lama
Laravel
Symfony
CodeIgniter
Slim
ORM
Framework DI Container
CRUD Generator
```

> **PHP version lock:** project wajib dijalankan menggunakan **PHP 8.4.x**. Jangan menggunakan PHP 8.5 maupun versi di bawah 8.4. Docker image, `composer.json`, dokumentasi setup, CI/test command (jika ada), dan environment development harus konsisten pada PHP 8.4.x.

#### Database

```text
MySQL 8+
PDO Prepared Statements
Foreign Key
Constraints
Indexes
Transactions
```

#### Runtime

```text
Docker
Docker Compose
```

Minimal terdapat:

```text
app/web container
mysql container
```

#### Testing

```text
PHPUnit
PHPStan >= level 5
atau
PHP_CodeSniffer PSR-12
```

Minimum:

```text
6 unit tests
3 integration tests
```

Unit test harus mencakup minimal **3 area business logic berbeda**.

---

## 3. System Actors

Sistem memiliki tiga role.

```text
Admin
Sales
WarehouseStaff
```

---

## 4. Authorization Matrix

| Feature | Admin | Sales | Warehouse Staff |
|---|:---:|:---:|:---:|
| Login/logout | ✓ | ✓ | ✓ |
| Update profile | ✓ | ✓ | ✓ |
| Manage users | ✓ | ✗ | ✗ |
| Manage products | ✓ | View | View |
| Manage categories | ✓ | View | View |
| Manage warehouses | ✓ | ✗ | View |
| Manage suppliers | ✓ | ✗ | View where needed |
| Manage customers | ✓ | View where needed | ✗ |
| Create Sales Order | ✓ | ✓ | ✗ |
| Submit Sales Order | ✓ | ✓ | ✗ |
| Approve Sales Order | ✓ | ✗ | ✗ |
| Reject Sales Order | ✓ | ✗ | ✗ |
| Create Purchase Order | ✓ | ✗ | ✓ |
| Goods Receipt | ✓ | ✗ | ✓ |
| Goods Issue | ✓ | ✗ | ✓ |
| Dashboard | All | Own orders | Stock/fulfillment |
| CSV Reports | All | Own orders | Stock |

### Critical Authorization Rule

Authorization **harus diperiksa di backend**.

Sales tidak boleh melakukan:

```text
approve Sales Order
```

termasuk Sales Order yang dibuat oleh dirinya sendiri.

UI boleh menyembunyikan tombol, tetapi penyembunyian UI **tidak dianggap sebagai authorization**.

---

## 5. Core Business Flow

```text
Login
 ↓
Master Data
 ↓
Purchase Order
 ↓
Goods Receipt
 ↓
Stock
 ↓
Sales Order
 ↓
Approval
 ↓
Goods Issue
 ↓
Stock Ledger
 ↓
Dashboard / Report
 ↓
Logout
```

---

## 6. Authentication Specification

### AUTH-01 — Login

#### User Story

```text
As a registered user
I want to login
So that I can access features according to my role.
```

#### Input

```text
email
password
```

#### Rules

- Email harus terdaftar.
- Password diverifikasi menggunakan `password_verify()`.
- Password disimpan menggunakan `password_hash()`.
- User dengan `is_active = false` tidak boleh login.
- Pesan login gagal tidak boleh mengungkap apakah email atau password yang salah.
- Session ID harus diregenerasi sesudah login.

#### Expected Behavior

```text
Admin
→ Admin Dashboard

Sales
→ Sales Dashboard

WarehouseStaff
→ Warehouse Dashboard
```

---

## 7. Logout Specification

### AUTH-02 — Logout

Logout harus:

```text
destroy authentication session
invalidate current login state
redirect to login
```

Setelah logout, protected URL tidak dapat dibuka tanpa autentikasi ulang.

---

## 8. User Management

### USR-01 — User Management

Hanya Admin dapat mengelola user.

#### User Fields

```text
id
name
email
password_hash
role
is_active
created_at
updated_at
```

#### Role Enum

```text
Admin
Sales
WarehouseStaff
```

#### Operations

```text
Create
View
Update
Activate
Deactivate
```

Tidak menggunakan hard delete.

#### Constraints

```text
email UNIQUE
```

Tidak tersedia public registration.

---

## 9. Master Data

### 9.1 Category

Fields:

```text
id
name
description
created_at
updated_at
```

---

## 10. Product

### PRD-01

Fields minimum:

```text
id
sku
name
category_id
unit
purchase_price
selling_price
reorder_point
image_path
is_active
created_at
updated_at
```

Constraints:

```text
sku UNIQUE
purchase_price >= 0
selling_price >= 0
reorder_point >= 0
```

Produk yang sudah digunakan pada transaksi tidak boleh dihapus permanen. Gunakan `is_active = false`.

---

## 11. Product Image

Upload gambar bersifat optional.

Validasi wajib:

```text
allowed MIME/type
maximum file size
generated/random filename
```

Nama file asli tidak boleh digunakan sebagai nama penyimpanan yang predictable.

Contoh:

```text
uploads/products/
  f82bc98f3392.webp
```

---

## 12. Warehouse

### WH-01

Fields:

```text
id
name
location
is_active
created_at
updated_at
```

Minimal seed:

```text
2 warehouses
```

---

## 13. Product Stock

Stok disimpan **per product + warehouse**.

Fields:

```text
id
product_id
warehouse_id
quantity
updated_at
```

Constraint:

```text
quantity >= 0
```

Unique constraint direkomendasikan:

```text
UNIQUE(product_id, warehouse_id)
```

Untuk satu produk:

```text
Warehouse A → 100
Warehouse B → 30

Total → 130
```

UI harus dapat menampilkan total stock dan stock per warehouse.

---

## 14. Supplier

Fields:

```text
id
name
contact
address
is_active
created_at
updated_at
```

Supplier dinonaktifkan, bukan dihapus apabila sudah digunakan transaksi.

---

## 15. Customer

Fields:

```text
id
name
contact
address
is_active
created_at
updated_at
```

Customer dinonaktifkan apabila sudah digunakan transaksi.

---

## 16. Purchase Order

### PO-01

#### Header

```text
id
po_number
supplier_id
warehouse_id
status
order_date
created_by
created_at
updated_at
```

#### PO Items

```text
id
purchase_order_id
product_id
quantity
received_quantity
purchase_price
```

#### PO Status

```text
Draft
Ordered
PartiallyReceived
Received
Cancelled
```

State flow:

```text
Draft
 ↓
Ordered
 ↓
PartiallyReceived
 ↓
Received
```

Alternative:

```text
Draft / Ordered / PartiallyReceived
            ↓
        Cancelled
```

---

## 17. Goods Receipt

Goods Receipt hanya memproses PO yang sesuai status.

Contoh:

```text
PO quantity = 100

First Receipt
30

PO:
received = 30
remaining = 70
status = PartiallyReceived

Second Receipt
70

received = 100
remaining = 0
status = Received
```

### Atomic Transaction Requirement

Goods Receipt wajib menjalankan:

```text
BEGIN TRANSACTION

1. Validate PO
2. Validate received quantity
3. Lock/read relevant stock
4. Increase ProductStock
5. Insert StockLedger Receipt
6. Update received quantity
7. Update PO status

COMMIT
```

Apabila ada error: `ROLLBACK`.

ProductStock dan StockLedger tidak boleh berbeda.

---

## 18. Sales Order

### SO-01

Header:

```text
id
so_number
customer_id
warehouse_id
created_by
approved_by
status
order_date
created_at
updated_at
```

Items:

```text
id
sales_order_id
product_id
quantity
selling_price
```

Statuses:

```text
Draft
PendingApproval
Approved
Fulfilled
Cancelled
```

State flow:

```text
Draft
 ↓
PendingApproval
 ↓
Approved
 ↓
Fulfilled
```

Cancellation:

```text
Draft → Cancelled
PendingApproval → Cancelled
Approved → Cancelled
```

Tidak boleh Cancel setelah Fulfilled.

---

## 19. Sales Order Approval

Sales membuat order berstatus `Draft`, lalu melakukan submit sehingga berubah menjadi `PendingApproval`.

Admin dapat melakukan approve atau reject/cancel.

Approve:

```text
PendingApproval
→ Approved
```

Server wajib memastikan role adalah Admin.

Sales request seperti:

```http
POST /sales-orders/123/approve
```

harus menghasilkan:

```http
403 Forbidden
```

meskipun request dikirim secara manual.

---

## 20. Goods Issue

Goods Issue hanya boleh dijalankan untuk:

```text
SO.status == Approved
```

Validasi:

```text
availableStock >= requestedQuantity
```

Jika tidak terpenuhi, transaksi harus ditolak dan stok tidak boleh menjadi negatif.

---

## 21. Concurrency Requirement

### ARCH-02

Kasus utama:

```text
Stock = 5

SO A requests 5
SO B requests 5
```

Apabila diproses hampir bersamaan:

```text
SO A → success
SO B → rejected/waits then rejected
```

Final:

```text
Stock = 0
```

Tidak boleh terjadi stock negatif atau kedua transaksi berhasil.

---

## 22. Recommended Concurrency Strategy

Salah satu desain yang paling sesuai dengan MySQL adalah menggunakan transaksi dan row-level locking:

```sql
START TRANSACTION;

SELECT quantity
FROM product_stocks
WHERE product_id = ?
AND warehouse_id = ?
FOR UPDATE;
```

Kemudian:

```text
if stock >= qty:
    update stock
    insert ledger
else:
    reject
```

Lalu:

```sql
COMMIT;
```

Mekanisme ini adalah rekomendasi desain, bukan satu-satunya pendekatan yang diperbolehkan.

---

## 23. Stock Ledger

Fields:

```text
id
product_id
warehouse_id
movement_type
quantity
reference_type
reference_id
performed_by
created_at
```

Movement:

```text
Receipt
Issue
Adjustment
```

Contoh:

```text
Product: SKU-001
Warehouse: WH-01

Receipt +100 PO-001
Issue   -20 SO-001
Issue   -10 SO-002
------------------
Stock    70
```

### Core Invariant

Tidak boleh ada perubahan `ProductStock.quantity` langsung dari UI/controller.

Seluruh perubahan stok harus melalui Service yang menulis `StockLedger` dan memperbarui `ProductStock` dalam satu transaksi.

---

## 24. Stock Invariants

Sistem harus mempertahankan:

```text
ProductStock.quantity >= 0
```

Secara konseptual:

```text
Current Stock
=
Initial Stock
+ Receipt
+ Adjustment In
- Issue
- Adjustment Out
```

Setiap perubahan harus dapat ditelusuri ke StockLedger.

---

## 25. Product Search

### FIND-01

Search:

```text
name
SKU
```

Filter:

```text
category
stock status
```

Stock status:

```text
Low Stock
Normal
```

Low stock dapat didefinisikan sebagai `quantity < reorder_point`, sepanjang dipakai konsisten dan didokumentasikan.

---

## 26. Order Search

PO dan SO mendukung:

```text
search
filter
sorting
pagination
```

Search:

```text
order number
supplier/customer
```

Filter:

```text
status
```

Sort:

```text
date ASC
date DESC
```

---

## 27. Pagination

Default:

```text
10 records / page
```

Filter harus tetap aktif ketika user berpindah halaman.

Contoh:

```text
/products?q=laptop&category=2&stock=low&page=2
```

---

## 28. Empty State

Jika tidak ada data, jangan hanya menampilkan tabel kosong.

Contoh:

```text
No sales orders found.

Try changing your filters or create a new Sales Order.
```

---

## 29. Dashboard

### Admin Dashboard

Menampilkan:

```text
inventory value
products below reorder point
PO count per status
SO count per status
pending approval orders
```

Semua berasal dari query database/agregasi.

---

## 30. Sales Dashboard

Menampilkan ringkasan order milik user login:

```text
Draft
PendingApproval
Approved
Fulfilled
Cancelled
```

Dengan filter `created_by = current_user_id`.

---

## 31. Warehouse Dashboard

Menampilkan:

```text
goods receipt queue
goods issue queue
low-stock products
stock summary
```

---

## 32. CSV Reports

### REPORT-01

Minimal laporan:

#### Stock Movement

```text
date
product
warehouse
movement
quantity
reference
performed_by
```

#### Order Status

```text
order_number
order_type
party
status
date
```

Filter:

```text
date_from
date_to
```

Role filtering tetap berlaku.

---

## 33. JSON API

### API-01

Minimal:

```http
GET /api/products/{sku}/availability
```

Example:

```http
GET /api/products/SKU-001/availability
```

Response:

```json
{
  "sku": "SKU-001",
  "name": "Mechanical Keyboard",
  "total_stock": 35,
  "warehouses": [
    {
      "warehouse_id": 1,
      "warehouse": "Main Warehouse",
      "quantity": 25
    },
    {
      "warehouse_id": 2,
      "warehouse": "Secondary Warehouse",
      "quantity": 10
    }
  ]
}
```

Response header:

```http
Content-Type: application/json
```

Status:

```text
200 OK
401 Unauthorized
404 Not Found
```

API harus menggunakan authentication/authorization yang sama dengan aplikasi HTML.

---

## 34. Input Validation

Validation dilakukan di frontend dan backend. Backend adalah source of truth.

Validate minimal:

```text
required fields
enum
foreign keys
dates
quantity >= 0
price >= 0
reorder_point >= 0
SKU unique
email unique
```

Invalid request tidak boleh menyimpan data.

---

## 35. Error Handling

### 401 / Authentication

Unauthenticated web user diarahkan ke `/login`.

JSON API:

```json
{
  "error": "Unauthenticated"
}
```

HTTP status: `401`.

### 403

Contoh: Sales mencoba approval.

Response: `403 Forbidden`.

### 404

Entity/URL tidak ditemukan: `404`.

### 500

User tidak boleh melihat:

```text
PDOException
stack trace
database credentials
SQL query
filesystem path
```

---

## 36. Application Architecture

Direkomendasikan struktur:

```text
inventory-order-system/
│
├── app/
│   ├── Controller/
│   ├── Service/
│   ├── Repository/
│   │   ├── Contract/
│   │   ├── MySQL/
│   │   └── InMemory/
│   ├── Entity/
│   ├── Exception/
│   └── Validation/
│
├── public/
│   ├── index.php
│   ├── css/
│   ├── js/
│   └── uploads/
│
├── views/
│
├── config/
│
├── database/
│   ├── schema.sql
│   └── seed.sql
│
├── scripts/
│   └── check-low-stock.php
│
├── tests/
│   ├── Unit/
│   └── Integration/
│
├── docs/
│   ├── planning/
│   ├── architecture/
│   ├── quality/
│   └── testing/
│
├── Dockerfile
├── compose.yaml
├── composer.json
├── phpunit.xml
├── phpstan.neon
├── .env.example
├── .gitignore
├── ai-usage-log.md
└── README.md
```

---

## 37. Repository Boundary

Contoh:

```php
interface ProductStockRepositoryInterface
{
    public function findForUpdate(
        int $productId,
        int $warehouseId
    ): ?ProductStock;

    public function updateQuantity(
        int $productId,
        int $warehouseId,
        int $quantity
    ): void;
}
```

Implementasi production:

```text
MySqlProductStockRepository
```

Implementasi test:

```text
InMemoryProductStockRepository
```

Service menerima dependency melalui constructor injection.

```php
final class GoodsIssueService
{
    public function __construct(
        private ProductStockRepositoryInterface $stocks,
        private StockLedgerRepositoryInterface $ledger
    ) {}
}
```

Service tidak boleh membuat koneksi PDO sendiri.

---

## 38. Controller Responsibility

Controller hanya menangani:

```text
HTTP Request
Authentication context
Input extraction
Call Service
HTTP Response / View
```

Hindari SQL, business rules, stock calculation, dan transaction logic di Controller.

---

## 39. Service Responsibility

Service menangani:

```text
business rules
status transition
authorization business rules
transaction orchestration
stock rules
```

Contoh:

```text
AuthenticationService
UserService
ProductService
PurchaseOrderService
GoodsReceiptService
SalesOrderService
SalesOrderApprovalService
GoodsIssueService
StockService
DashboardService
ReportService
```

---

## 40. Repository Responsibility

Repository menangani:

```text
database access
prepared statement
mapping database → entity
locking query
persistence
```

Repository tidak seharusnya menentukan apakah Sales boleh melakukan approve terhadap Sales Order.

---

## 41. Proposed Repository Interfaces

```text
UserRepositoryInterface
ProductRepositoryInterface
CategoryRepositoryInterface
WarehouseRepositoryInterface
ProductStockRepositoryInterface
SupplierRepositoryInterface
CustomerRepositoryInterface
PurchaseOrderRepositoryInterface
SalesOrderRepositoryInterface
StockLedgerRepositoryInterface
```

Tidak perlu memaksakan interface pada setiap class jika tidak memberikan boundary/testability yang nyata.

---

## 42. Scheduled Script

### JOB-01

File:

```text
scripts/check-low-stock.php
```

Run:

```bash
docker compose exec app php scripts/check-low-stock.php
```

Output contoh:

```text
LOW STOCK REPORT
================

SKU-001 | Keyboard | Warehouse A | Stock: 3 | Reorder: 5
SKU-008 | Mouse    | Warehouse B | Stock: 4 | Reorder: 10

Total low stock products: 2
```

Tidak wajib menggunakan cron sungguhan.

---

## 43. Unit Testing Specification

Minimum:

```text
6 tests
>= 3 business logic areas
```

Direkomendasikan:

### Sales Order State

```text
testDraftOrderCanBeSubmitted()
testPendingOrderCanBeApprovedByAdmin()
testSalesCannotApproveOrder()
testFulfilledOrderCannotBeCancelled()
```

### Stock

```text
testIssueRejectedWhenStockInsufficient()
testIssueReducesAvailableStock()
testReceiptIncreasesStock()
```

### Low Stock

```text
testProductBelowReorderPointIsLowStock()
testProductAboveReorderPointIsNormal()
```

Unit test:

```text
NO MySQL
NO PDO
NO Session
NO HTTP call
```

Gunakan fake repository.

---

## 44. Integration Testing Specification

Minimum 3.

### IT-01 Goods Receipt

```text
Given stock = 10
When goods receipt 5
Then ProductStock = 15
And StockLedger contains Receipt +5
```

### IT-02 Goods Issue

```text
Given stock = 10
When goods issue 4
Then ProductStock = 6
And ledger contains Issue 4
```

### IT-03 Oversell Protection

```text
Given stock = 5

First goods issue = 5
Second goods issue = 5

First succeeds
Second fails

Final stock = 0
Only one successful Issue ledger entry
```

Integration test menggunakan MySQL nyata dari Docker.

---

## 45. Static Analysis

Minimum:

```text
PHPStan level 5+
```

Target:

```text
0 critical errors
```

Contoh perintah:

```bash
vendor/bin/phpstan analyse app --level=5
```

Result disimpan pada `docs/quality/`.

---

## 46. FIRST Testing Principle

Test harus:

```text
Fast
Independent
Repeatable
Self-validating
Timely
```

Tidak boleh menggunakan workaround seperti `sleep()` atau bergantung pada urutan test maupun network service eksternal.

---

## 47. Responsive UI

Minimum viewport:

```text
360px
desktop
```

Wajib usable:

```text
Login
Dashboard
Product list
Order list
Order detail
Forms
```

Tabel tidak boleh menjadi unusable atau terpotong tanpa solusi.

Form harus memiliki label, focus state, basic contrast, dan validation feedback.

---

## 48. Seed Data

Minimum:

```text
1 Admin
2 Sales
2 WarehouseStaff

2 Warehouses

30 Products

>=25 combined PO + SO
```

Order harus memiliki variasi status termasuk `PendingApproval` dan `Cancelled`.

Beberapa produk harus berada di bawah reorder point.

---

## 49. Database Tables

Minimum logical schema:

```text
users
categories
products
warehouses
product_stocks
suppliers
customers
purchase_orders
purchase_order_items
sales_orders
sales_order_items
stock_ledgers
```

Opsional bila desain membutuhkan:

```text
goods_receipts
goods_receipt_items
goods_issues
goods_issue_items
```

---

## 50. Index Strategy

Minimum index relevan misalnya:

```sql
users(email)
products(sku)
products(category_id)
product_stocks(product_id, warehouse_id)
purchase_orders(status)
purchase_orders(order_date)
sales_orders(status)
sales_orders(order_date)
sales_orders(created_by)
stock_ledgers(product_id, warehouse_id)
stock_ledgers(created_at)
```

Jangan menambah index secara sembarang; minimal satu keputusan index harus dapat dijelaskan saat defense.

---

## 51. Security Requirements

Wajib:

```text
password_hash()
password_verify()
PDO prepared statements
HTML output escaping
session regeneration
server-side authorization
.env excluded from Git
no active credential in repository
```

Contoh output escaping:

```php
htmlspecialchars(
    $value,
    ENT_QUOTES,
    'UTF-8'
);
```

---

## 52. Documentation Requirements

### docs/planning/

```text
scope.md
user-stories.md
backlog.md
erd.*
class-diagram-initial.*
```

### docs/architecture/

```text
class-diagram-as-built.*
adr-001-repository-boundary.md
adr-002-stock-concurrency.md
adr-003-...
```

---

## 53. ADR Structure

Contoh:

```markdown
# ADR-002: Stock Row Locking

## Context

Concurrent goods issue can read the same available stock and both
successfully deduct it, causing overselling.

## Decision

Use a database transaction combined with SELECT ... FOR UPDATE
when obtaining ProductStock.

## Consequences

Positive:
- Prevents concurrent stock modification.
- Keeps ProductStock and StockLedger consistent.

Negative:
- Locks stock rows temporarily.
- Transaction scope must remain small.
```

---

## 54. Refactoring Evidence

Minimum:

```text
3 refactoring entries
```

Setiap entry:

```text
Smell
Before
Refactoring technique
After
Reason
Impact
```

Contoh smell:

```text
Long Method
Duplicate Code
Feature Envy
Primitive Obsession
Large Class
```

---

## 55. SRP Audit

Minimal satu kelas awal yang memiliki lebih dari satu responsibility.

Contoh:

```text
Before:

SalesOrderService
- validate HTTP
- save order
- approve order
- manipulate stock
- create CSV

After:

SalesOrderController
SalesOrderService
GoodsIssueService
ReportService
```

---

## 56. Tech Debt Register

File:

```text
docs/quality/tech-debt.md
```

Format:

```markdown
## TD-001

Problem:
CSV generation currently loads all records into memory.

Reason:
Limited implementation time.

Impact:
Large reports may consume excessive memory.

Ideal Fix:
Stream CSV rows directly from database cursor.
```

---

## 57. Critique Exercise

File:

```text
docs/quality/critique.md
```

Untuk kode yang diberikan assessor, analisis:

```text
Code smell
SOLID principle violation
Why problematic
Suggested refactoring
```

Tidak wajib melakukan implementasi refactoring tersebut.

---

## 58. AI Usage Log

File:

```text
ai-usage-log.md
```

Format yang disarankan:

```markdown
## Entry 001

Date:
2026-...

Tool:
ChatGPT

Purpose:
Design initial database schema.

Prompt summary:
Asked AI to review proposed Inventory Management ERD.

Output used:
Suggestions regarding ProductStock unique constraint.

Output rejected:
Suggested Laravel implementation because framework is prohibited.

Verification:
Checked against Final Project Brief DB-01 and ARCH-01.
```

Empat kewajiban penggunaan AI:

```text
DISCLOSE
REVIEW
VERIFY
TEST
```

---

## 59. README Requirements

README harus memiliki:

```text
Project overview
Features
Technology stack
Architecture overview
Requirements
Installation
Docker startup
Database initialization
Demo accounts
Run unit tests
Run integration tests
Run static analysis
Run scheduled script
Known limitations
```

---

## 60. Docker Acceptance

Dari folder kosong:

```bash
git clone ...
cd inventory-order-management
cp .env.example .env
docker compose up --build
```

Kemudian database harus dapat dibuat melalui prosedur README.

Aplikasi tidak boleh bergantung pada absolute path yang spesifik ke komputer peserta.

---

## 61. Recommended Routes

```text
GET  /login
POST /login
POST /logout

GET  /dashboard

GET  /users
GET  /users/create
POST /users
GET  /users/{id}/edit
POST /users/{id}/update
POST /users/{id}/activate
POST /users/{id}/deactivate

GET  /products
GET  /products/{id}
GET  /products/create
POST /products
POST /products/{id}/update

GET  /warehouses
POST /warehouses

GET  /purchase-orders
GET  /purchase-orders/create
POST /purchase-orders
GET  /purchase-orders/{id}
POST /purchase-orders/{id}/order
POST /purchase-orders/{id}/receive
POST /purchase-orders/{id}/cancel

GET  /sales-orders
GET  /sales-orders/create
POST /sales-orders
GET  /sales-orders/{id}
POST /sales-orders/{id}/submit
POST /sales-orders/{id}/approve
POST /sales-orders/{id}/cancel
POST /sales-orders/{id}/issue

GET /reports/stock-movement.csv
GET /reports/orders.csv

GET /api/products/{sku}/availability
```

Route persisnya tidak ditetapkan brief; ini adalah rancangan implementasi agar scope konsisten.

---

## 62. Definition of Done — Core Feature

Sebuah feature dianggap selesai ketika:

```text
[ ] UI works
[ ] server-side validation works
[ ] authorization works
[ ] repository uses prepared statements
[ ] failure state handled
[ ] relevant test exists
[ ] Docker environment works
[ ] documentation/evidence updated where applicable
```

---

## 63. Recommended Implementation Order

```text
Phase 0
Project skeleton
Docker
Composer
DB connection
Routing

↓

Phase 1
Authentication
Session
Authorization
3 roles

↓

Phase 2
Category
Product
Warehouse
ProductStock
Supplier
Customer

↓

Phase 3
Purchase Order
Goods Receipt
StockLedger

↓

Phase 4
Sales Order
Submit
Approval
Goods Issue
Concurrency protection

↓

Phase 5
Search
Filter
Sort
Pagination

↓

Phase 6
Dashboard
Reports

↓

Phase 7
JSON API

↓

Phase 8
Scheduled low-stock script

↓

Phase 9
Unit tests
Integration tests
Static analysis

↓

Phase 10
Responsive polish
Documentation
Refactor
ADR
Evidence
```

Test sebaiknya mulai ditulis sejak business rule dibuat, bukan menunggu seluruh Phase 9.

---

## 64. Suggested Initial Backlog

### Epic 1 — Foundation

```text
PROJ-001 Initialize Composer project
PROJ-002 Create Dockerfile
PROJ-003 Create compose.yaml
PROJ-004 Configure MySQL
PROJ-005 Create environment loader
PROJ-006 Create application bootstrap
PROJ-007 Implement simple router
```

### Epic 2 — Authentication

```text
AUTH-001 Login
AUTH-002 Session protection
AUTH-003 Role authorization
AUTH-004 Logout
AUTH-005 User management
```

### Epic 3 — Master Data

```text
MASTER-001 Categories
MASTER-002 Products
MASTER-003 Product image
MASTER-004 Warehouses
MASTER-005 ProductStock
MASTER-006 Suppliers
MASTER-007 Customers
```

### Epic 4 — Purchase

```text
PO-001 Create PO
PO-002 PO items
PO-003 Submit/order PO
PO-004 Partial goods receipt
PO-005 Full goods receipt
PO-006 Stock ledger receipt
```

### Epic 5 — Sales

```text
SO-001 Create SO
SO-002 Submit SO
SO-003 Admin approval
SO-004 Server authorization
SO-005 Goods issue
SO-006 Insufficient stock handling
SO-007 Concurrency protection
SO-008 Ledger issue
```

### Epic 6 — Query UX

```text
VIEW-001 Product list
VIEW-002 PO list
VIEW-003 SO list
VIEW-004 Search
VIEW-005 Filter
VIEW-006 Sort
VIEW-007 Pagination
VIEW-008 Empty state
```

### Epic 7 — Analytics

```text
DASH-001 Admin dashboard
DASH-002 Sales dashboard
DASH-003 Warehouse dashboard
REPORT-001 Stock CSV
REPORT-002 Order CSV
```

### Epic 8 — Other Mandatory Features

```text
API-001 Product availability JSON
JOB-001 Low stock CLI script
```

### Epic 9 — Engineering Evidence

```text
TEST-001 Unit tests
TEST-002 Integration tests
TEST-003 Concurrency scenario
QUALITY-001 PHPStan
QUALITY-002 Initial class diagram
QUALITY-003 As-built diagram
QUALITY-004 ADR
QUALITY-005 Refactor log
QUALITY-006 SRP audit
QUALITY-007 Tech-debt register
QUALITY-008 Critique exercise
QUALITY-009 AI usage log
```

---

## 65. Critical Acceptance Scenarios

### Scenario A — Authentication

```text
Given user is active Sales
When login with valid credentials
Then Sales dashboard appears
```

### Scenario B — Unauthorized Approval

```text
Given Sales created SO-001
When Sales POST /sales-orders/1/approve
Then response = 403
And SO status remains PendingApproval
```

### Scenario C — PO Receipt

```text
Given Product stock = 10
And ordered PO qty = 20

When Warehouse receives 5

Then stock = 15
And PO = PartiallyReceived
And Receipt ledger = 5
```

### Scenario D — SO Fulfillment

```text
Given Approved SO qty = 10
And stock = 20

When goods issue processed

Then stock = 10
And ledger contains Issue 10
And SO = Fulfilled
```

### Scenario E — Insufficient Stock

```text
Given stock = 5
And SO requests 10

When goods issue processed

Then transaction fails
And stock remains 5
And no Issue ledger is committed
```

### Scenario F — Concurrency

```text
Given stock = 5

Issue A = 5
Issue B = 5

Then exactly one succeeds
And stock = 0
And stock never becomes negative
```

---

## 66. Non-Goals

Tidak perlu membuat:

```text
Microservices
Kafka / RabbitMQ
Kubernetes
CI/CD
Cloud deployment
Mobile application
Real-time notification
Real cron scheduler
Automated E2E tests
```

Jangan menambah teknologi tersebut hanya untuk membuat project terlihat lebih kompleks.

---

## 67. Project Success Criteria

Project dianggap memenuhi brief jika:

```text
✓ Docker dapat menjalankan app + MySQL
✓ login seluruh role berhasil
✓ authorization server-side benar
✓ master data berfungsi
✓ multi-warehouse stock berfungsi
✓ PO + partial/full receipt berfungsi
✓ SO + approval + fulfillment berfungsi
✓ ProductStock selalu konsisten dengan StockLedger
✓ oversell tidak dapat terjadi
✓ search/filter/sort/pagination berfungsi
✓ dashboard role-based menggunakan aggregate query
✓ CSV report berfungsi
✓ JSON API berfungsi
✓ low-stock CLI script berfungsi
✓ >= 6 unit tests
✓ >= 3 integration tests
✓ static analysis memenuhi requirement
✓ initial + as-built class diagram tersedia
✓ ADR tersedia
✓ refactoring evidence tersedia
✓ tech debt tercatat
✓ AI usage tercatat
✓ responsive pada 360px
✓ README setup dapat direproduksi
```

Target final:

```text
Nilai minimal 80 dan tidak memiliki critical failure.
```
