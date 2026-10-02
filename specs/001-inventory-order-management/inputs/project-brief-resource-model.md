# Resource Model Digest — Project Brief (Inventory & Order Management System)

**Source**: `inputs/Project Brief - Programmer.pdf` — Neuronworks Indonesia, Participant
Guide Edisi 1.0, "Intermediate Programmer - Final Project Brief", Oktober 2026.
**Extracted**: 2026-09-10
**Authority**: This digest is the authoritative domain model for `/rudis.plan`. Fields are
transcribed from §1.3 "Data dan nilai yang digunakan" and the requirement sections. Where
the source does not state a detail, it is marked `[not specified in source]` — nothing here
is invented.

---

## Entities

### User

- **Attributes** (§1.3): Nama · string · mandatory; email · string · mandatory, unique
  (USR-01); password · string · mandatory, stored via `password_hash()` (AUTH-01); role ·
  enum · mandatory; status aktif · boolean · mandatory; timestamps · mandatory.
- **Enumeration — role**: `Admin` | `Sales` | `WarehouseStaff` (exactly these three;
  USR-01 states no other role and no public registration).
- **Owns / contains**: none.
- **State lifecycle**: active / inactive only (`status aktif`). An inactive user cannot log
  in (AUTH-01).
- **Relationships**: creates SalesOrder (`dibuat oleh`); approves SalesOrder
  (`disetujui oleh`); performs StockLedger movements (`dilakukan oleh`).

### Warehouse

- **Attributes** (§1.3): Nama · string · mandatory; lokasi · string · mandatory;
  status aktif · boolean · mandatory.
- **Owns / contains**: none.
- **State lifecycle**: active / inactive.
- **Relationships**: holds many ProductStock rows; is the destination warehouse of
  PurchaseOrder (`gudang tujuan`) and the source warehouse of SalesOrder (`gudang asal`);
  referenced by StockLedger.

### Category

- **Attributes** (§1.3): Nama · string · mandatory; deskripsi · string ·
  [not specified in source whether mandatory].
- **Owns / contains**: none.
- **State lifecycle**: [not specified in source].
- **Relationships**: classifies many Product.

### Product

- **Attributes** (§1.3): SKU · string · mandatory, unique; nama · string · mandatory;
  kategori · reference to Category · mandatory; unit · string · mandatory; harga beli ·
  decimal · mandatory, `>= 0` (PRD-01); harga jual · decimal · mandatory, `>= 0` (PRD-01);
  reorder point · integer · mandatory, `>= 0` (PRD-01); gambar · file · **optional**,
  validated for file type and size and stored under a random unguessable filename (PRD-01);
  status aktif · boolean · mandatory.
- **Owns / contains**: one ProductStock row per Warehouse (WH-01).
- **State lifecycle**: active / inactive. A Product already used on an order may only be
  deactivated, never hard-deleted (PRD-01, §1.3 "Keputusan data").
- **Relationships**: belongs to one Category; has ProductStock per Warehouse; appears in
  PurchaseOrderItem and SalesOrderItem; referenced by StockLedger.

### ProductStock

- **Attributes** (§1.3): Produk · reference to Product · mandatory; gudang · reference to
  Warehouse · mandatory; quantity · integer · mandatory, **constraint `quantity >= 0`**;
  updated_at · timestamp · mandatory.
- **Owns / contains**: none.
- **State lifecycle**: none (a quantity, not a state machine).
- **Relationships**: one row per (Product, Warehouse) pair. **Never mutated directly by the
  UI** — only by a service that writes a StockLedger row and updates ProductStock inside a
  single transaction (§1.3 "Keputusan data", ARCH-02).

### Supplier

- **Attributes** (§1.3, shared row with Customer): Nama · string · mandatory; kontak ·
  string · mandatory; alamat · string · mandatory; status aktif · boolean · mandatory.
- **Owns / contains**: none.
- **State lifecycle**: active / inactive; deactivated, never hard-deleted (§1.3).
- **Relationships**: supplies many PurchaseOrder.

### Customer

- **Attributes** (§1.3, shared row with Supplier): Nama · string · mandatory; kontak ·
  string · mandatory; alamat · string · mandatory; status aktif · boolean · mandatory.
- **Owns / contains**: none.
- **State lifecycle**: active / inactive; deactivated, never hard-deleted (§1.3).
- **Relationships**: receives many SalesOrder.

### PurchaseOrder

- **Attributes** (§1.3): Supplier · reference · mandatory; gudang tujuan · reference to
  Warehouse · mandatory; status · enum · mandatory; tanggal order · date · mandatory.
- **Owns / contains**: **PurchaseOrderItem** collection (one or more).
- **Enumeration / state lifecycle** (§1.3, PO-01):
  `Draft` → `Ordered` → `PartiallyReceived` / `Received`, with `Cancelled` also listed as a
  status. Source states the flow as
  `Draft -> Ordered -> PartiallyReceived/Received -> Cancelled`.
  [The precise stage(s) at which Cancelled is reachable is not specified in source for PO —
  only for SO.]
- **Relationships**: belongs to one Supplier and one Warehouse; goods receipt against it
  produces StockLedger rows of type `Receipt` and increases ProductStock (PO-01).
- **Behavior noted in source**: partial receipt is allowed; the not-yet-received remaining
  quantity stays recorded (PO-01).

### PurchaseOrderItem

- **Attributes** (§1.3): produk · reference to Product · mandatory; qty · integer ·
  mandatory; harga beli · decimal · mandatory.
- **Owns / contains**: none.
- **State lifecycle**: [not specified in source]; remaining un-received quantity must be
  tracked (PO-01).
- **Relationships**: belongs to one PurchaseOrder; references one Product.

### SalesOrder

- **Attributes** (§1.3): Customer · reference · mandatory; dibuat oleh · reference to User ·
  mandatory; disetujui oleh · reference to User · [optional until approved]; gudang asal ·
  reference to Warehouse · mandatory; status · enum · mandatory.
  [tanggal order is not listed for SalesOrder in the source table.]
- **Owns / contains**: **SalesOrderItem** collection (one or more).
- **Enumeration / state lifecycle** (§1.3 "Alur status Sales Order", SO-01):
  `Draft` → `PendingApproval` → `Approved` → `Fulfilled`, **or** `Cancelled` from any stage
  before Fulfilled.
- **Relationships**: belongs to one Customer and one Warehouse; created by a User; approved
  by a User; goods issue against it produces StockLedger rows of type `Issue` and decreases
  ProductStock (SO-01).
- **Rules noted in source**: approval authorization is checked on the server — a Sales user
  cannot approve any order, including their own (SO-01, §1.2). Goods issue is only possible
  for status `Approved`, and is rejected when available stock is insufficient (SO-01).

### SalesOrderItem

- **Attributes** (§1.3): produk · reference to Product · mandatory; qty · integer ·
  mandatory; harga jual · decimal · mandatory.
- **Owns / contains**: none.
- **State lifecycle**: [not specified in source].
- **Relationships**: belongs to one SalesOrder; references one Product.

### StockLedger

- **Attributes** (§1.3): Produk · reference to Product · mandatory; gudang · reference to
  Warehouse · mandatory; tipe pergerakan · enum · mandatory; quantity · integer · mandatory;
  referensi (PO/SO id) · reference · mandatory; dilakukan oleh · reference to User ·
  mandatory; timestamp · mandatory.
- **Enumeration — tipe pergerakan**: `Receipt` | `Issue` | `Adjustment`.
- **Owns / contains**: none.
- **State lifecycle**: none — append-only movement history.
- **Relationships**: references Product, Warehouse, the originating PurchaseOrder or
  SalesOrder, and the acting User.
- **Invariant from source**: every stock number change must be traceable to exactly one
  ledger row; StockLedger and ProductStock must stay consistent (§1.3, §8.2 critical
  failure list).

---

## Cross-Entity Rules Stated in the Source

- **Stock mutation path** (§1.3): stock is *never* changed directly by the UI. A service
  writes the StockLedger row and updates ProductStock **in one transaction**. Changing stock
  outside this path is listed as a critical failure (§8.2).
- **Soft deactivation** (§1.3): Product, Supplier, and Customer are deactivated, never
  permanently deleted.
- **Segregation of duties** (§1.2): the Sales user who creates an order must not approve the
  same order, even their own; enforced in the server authorization layer, not by hiding UI.
- **Concurrency** (ARCH-02): two near-simultaneous goods issues for the same product and
  warehouse must not oversell and must not lose an update. The prevention mechanism is the
  implementer's design decision and must be recorded in an ADR.

---

## Role × Activity Matrix (§1.2, verbatim)

| Activity | Admin | Sales | Warehouse Staff |
| --- | --- | --- | --- |
| Login, logout, own profile | Yes | Yes | Yes |
| Manage users | Yes | No | No |
| Manage master data (product/warehouse/supplier/customer) | Yes | View catalog only | View products & stock only |
| Create & submit Sales Order | Yes | Yes, own only | No |
| Approve / reject Sales Order | Yes | No, not even own order | No |
| Create Purchase Order | Yes | No | May propose |
| Process goods receipt (PO) | Yes | No | Yes |
| Process goods issue (SO) | Yes | No | Yes |
| View dashboard | All data | Summary of own orders | Stock & fulfillment summary |
| Download CSV report | Yes | Own orders | Stock report |

---

## Requirement Index (source labels, for traceability)

`AUTH-01` login & session · `AUTH-02` logout · `USR-01` user management (3 roles) ·
`PRD-01` product, category & reorder point · `WH-01` warehouse & multi-location stock ·
`PO-01` purchase order & goods receipt · `SO-01` sales order, approval & goods issue ·
`VIEW-01` list, detail & empty state · `FIND-01` search, filter, sort & pagination ·
`DASH-01` role-scoped dashboard · `REPORT-01` CSV report · `API-01` JSON endpoint ·
`VAL-01` validation & feedback · `ERR-01` error handling · `UI-01` responsive & usability ·
`DB-01` relational database & transactions · `JOB-01` scheduled script ·
`ARCH-01` layer separation & repository interface · `ARCH-02` transactions &
concurrency-safe stock operation · `DESIGN-01..04` diagrams, ADR, refactor log, critique ·
`TEST-01..03` unit tests, integration tests, static analysis & FIRST.

---

## Minimum Seed Data (§7.1, verbatim)

- One Admin account, at least two Sales accounts, at least two Warehouse Staff accounts.
- At least two warehouses.
- 30 products with varied reorder points, including several below their reorder point.
- At least 25 combined orders (PO + SO) across varied statuses, including examples that are
  `PendingApproval` and `Cancelled`.
