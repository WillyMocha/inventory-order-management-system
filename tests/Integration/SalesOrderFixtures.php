<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Entity\Category;
use App\Entity\Customer;
use App\Entity\Enum\Role;
use App\Entity\Enum\SalesOrderStatus;
use App\Entity\Enum\PurchaseOrderStatus;
use App\Entity\Product;
use App\Entity\PurchaseOrder;
use App\Entity\PurchaseOrderItem;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderItem;
use App\Entity\User;
use App\Entity\Supplier;
use App\Entity\Warehouse;
use App\Repository\Mysql\MysqlCategoryRepository;
use App\Repository\Mysql\MysqlCustomerRepository;
use App\Repository\Mysql\MysqlProductRepository;
use App\Repository\Mysql\MysqlPurchaseOrderRepository;
use App\Repository\Mysql\MysqlSupplierRepository;
use App\Repository\Mysql\MysqlSalesOrderRepository;
use App\Repository\Mysql\MysqlUserRepository;
use App\Repository\Mysql\MysqlWarehouseRepository;

/**
 * Fixture bersama untuk integration test seputar Sales Order dan stock.
 *
 * Dipakai GoodsIssueTest, ConcurrentGoodsIssueTest dan
 * LedgerReconciliationTest. Dijadikan trait agar ketiganya memakai data awal
 * yang sama persis — kalau tiap test menyusun fixture-nya sendiri, perbedaan
 * kecil di antaranya akan menyulitkan pembacaan kegagalan.
 *
 * @phpstan-require-extends IntegrationTestCase
 */
trait SalesOrderFixtures
{
    protected User $admin;
    protected User $salesCreator;
    protected User $warehouseStaff;

    protected int $customerId;
    protected int $supplierId;
    protected int $warehouseId;
    protected int $productId;
    protected int $secondProductId;

    protected function seedSalesOrderFixtures(): void
    {
        $users = new MysqlUserRepository($this->database);

        $this->admin = $this->persistFixtureUser($users, 'Fixture Admin', 'fixture-admin@test', Role::Admin);
        $this->salesCreator = $this->persistFixtureUser(
            $users,
            'Fixture Sales',
            'fixture-sales@test',
            Role::Sales,
        );
        $this->warehouseStaff = $this->persistFixtureUser(
            $users,
            'Fixture Warehouse',
            'fixture-wh@test',
            Role::WarehouseStaff,
        );

        $customers = new MysqlCustomerRepository($this->database);
        $this->customerId = $customers->save(new Customer(null, 'Fixture Customer', '0800', 'Jakarta', true));

        $suppliers = new MysqlSupplierRepository($this->database);
        $this->supplierId = $suppliers->save(new Supplier(null, 'Fixture Supplier', '0800', 'Jakarta', true));

        $warehouses = new MysqlWarehouseRepository($this->database);
        $this->warehouseId = $warehouses->save(new Warehouse(null, 'Fixture Warehouse Site', 'Jakarta', true));

        $categories = new MysqlCategoryRepository($this->database);
        $categoryId = $categories->save(new Category(null, 'Fixture Category', 'Fixture'));

        $products = new MysqlProductRepository($this->database);

        $this->productId = $products->save(new Product(
            null,
            'FIXTURE-SKU-1',
            'Fixture Product One',
            $categoryId,
            'pcs',
            '1000.00',
            '1500.00',
            5,
            null,
            true,
        ));

        $this->secondProductId = $products->save(new Product(
            null,
            'FIXTURE-SKU-2',
            'Fixture Product Two',
            $categoryId,
            'pcs',
            '2000.00',
            '2500.00',
            5,
            null,
            true,
        ));
    }

    /**
     * Menghapus seluruh baris yang di-seed trait ini.
     *
     * Hanya dibutuhkan test yang mematikan wrapsInTransaction() — pada test itu
     * fixture-nya benar-benar ter-commit sehingga tidak ada rollback yang
     * membersihkannya.
     *
     * Urutannya menghormati foreign key: baris anak lebih dulu, baris induk
     * terakhir. Seluruh predikatnya terikat pada penanda fixture ('FIXTURE-',
     * 'SO-FIXTURE-', 'PO-FIXTURE-', 'fixture-%@test'), sehingga data lain di
     * database test tidak pernah ikut terhapus.
     */
    protected function cleanUpSalesOrderFixtures(): void
    {
        $statements = [
            "DELETE FROM stock_ledger WHERE performed_by IN
                (SELECT id FROM `user` WHERE email LIKE 'fixture-%@test')",
            "DELETE FROM sales_order_item WHERE sales_order_id IN
                (SELECT id FROM sales_order WHERE order_number LIKE 'SO-FIXTURE-%')",
            "DELETE FROM sales_order WHERE order_number LIKE 'SO-FIXTURE-%'",
            "DELETE FROM purchase_order_item WHERE purchase_order_id IN
                (SELECT id FROM purchase_order WHERE order_number LIKE 'PO-FIXTURE-%')",
            "DELETE FROM purchase_order WHERE order_number LIKE 'PO-FIXTURE-%'",
            "DELETE FROM product_stock WHERE product_id IN
                (SELECT id FROM product WHERE sku LIKE 'FIXTURE-SKU-%')",
            "DELETE FROM product WHERE sku LIKE 'FIXTURE-SKU-%'",
            "DELETE FROM category WHERE name = 'Fixture Category'",
            "DELETE FROM customer WHERE name = 'Fixture Customer'",
            "DELETE FROM supplier WHERE name = 'Fixture Supplier'",
            "DELETE FROM warehouse WHERE name = 'Fixture Warehouse Site'",
            "DELETE FROM `user` WHERE email LIKE 'fixture-%@test'",
        ];

        foreach ($statements as $statement) {
            $this->pdo->exec($statement);
        }
    }

    /**
     * Menetapkan quantity awal LANGSUNG lewat SQL.
     *
     * Ini satu-satunya tempat stock boleh diubah tanpa ledger, dan hanya
     * karena ini penyiapan fixture — bukan jalur aplikasi. Test yang memeriksa
     * invariant ledger menyiapkan stock-nya lewat ledger, bukan lewat method
     * ini.
     */
    protected function setStock(int $productId, int $warehouseId, int $quantity): void
    {
        // Placeholder untuk quantity ditulis DUA KALI dengan nama berbeda.
        // ATTR_EMULATE_PREPARES = false berarti PDO meneruskan statement apa
        // adanya ke MySQL, dan satu nama placeholder hanya boleh muncul sekali
        // — memakai :quantity di VALUES dan di UPDATE sekaligus menghasilkan
        // "Invalid parameter number".
        $statement = $this->pdo->prepare(
            'INSERT INTO product_stock (product_id, warehouse_id, quantity, updated_at)
                  VALUES (:product_id, :warehouse_id, :quantity, NOW())
             ON DUPLICATE KEY UPDATE quantity = :new_quantity, updated_at = NOW()',
        );

        $statement->execute([
            'product_id'   => $productId,
            'warehouse_id' => $warehouseId,
            'quantity'     => $quantity,
            'new_quantity' => $quantity,
        ]);
    }

    protected function stockQuantity(int $productId, int $warehouseId): int
    {
        $statement = $this->pdo->prepare(
            'SELECT quantity FROM product_stock
              WHERE product_id = :product_id AND warehouse_id = :warehouse_id',
        );

        $statement->execute(['product_id' => $productId, 'warehouse_id' => $warehouseId]);

        $value = $statement->fetchColumn();

        return $value === false ? 0 : (int) $value;
    }

    protected function ledgerRowCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM stock_ledger')->fetchColumn();
    }

    /** @param list<array{0: int, 1: int}> $lines [productId, quantity] */
    protected function approvedOrder(array $lines): int
    {
        return $this->persistOrder($lines, SalesOrderStatus::Approved, (int) $this->admin->id);
    }

    /** @param list<array{0: int, 1: int}> $lines */
    protected function draftOrder(array $lines): int
    {
        return $this->persistOrder($lines, SalesOrderStatus::Draft, null);
    }

    /**
     * Purchase Order berstatus Ordered — siap menerima barang.
     *
     * @param list<array{0: int, 1: int}> $lines [productId, quantity]
     */
    protected function orderedPurchaseOrder(array $lines, ?int $warehouseId = null): int
    {
        $orders = new MysqlPurchaseOrderRepository($this->database);

        $items = [];
        foreach ($lines as $line) {
            $items[] = new PurchaseOrderItem(null, null, $line[0], $line[1], 0, '1000.00');
        }

        $orderId = $orders->save(new PurchaseOrder(
            null,
            'PO-FIXTURE-' . bin2hex(random_bytes(6)),
            $this->supplierId,
            $warehouseId ?? $this->warehouseId,
            PurchaseOrderStatus::Draft,
            '2026-09-11',
            (int) $this->warehouseStaff->id,
            $items,
        ));

        $orders->updateStatus($orderId, PurchaseOrderStatus::Draft, PurchaseOrderStatus::Ordered);

        return $orderId;
    }

    /**
     * Id purchase_order_item milik satu PO, dalam urutan penyimpanannya.
     *
     * @return list<int>
     */
    protected function purchaseItemIds(int $purchaseOrderId): array
    {
        $order = (new MysqlPurchaseOrderRepository($this->database))->findById($purchaseOrderId);

        if ($order === null) {
            self::fail('Fixture purchase order tidak ditemukan: ' . $purchaseOrderId);
        }

        return array_map(static fn (PurchaseOrderItem $i): int => (int) $i->id, $order->items);
    }

    /** @param list<array{0: int, 1: int}> $lines */
    private function persistOrder(array $lines, SalesOrderStatus $status, ?int $approvedBy): int
    {
        $orders = new MysqlSalesOrderRepository($this->database);

        $items = [];
        foreach ($lines as $line) {
            $items[] = new SalesOrderItem(null, null, $line[0], $line[1], '1500.00');
        }

        $orderId = $orders->save(new SalesOrder(
            null,
            'SO-FIXTURE-' . bin2hex(random_bytes(6)),
            $this->customerId,
            (int) $this->salesCreator->id,
            null,
            $this->warehouseId,
            SalesOrderStatus::Draft,
            '2026-09-11',
            $items,
        ));

        // save() selalu menulis Draft; status akhir di-set terpisah agar
        // fixture dapat memulai dari tahap mana pun tanpa menjalankan seluruh
        // alur approval.
        // updateStatus() bersifat compare-and-set, jadi status asal ikut
        // disebut; markApproved() hanya berlaku dari PendingApproval.
        if ($status !== SalesOrderStatus::Draft) {
            if ($approvedBy === null) {
                $orders->updateStatus($orderId, SalesOrderStatus::Draft, $status);
            } else {
                $orders->updateStatus($orderId, SalesOrderStatus::Draft, SalesOrderStatus::PendingApproval);
                $orders->markApproved($orderId, $approvedBy, '2026-09-11 10:00:00');

                if ($status !== SalesOrderStatus::Approved) {
                    $orders->updateStatus($orderId, SalesOrderStatus::Approved, $status);
                }
            }
        }

        return $orderId;
    }

    private function persistFixtureUser(
        MysqlUserRepository $users,
        string $name,
        string $email,
        Role $role,
    ): User {
        $id = $users->save(new User(
            null,
            $name,
            $email,
            password_hash('Password123!', PASSWORD_DEFAULT),
            $role,
            true,
        ));

        $user = $users->findById($id);

        if ($user === null) {
            self::fail('Fixture user gagal tersimpan: ' . $email);
        }

        return $user;
    }
}
