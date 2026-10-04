<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Entity\Category;
use App\Entity\Enum\MovementType;
use App\Entity\Enum\ReferenceType;
use App\Entity\Enum\Role;
use App\Entity\Product;
use App\Entity\StockLedger;
use App\Entity\User;
use App\Entity\Warehouse;
use App\Repository\Mysql\MysqlCategoryRepository;
use App\Repository\Mysql\MysqlProductRepository;
use App\Repository\Mysql\MysqlProductStockRepository;
use App\Repository\Mysql\MysqlPurchaseOrderRepository;
use App\Repository\Mysql\MysqlSalesOrderRepository;
use App\Repository\Mysql\MysqlStockLedgerRepository;
use App\Repository\Mysql\MysqlUserRepository;
use App\Repository\Mysql\MysqlWarehouseRepository;
use App\Service\ProductService;
use App\Service\StockService;
use App\Support\Authorization;
use App\Support\Exception\ForbiddenException;
use App\Support\Exception\ValidationException;
use App\Support\Router;
use App\Support\Session;
use PDOException;
use PHPUnit\Framework\Attributes\Test;

/**
 * Koreksi stock (Adjustment) terhadap MySQL sungguhan (003-stock-adjustment).
 *
 * Bagian pertama membuktikan aturan schema dari migration 004_ledger_note.sql
 * langsung di database: alasan wajib tepat untuk Adjustment, dan Adjustment
 * selalu ber-reference Manual. Aturan ini dijaga CHECK constraint, bukan hanya
 * konvensi aplikasi (research R-001).
 */
final class StockAdjustmentFlowTest extends IntegrationTestCase
{
    /** Kode error MySQL untuk pelanggaran CHECK constraint. */
    private const int CHECK_VIOLATION = 3819;
    private const string REASON = '3 units water-damaged';

    private int $productId;
    private int $warehouseId;
    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $categoryId = (new MysqlCategoryRepository($this->database))
            ->save(new Category(null, 'Adjustment Category', 'Fixture category'));

        $this->productId = (new MysqlProductRepository($this->database))->save(new Product(
            null,
            'ADJUST-SKU-1',
            'Adjustment Product',
            $categoryId,
            'pcs',
            '1000.00',
            '1500.00',
            5,
            null,
            true,
        ));

        $this->warehouseId = (new MysqlWarehouseRepository($this->database))
            ->save(new Warehouse(null, 'Adjustment Warehouse', 'Jakarta', true));

        $users = new MysqlUserRepository($this->database);
        $staffId = $users->save(new User(
            null,
            'Adjustment Staff',
            'adjustment-staff@test.local',
            password_hash('Password123!', PASSWORD_DEFAULT),
            Role::WarehouseStaff,
            true,
        ));
        $staff = $users->findById($staffId);
        self::assertNotNull($staff);
        $this->staff = $staff;
    }

    // ------------------------------------------------ schema (research R-001)

    #[Test]
    public function anAdjustmentWithoutAReasonIsRejectedByTheDatabase(): void
    {
        $this->assertCheckViolation('Adjustment', 'Manual', null, null);
    }

    #[Test]
    public function aReceiptCarryingAReasonIsRejectedByTheDatabase(): void
    {
        $this->assertCheckViolation('Receipt', 'PurchaseOrder', 1, 'should not be here');
    }

    #[Test]
    public function anAdjustmentReferencingAnOrderIsRejectedByTheDatabase(): void
    {
        $this->assertCheckViolation('Adjustment', 'PurchaseOrder', 1, 'counted again');
    }

    #[Test]
    public function aManualAdjustmentWithAReasonIsAccepted(): void
    {
        $this->insertLedgerRow('Adjustment', 'Manual', null, self::REASON);

        $note = $this->pdo->query(
            'SELECT note FROM stock_ledger WHERE id = ' . (int) $this->pdo->lastInsertId()
        )?->fetchColumn();

        self::assertSame(self::REASON, $note);
    }

    // ---------------------------------- koreksi lewat StockService (US1)

    #[Test]
    public function correctionsChangeStockAndLedgerTogether(): void
    {
        $service = $this->stockService();

        self::assertSame(
            ['before' => 0, 'after' => 12, 'delta' => 12],
            $service->adjustStock($this->productId, $this->input('12', '0'), $this->staff),
        );
        self::assertSame(
            ['before' => 12, 'after' => 9, 'delta' => -3],
            $service->adjustStock($this->productId, $this->input('9', '12'), $this->staff),
        );

        self::assertSame(9, $this->stockQuantity());
        self::assertSame(2, $this->ledgerRows());

        // Invariant NFR-002 / SC-002: stock = jumlah seluruh pergerakannya.
        $ledger = new MysqlStockLedgerRepository($this->database);
        self::assertSame(9, $ledger->sumQuantity($this->productId, $this->warehouseId));

        $latest = $ledger->forProductAndWarehouse($this->productId, $this->warehouseId, 1)[0];
        self::assertSame(MovementType::Adjustment, $latest->movementType);
        self::assertSame(ReferenceType::Manual, $latest->referenceType);
        self::assertSame(-3, $latest->quantity);
        self::assertSame(self::REASON, $latest->note);
    }

    /** SC-004: setiap penolakan tidak mengubah stock maupun ledger. */
    #[Test]
    public function everyRefusalLeavesStockAndLedgerUnchanged(): void
    {
        $service = $this->stockService();
        $service->adjustStock($this->productId, $this->input('9', '0'), $this->staff);

        foreach ([['9', '9'], ['5', '12'], ['2.5', '9'], ['5', '9', '']] as $case) {
            try {
                $service->adjustStock($this->productId, $this->input(...$case), $this->staff);
                self::fail('Koreksi seharusnya ditolak: ' . implode(' / ', $case));
            } catch (ValidationException) {
                self::assertSame(9, $this->stockQuantity());
                self::assertSame(1, $this->ledgerRows());
            }
        }
    }

    /** FR-012: angka low-stock dan availability langsung mengikuti koreksi. */
    #[Test]
    public function lowStockAndAvailabilityReflectTheCorrection(): void
    {
        $products = new MysqlProductRepository($this->database);
        $criteria = ['search' => 'ADJUST-SKU-1', 'lowStock' => true];

        $this->stockService()->adjustStock($this->productId, $this->input('20', '0'), $this->staff);
        self::assertSame(0, $products->countBy($criteria));

        // Reorder point fixture = 5: hasil hitung 4 menjadikannya low stock.
        $this->stockService()->adjustStock($this->productId, $this->input('4', '20'), $this->staff);
        self::assertSame(1, $products->countBy($criteria));

        self::assertSame(4, $this->stockService()->availableFor($this->productId, $this->warehouseId));

        $productService = new ProductService(
            $products,
            new MysqlProductStockRepository($this->database),
            new MysqlCategoryRepository($this->database),
        );
        self::assertSame(4, $productService->availableQuantity($this->productId, $this->warehouseId));
    }

    // ------------------------------------- riwayat dan CSV (US2, FR-009/010)

    /**
     * Saldo setelah setiap koreksi dihitung MySQL dari seluruh pergerakan,
     * termasuk Receipt dan Issue di antaranya (research R-006).
     */
    #[Test]
    public function recentAdjustmentsCarryTheRunningBalanceFromTheWholeLedger(): void
    {
        $ledger = new MysqlStockLedgerRepository($this->database);
        $service = $this->stockService();
        $staffId = (int) $this->staff->id;

        $this->recordMovement(StockLedger::receipt($this->productId, $this->warehouseId, 12, 1, $staffId));
        $service->adjustStock($this->productId, $this->input('9', '12'), $this->staff);
        $this->recordMovement(StockLedger::issue($this->productId, $this->warehouseId, 2, 1, $staffId));
        $service->adjustStock($this->productId, $this->input('10', '7', 'found behind shelf'), $this->staff);

        $recent = $ledger->recentAdjustmentsForProduct($this->productId, 10);

        self::assertCount(2, $recent);
        self::assertSame([3, 10, 'found behind shelf'], [
            $recent[0]['quantity'],
            $recent[0]['balanceAfter'],
            $recent[0]['note'],
        ]);
        self::assertSame([-3, 9, self::REASON], [
            $recent[1]['quantity'],
            $recent[1]['balanceAfter'],
            $recent[1]['note'],
        ]);
        self::assertSame('Adjustment Warehouse', $recent[0]['warehouseName']);
        self::assertSame('Adjustment Staff', $recent[0]['performedByName']);
        self::assertCount(1, $ledger->recentAdjustmentsForProduct($this->productId, 1));
    }

    #[Test]
    public function theMovementExportCarriesTheReasonOnlyForAdjustments(): void
    {
        $staffId = (int) $this->staff->id;
        $this->recordMovement(StockLedger::receipt($this->productId, $this->warehouseId, 12, 1, $staffId));
        $this->stockService()->adjustStock($this->productId, $this->input('9', '12'), $this->staff);

        $today = date('Y-m-d');
        $rows = array_values(array_filter(
            (new MysqlStockLedgerRepository($this->database))->movementsBetween($today, $today),
            static fn (array $row): bool => $row['sku'] === 'ADJUST-SKU-1',
        ));

        self::assertCount(2, $rows);
        self::assertNull($rows[0]['note']);
        self::assertSame('Adjustment', $rows[1]['movement_type']);
        self::assertSame(self::REASON, $rows[1]['note']);
    }

    // ------------------------------------------ route table (FR-001)

    #[Test]
    public function bothAdjustRoutesAllowExactlyAdminAndWarehouseStaff(): void
    {
        $router = new Router();
        /** @var callable(Router): void $register */
        $register = require dirname(__DIR__, 2) . '/config/routes.php';
        $register($router);

        foreach (['GET', 'POST'] as $method) {
            $matched = $router->match($method, '/products/7/adjust-stock');

            self::assertSame('StockAdjustmentController', $matched['controller']);
            self::assertSame([Role::Admin, Role::WarehouseStaff], $matched['roles'], $method);
        }
    }

    #[Test]
    public function theGuardRefusesSalesAtTheAdjustRoute(): void
    {
        $router = new Router();
        /** @var callable(Router): void $register */
        $register = require dirname(__DIR__, 2) . '/config/routes.php';
        $register($router);

        $_SESSION = ['auth_user_id' => 99, 'auth_user_role' => Role::Sales->value, 'auth_user_name' => 'Sales'];

        try {
            $this->expectException(ForbiddenException::class);
            (new Authorization(new Session('IOMS_TEST_SESSION', false)))
                ->authorizeRoute($router->match('POST', '/products/7/adjust-stock')['roles']);
        } finally {
            $_SESSION = [];
        }
    }

    // ---------------------------------------------------------- helper

    /** Menulis pergerakan Receipt/Issue bersama stock-nya, seperti StockService. */
    private function recordMovement(StockLedger $entry): void
    {
        (new MysqlStockLedgerRepository($this->database))->append($entry);
        (new MysqlProductStockRepository($this->database))
            ->adjust($entry->productId, $entry->warehouseId, $entry->quantity);
    }

    private function stockService(): StockService
    {
        return new StockService(
            new MysqlSalesOrderRepository($this->database),
            new MysqlPurchaseOrderRepository($this->database),
            new MysqlProductStockRepository($this->database),
            new MysqlStockLedgerRepository($this->database),
            new MysqlProductRepository($this->database),
            new MysqlWarehouseRepository($this->database),
            $this->database,
        );
    }

    /** @return array<string, string> */
    private function input(string $counted, string $expected, string $note = self::REASON): array
    {
        return [
            'warehouse_id'      => (string) $this->warehouseId,
            'counted_quantity'  => $counted,
            'expected_quantity' => $expected,
            'note'              => $note,
        ];
    }

    private function stockQuantity(): int
    {
        return (new MysqlProductStockRepository($this->database))
            ->findFor($this->productId, $this->warehouseId)->quantity ?? 0;
    }

    private function ledgerRows(): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM stock_ledger WHERE product_id = :product_id');
        $statement->execute(['product_id' => $this->productId]);

        return (int) $statement->fetchColumn();
    }

    private function assertCheckViolation(
        string $movementType,
        string $referenceType,
        ?int $referenceId,
        ?string $note,
    ): void {
        try {
            $this->insertLedgerRow($movementType, $referenceType, $referenceId, $note);
        } catch (PDOException $e) {
            // Hanya pelanggaran CHECK yang dihitung. Error lain, misalnya kolom
            // `note` yang belum ada, berarti aturan schema-nya belum terpasang.
            self::assertSame(self::CHECK_VIOLATION, $e->errorInfo[1] ?? null, $e->getMessage());

            return;
        }

        self::fail('MySQL seharusnya menolak baris ' . $movementType . '/' . $referenceType);
    }

    private function insertLedgerRow(
        string $movementType,
        string $referenceType,
        ?int $referenceId,
        ?string $note,
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO stock_ledger (product_id, warehouse_id, movement_type, quantity,
                                       reference_type, reference_id, note, performed_by, created_at)
                  VALUES (:product_id, :warehouse_id, :movement_type, :quantity,
                          :reference_type, :reference_id, :note, :performed_by, NOW())'
        );

        $statement->execute([
            'product_id'     => $this->productId,
            'warehouse_id'   => $this->warehouseId,
            'movement_type'  => $movementType,
            'quantity'       => 3,
            'reference_type' => $referenceType,
            'reference_id'   => $referenceId,
            'note'           => $note,
            'performed_by'   => (int) $this->staff->id,
        ]);
    }
}
