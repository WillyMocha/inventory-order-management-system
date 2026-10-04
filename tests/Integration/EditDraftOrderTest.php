<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Entity\Customer;
use App\Entity\Enum\PurchaseOrderStatus;
use App\Entity\Enum\SalesOrderStatus;
use App\Entity\PurchaseOrder;
use App\Entity\PurchaseOrderItem;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderItem;
use App\Entity\Supplier;
use App\Entity\Warehouse;
use App\Repository\Mysql\MysqlCustomerRepository;
use App\Repository\Mysql\MysqlProductRepository;
use App\Service\PurchaseOrderService;
use App\Service\SalesOrderService;
use App\Support\Exception\DomainException;
use App\Support\SystemClock;
use App\Repository\Mysql\MysqlPurchaseOrderRepository;
use App\Repository\Mysql\MysqlSalesOrderRepository;
use App\Repository\Mysql\MysqlSupplierRepository;
use App\Repository\Mysql\MysqlWarehouseRepository;
use App\Entity\Enum\Role;
use App\Support\Authorization;
use App\Support\Exception\ForbiddenException;
use App\Support\Router;
use App\Support\Session;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/**
 * Edit order Draft terhadap MySQL sungguhan (spec 004-edit-draft-orders).
 *
 * Yang dibuktikan di sini adalah bagian yang tidak dapat dibuktikan fake
 * in-memory: UPDATE bersyarat `status = 'Draft'` (research R-003), penggantian
 * line order (R-004), rollback header dan line bersama-sama di dalam satu
 * transaction (FR-011), dan bahwa semua itu tidak pernah menyentuh stock
 * (FR-012).
 */
final class EditDraftOrderTest extends IntegrationTestCase
{
    use SalesOrderFixtures;

    private const string EDITED_DATE = '2026-09-20';
    private const string SECOND_SELLING_PRICE = '2500.00';
    private const string SECOND_PURCHASE_PRICE = '2000.00';

    private MysqlSalesOrderRepository $salesOrders;
    private MysqlPurchaseOrderRepository $purchaseOrders;
    private int $otherWarehouseId;
    private int $otherCustomerId;
    private int $otherSupplierId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedSalesOrderFixtures();
        $this->salesOrders = new MysqlSalesOrderRepository($this->database);
        $this->purchaseOrders = new MysqlPurchaseOrderRepository($this->database);

        $this->otherWarehouseId = (new MysqlWarehouseRepository($this->database))
            ->save(new Warehouse(null, 'Edit Other Warehouse', 'Surabaya', true));
        $this->otherCustomerId = (new MysqlCustomerRepository($this->database))
            ->save(new Customer(null, 'Edit Other Customer', '0800', 'Bandung', true));
        $this->otherSupplierId = (new MysqlSupplierRepository($this->database))
            ->save(new Supplier(null, 'Edit Other Supplier', '0800', 'Bandung', true));
    }

    // ------------------------------------------------ Sales Order (R-003/R-004)

    #[Test]
    public function updateDraftWritesTheSalesOrderHeaderOnly(): void
    {
        $id = $this->draftOrder([[$this->productId, 2]]);
        $before = $this->requireSalesOrder($id);

        self::assertTrue($this->salesOrders->updateDraft($this->editedSalesOrder($before)));

        $after = $this->requireSalesOrder($id);
        self::assertSame($this->otherCustomerId, $after->customerId);
        self::assertSame($this->otherWarehouseId, $after->warehouseId);
        self::assertSame(self::EDITED_DATE, $after->orderDate);
        self::assertSame($before->orderNumber, $after->orderNumber);
        self::assertSame(SalesOrderStatus::Draft, $after->status);
        self::assertSame($before->createdBy, $after->createdBy);
        self::assertNull($after->approvedBy);
        self::assertCount(1, $after->items, 'updateDraft tidak menyentuh line');
    }

    #[Test]
    public function updateDraftAlsoSucceedsWhenNothingChanged(): void
    {
        // rowCount() MySQL menghitung baris yang BERUBAH; menyimpan nilai yang
        // sama tetap harus dianggap berhasil selama order masih Draft.
        $id = $this->draftOrder([[$this->productId, 2]]);
        $order = $this->requireSalesOrder($id);

        self::assertTrue($this->salesOrders->updateDraft($order));
        self::assertTrue($this->salesOrders->updateDraft($order));
    }

    #[Test]
    public function updateDraftRefusesASalesOrderThatLeftDraft(): void
    {
        $id = $this->draftOrder([[$this->productId, 2]]);
        $before = $this->requireSalesOrder($id);
        $this->salesOrders->updateStatus($id, SalesOrderStatus::Draft, SalesOrderStatus::PendingApproval);

        self::assertFalse($this->salesOrders->updateDraft($this->editedSalesOrder($before)));

        $after = $this->requireSalesOrder($id);
        self::assertSame($before->customerId, $after->customerId);
        self::assertSame($before->warehouseId, $after->warehouseId);
        self::assertSame(SalesOrderStatus::PendingApproval, $after->status);
    }

    #[Test]
    public function replaceItemsLeavesExactlyTheGivenSalesOrderLines(): void
    {
        $id = $this->draftOrder([[$this->productId, 2], [$this->secondProductId, 1]]);
        $oldItemIds = array_map(static fn (SalesOrderItem $i): ?int => $i->id, $this->requireSalesOrder($id)->items);

        $this->salesOrders->replaceItems(
            $id,
            [new SalesOrderItem(null, null, $this->secondProductId, 7, self::SECOND_SELLING_PRICE)],
        );

        $items = $this->requireSalesOrder($id)->items;
        self::assertCount(1, $items);
        self::assertSame($this->secondProductId, $items[0]->productId);
        self::assertSame(7, $items[0]->quantity);
        self::assertSame(self::SECOND_SELLING_PRICE, $items[0]->sellingPrice);
        self::assertNotContains($items[0]->id, $oldItemIds, 'line lama dihapus, bukan diubah');
    }

    #[Test]
    public function aFailedSalesOrderEditRollsBackHeaderAndLinesTogether(): void
    {
        $id = $this->draftOrder([[$this->productId, 2]]);
        $before = $this->requireSalesOrder($id);

        $this->failInsideTransaction(function () use ($before, $id): void {
            $this->salesOrders->updateDraft($this->editedSalesOrder($before));
            $this->salesOrders->replaceItems(
                $id,
                [new SalesOrderItem(null, null, $this->secondProductId, 9, self::SECOND_SELLING_PRICE)],
            );
        });

        $after = $this->requireSalesOrder($id);
        self::assertSame($before->customerId, $after->customerId);
        self::assertCount(1, $after->items);
        self::assertSame($this->productId, $after->items[0]->productId);
        self::assertSame(2, $after->items[0]->quantity);
    }

    // --------------------------------------------- Purchase Order (R-003/R-004)

    #[Test]
    public function updateDraftWritesThePurchaseOrderHeaderOnly(): void
    {
        $id = $this->draftPurchaseOrder([[$this->productId, 4]]);
        $before = $this->requirePurchaseOrder($id);

        self::assertTrue($this->purchaseOrders->updateDraft($this->editedPurchaseOrder($before)));

        $after = $this->requirePurchaseOrder($id);
        self::assertSame($this->otherSupplierId, $after->supplierId);
        self::assertSame($this->otherWarehouseId, $after->warehouseId);
        self::assertSame(self::EDITED_DATE, $after->orderDate);
        self::assertSame($before->orderNumber, $after->orderNumber);
        self::assertSame(PurchaseOrderStatus::Draft, $after->status);
        self::assertSame($before->createdBy, $after->createdBy);
        self::assertCount(1, $after->items);
    }

    #[Test]
    public function updateDraftRefusesAPurchaseOrderThatLeftDraft(): void
    {
        $id = $this->draftPurchaseOrder([[$this->productId, 4]]);
        $before = $this->requirePurchaseOrder($id);
        $this->purchaseOrders->updateStatus($id, PurchaseOrderStatus::Draft, PurchaseOrderStatus::Ordered);

        self::assertFalse($this->purchaseOrders->updateDraft($this->editedPurchaseOrder($before)));

        $after = $this->requirePurchaseOrder($id);
        self::assertSame($before->supplierId, $after->supplierId);
        self::assertSame(PurchaseOrderStatus::Ordered, $after->status);
    }

    #[Test]
    public function replaceItemsLeavesExactlyTheGivenPurchaseOrderLines(): void
    {
        $id = $this->draftPurchaseOrder([[$this->productId, 4], [$this->secondProductId, 3]]);

        $this->purchaseOrders->replaceItems(
            $id,
            [new PurchaseOrderItem(null, null, $this->secondProductId, 11, 0, self::SECOND_PURCHASE_PRICE)],
        );

        $items = $this->requirePurchaseOrder($id)->items;
        self::assertCount(1, $items);
        self::assertSame($this->secondProductId, $items[0]->productId);
        self::assertSame(11, $items[0]->quantity);
        self::assertSame(0, $items[0]->receivedQuantity);
        self::assertSame(self::SECOND_PURCHASE_PRICE, $items[0]->purchasePrice);
    }

    #[Test]
    public function aFailedPurchaseOrderEditRollsBackHeaderAndLinesTogether(): void
    {
        $id = $this->draftPurchaseOrder([[$this->productId, 4]]);
        $before = $this->requirePurchaseOrder($id);

        $this->failInsideTransaction(function () use ($before, $id): void {
            $this->purchaseOrders->updateDraft($this->editedPurchaseOrder($before));
            $this->purchaseOrders->replaceItems(
                $id,
                [new PurchaseOrderItem(null, null, $this->secondProductId, 9, 0, self::SECOND_PURCHASE_PRICE)],
            );
        });

        $after = $this->requirePurchaseOrder($id);
        self::assertSame($before->supplierId, $after->supplierId);
        self::assertCount(1, $after->items);
        self::assertSame(4, $after->items[0]->quantity);
    }

    // ---------------------------------------------------------- FR-012

    #[Test]
    public function editingDraftsNeverTouchesStock(): void
    {
        $this->setStock($this->productId, $this->warehouseId, 20);
        $ledgerBefore = $this->ledgerRowCount();

        $salesId = $this->draftOrder([[$this->productId, 2]]);
        $this->salesOrders->updateDraft($this->editedSalesOrder($this->requireSalesOrder($salesId)));
        $this->salesOrders->replaceItems($salesId, [new SalesOrderItem(null, null, $this->productId, 15, '1500.00')]);

        $purchaseId = $this->draftPurchaseOrder([[$this->productId, 4]]);
        $this->purchaseOrders->updateDraft($this->editedPurchaseOrder($this->requirePurchaseOrder($purchaseId)));
        $this->purchaseOrders->replaceItems(
            $purchaseId,
            [new PurchaseOrderItem(null, null, $this->productId, 30, 0, '1000.00')],
        );

        self::assertSame($ledgerBefore, $this->ledgerRowCount());
        self::assertSame(20, $this->stockQuantity($this->productId, $this->warehouseId));
    }

    // ------------------------------------ jalur Service di MySQL (FR-005, FR-011)

    #[Test]
    public function theSalesOrderServiceEditsADraftOnMysql(): void
    {
        $id = $this->draftOrder([[$this->productId, 2], [$this->secondProductId, 1]]);

        $this->salesOrderService()->update($id, [
            'customer_id'  => (string) $this->otherCustomerId,
            'warehouse_id' => (string) $this->otherWarehouseId,
            'order_date'   => self::EDITED_DATE,
            'items'        => [['product_id' => (string) $this->secondProductId, 'quantity' => '4']],
        ], $this->salesCreator);

        $after = $this->requireSalesOrder($id);
        self::assertSame($this->otherCustomerId, $after->customerId);
        self::assertCount(1, $after->items);
        self::assertSame(4, $after->items[0]->quantity);
        // Harga diambil dari katalog fixture (selling_price 2500.00), bukan dari payload.
        self::assertSame(self::SECOND_SELLING_PRICE, $after->items[0]->sellingPrice);
    }

    #[Test]
    public function aSubmittedSalesOrderIsRefusedOnMysqlAndKeepsItsLines(): void
    {
        $id = $this->draftOrder([[$this->productId, 2]]);
        $this->salesOrders->updateStatus($id, SalesOrderStatus::Draft, SalesOrderStatus::PendingApproval);

        try {
            $this->salesOrderService()->update($id, [
                'customer_id'  => (string) $this->otherCustomerId,
                'warehouse_id' => (string) $this->warehouseId,
                'items'        => [['product_id' => (string) $this->secondProductId, 'quantity' => '9']],
            ], $this->salesCreator);
            self::fail('Order yang sudah di-submit seharusnya ditolak');
        } catch (DomainException $e) {
            self::assertSame('Only a draft order can be edited.', $e->getMessage());
        }

        $after = $this->requireSalesOrder($id);
        self::assertSame($this->customerId, $after->customerId);
        self::assertSame(2, $after->items[0]->quantity);
    }

    #[Test]
    public function thePurchaseOrderServiceEditsADraftOnMysql(): void
    {
        $id = $this->draftPurchaseOrder([[$this->productId, 4]]);

        $this->purchaseOrderService()->update($id, [
            'supplier_id'  => (string) $this->otherSupplierId,
            'warehouse_id' => (string) $this->otherWarehouseId,
            'items'        => [['product_id' => (string) $this->secondProductId, 'quantity' => '6']],
        ], $this->admin);

        $after = $this->requirePurchaseOrder($id);
        self::assertSame($this->otherSupplierId, $after->supplierId);
        self::assertSame($this->otherWarehouseId, $after->warehouseId);
        self::assertSame(6, $after->items[0]->quantity);
        self::assertSame(self::SECOND_PURCHASE_PRICE, $after->items[0]->purchasePrice);
        self::assertSame((int) $this->warehouseStaff->id, $after->createdBy);
    }

    #[Test]
    public function anOrderedPurchaseOrderIsRefusedOnMysqlAndKeepsItsLines(): void
    {
        $id = $this->orderedPurchaseOrder([[$this->productId, 4]]);

        try {
            $this->purchaseOrderService()->update($id, [
                'supplier_id'  => (string) $this->otherSupplierId,
                'warehouse_id' => (string) $this->warehouseId,
                'items'        => [['product_id' => (string) $this->secondProductId, 'quantity' => '9']],
            ], $this->admin);
            self::fail('PO yang sudah Ordered seharusnya ditolak');
        } catch (DomainException $e) {
            self::assertSame('Only a draft order can be edited.', $e->getMessage());
        }

        $after = $this->requirePurchaseOrder($id);
        self::assertSame($this->supplierId, $after->supplierId);
        self::assertSame(4, $after->items[0]->quantity);
    }

    // ------------------------------------------ route table (FR-001 s/d FR-004)

    #[Test]
    public function bothSalesOrderEditRoutesAllowExactlyAdminAndSales(): void
    {
        $router = $this->routeTable();

        $routes = [['GET', '/sales-orders/7/edit', 'edit'], ['POST', '/sales-orders/7', 'update']];

        foreach ($routes as [$method, $path, $action]) {
            $matched = $router->match($method, $path);

            self::assertSame('SalesOrderController', $matched['controller']);
            self::assertSame($action, $matched['action']);
            self::assertSame([Role::Admin, Role::Sales], $matched['roles'], $method);
        }
    }

    #[Test]
    public function theGuardRefusesWarehouseStaffAtTheSalesOrderEditRoute(): void
    {
        $this->assertGuardRefuses(Role::WarehouseStaff, 'POST', '/sales-orders/7');
    }

    #[Test]
    public function bothPurchaseOrderEditRoutesAllowExactlyAdminAndWarehouseStaff(): void
    {
        $router = $this->routeTable();
        $routes = [['GET', '/purchase-orders/7/edit', 'edit'], ['POST', '/purchase-orders/7', 'update']];

        foreach ($routes as [$method, $path, $action]) {
            $matched = $router->match($method, $path);

            self::assertSame('PurchaseOrderController', $matched['controller']);
            self::assertSame($action, $matched['action']);
            self::assertSame([Role::Admin, Role::WarehouseStaff], $matched['roles'], $method);
        }
    }

    #[Test]
    public function theGuardRefusesSalesAtThePurchaseOrderEditRoute(): void
    {
        $this->assertGuardRefuses(Role::Sales, 'POST', '/purchase-orders/7');
    }

    // ---------------------------------------------------------- helper

    private function salesOrderService(): SalesOrderService
    {
        return new SalesOrderService(
            $this->salesOrders,
            new MysqlCustomerRepository($this->database),
            new MysqlWarehouseRepository($this->database),
            new MysqlProductRepository($this->database),
            new SystemClock(),
            $this->database,
        );
    }

    private function purchaseOrderService(): PurchaseOrderService
    {
        return new PurchaseOrderService(
            $this->purchaseOrders,
            new MysqlSupplierRepository($this->database),
            new MysqlWarehouseRepository($this->database),
            new MysqlProductRepository($this->database),
            new SystemClock(),
            $this->database,
        );
    }

    private function routeTable(): Router
    {
        $router = new Router();
        /** @var callable(Router): void $register */
        $register = require dirname(__DIR__, 2) . '/config/routes.php';
        $register($router);

        return $router;
    }

    private function assertGuardRefuses(Role $role, string $method, string $path): void
    {
        $roles = $this->routeTable()->match($method, $path)['roles'];
        $_SESSION = ['auth_user_id' => 99, 'auth_user_role' => $role->value, 'auth_user_name' => 'Guard Test'];

        try {
            $this->expectException(ForbiddenException::class);
            (new Authorization(new Session('IOMS_TEST_SESSION', false)))->authorizeRoute($roles);
        } finally {
            $_SESSION = [];
        }
    }

    private function editedSalesOrder(SalesOrder $order): SalesOrder
    {
        return new SalesOrder(
            $order->id,
            $order->orderNumber,
            $this->otherCustomerId,
            $order->createdBy,
            $order->approvedBy,
            $this->otherWarehouseId,
            $order->status,
            self::EDITED_DATE,
        );
    }

    private function editedPurchaseOrder(PurchaseOrder $order): PurchaseOrder
    {
        return new PurchaseOrder(
            $order->id,
            $order->orderNumber,
            $this->otherSupplierId,
            $this->otherWarehouseId,
            $order->status,
            self::EDITED_DATE,
            $order->createdBy,
        );
    }

    /** @param list<array{0: int, 1: int}> $lines [productId, quantity] */
    private function draftPurchaseOrder(array $lines): int
    {
        $items = [];
        foreach ($lines as $line) {
            $items[] = new PurchaseOrderItem(null, null, $line[0], $line[1], 0, '1000.00');
        }

        return $this->purchaseOrders->save(new PurchaseOrder(
            null,
            'PO-FIXTURE-' . bin2hex(random_bytes(6)),
            $this->supplierId,
            $this->warehouseId,
            PurchaseOrderStatus::Draft,
            '2026-09-11',
            (int) $this->warehouseStaff->id,
            $items,
        ));
    }

    /** Menjalankan callback dalam transaction lalu memaksanya gagal. */
    private function failInsideTransaction(callable $work): void
    {
        $thrown = null;

        try {
            $this->database->transaction(static function () use ($work): void {
                $work();

                throw new RuntimeException('gagal setelah header dan line ditulis');
            });
        } catch (RuntimeException $e) {
            $thrown = $e;
        }

        self::assertInstanceOf(RuntimeException::class, $thrown, 'Transaction seharusnya gagal');
    }

    private function requireSalesOrder(int $id): SalesOrder
    {
        $order = $this->salesOrders->findById($id);
        self::assertNotNull($order);

        return $order;
    }

    private function requirePurchaseOrder(int $id): PurchaseOrder
    {
        $order = $this->purchaseOrders->findById($id);
        self::assertNotNull($order);

        return $order;
    }
}
