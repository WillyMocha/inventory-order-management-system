<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Entity\Enum\MovementType;
use App\Entity\Enum\ReferenceType;
use App\Entity\Enum\Role;
use App\Entity\Product;
use App\Entity\StockLedger;
use App\Entity\User;
use App\Entity\Warehouse;
use App\Service\StockService;
use App\Support\Exception\ForbiddenException;
use App\Support\Exception\NotFoundException;
use App\Support\Exception\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Fake\ImmediateTransactionRunner;
use Tests\Unit\Fake\InMemoryProductRepository;
use Tests\Unit\Fake\InMemoryProductStockRepository;
use Tests\Unit\Fake\InMemoryPurchaseOrderRepository;
use Tests\Unit\Fake\InMemorySalesOrderRepository;
use Tests\Unit\Fake\InMemoryStockLedgerRepository;
use Tests\Unit\Fake\InMemoryWarehouseRepository;

/**
 * Unit test koreksi stock — StockService::adjustStock() (spec 003).
 *
 * Yang diuji adalah ATURAN-nya: siapa yang boleh, input apa yang sah, kapan
 * koreksi ditolak, dan bahwa koreksi yang sah menulis tepat satu baris ledger
 * bersama perubahan stock-nya. Setiap penolakan juga memastikan stock dan
 * ledger tidak berubah sedikit pun.
 *
 * Perilaku lock-nya sendiri dibuktikan ConcurrentStockAdjustmentTest dengan
 * dua connection MySQL nyata — fake di sini berjalan single threaded.
 */
final class StockServiceAdjustmentTest extends TestCase
{
    private const int PRODUCT = 30;
    private const int INACTIVE_PRODUCT = 31;
    private const int WAREHOUSE = 20;
    private const int EMPTY_WAREHOUSE = 21;
    private const int INACTIVE_WAREHOUSE = 22;
    private const string REASON = '3 units water-damaged';
    private const string STALE_PREFIX = 'The stock in this warehouse changed to';

    private InMemoryProductStockRepository $stocks;
    private InMemoryStockLedgerRepository $ledger;
    private StockService $service;

    private User $admin;
    private User $warehouseStaff;
    private User $sales;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = new User(1, 'Admin One', 'admin@ioms.test', 'hash', Role::Admin, true);
        $this->warehouseStaff = new User(5, 'Warehouse One', 'wh1@ioms.test', 'hash', Role::WarehouseStaff, true);
        $this->sales = new User(3, 'Sales One', 'sales1@ioms.test', 'hash', Role::Sales, true);

        $this->stocks = new InMemoryProductStockRepository([
            self::PRODUCT . ':' . self::WAREHOUSE => 12,
            self::INACTIVE_PRODUCT . ':' . self::WAREHOUSE => 3,
        ]);
        $this->ledger = new InMemoryStockLedgerRepository();

        $this->service = new StockService(
            new InMemorySalesOrderRepository(),
            new InMemoryPurchaseOrderRepository(),
            $this->stocks,
            $this->ledger,
            new InMemoryProductRepository([
                new Product(self::PRODUCT, 'SKU-A', 'Product A', 1, 'pcs', '1000.00', '1500.00', 5, null, true),
                new Product(
                    self::INACTIVE_PRODUCT,
                    'SKU-B',
                    'Product B',
                    1,
                    'pcs',
                    '1000.00',
                    '1500.00',
                    5,
                    null,
                    false,
                ),
            ]),
            new InMemoryWarehouseRepository([
                new Warehouse(self::WAREHOUSE, 'Main Warehouse', 'Jakarta', true),
                new Warehouse(self::EMPTY_WAREHOUSE, 'Second Warehouse', 'Surabaya', true),
                new Warehouse(self::INACTIVE_WAREHOUSE, 'Closed Warehouse', 'Medan', false),
            ]),
            new ImmediateTransactionRunner(),
        );
    }

    // ------------------------------------------------------ recorded

    #[Test]
    public function aDecreaseWritesOneAdjustmentAndTheCountedQuantity(): void
    {
        $result = $this->service->adjustStock(self::PRODUCT, $this->input('9', '12'), $this->warehouseStaff);

        self::assertSame(['before' => 12, 'after' => 9, 'delta' => -3], $result);
        self::assertSame(9, $this->quantity(self::PRODUCT, self::WAREHOUSE));

        $entries = $this->ledger->all();
        self::assertCount(1, $entries);
        self::assertSame(MovementType::Adjustment, $entries[0]->movementType);
        self::assertSame(ReferenceType::Manual, $entries[0]->referenceType);
        self::assertNull($entries[0]->referenceId);
        self::assertSame(-3, $entries[0]->quantity);
        self::assertSame(self::REASON, $entries[0]->note);
        self::assertSame(5, $entries[0]->performedBy);
    }

    #[Test]
    public function anIncreaseByAnAdminWritesAPositiveAdjustment(): void
    {
        $result = $this->service->adjustStock(self::PRODUCT, $this->input('20', '12'), $this->admin);

        self::assertSame(['before' => 12, 'after' => 20, 'delta' => 8], $result);
        self::assertSame(8, $this->ledger->all()[0]->quantity);
    }

    #[Test]
    public function aWarehouseNeverStockedStartsFromZero(): void
    {
        $result = $this->service->adjustStock(
            self::PRODUCT,
            $this->input('5', '0', self::REASON, (string) self::EMPTY_WAREHOUSE),
            $this->admin,
        );

        self::assertSame(['before' => 0, 'after' => 5, 'delta' => 5], $result);
        self::assertSame(5, $this->quantity(self::PRODUCT, self::EMPTY_WAREHOUSE));
    }

    #[Test]
    public function anInactiveProductCanStillBeCorrected(): void
    {
        $this->service->adjustStock(self::INACTIVE_PRODUCT, $this->input('0', '3'), $this->warehouseStaff);

        self::assertSame(0, $this->quantity(self::INACTIVE_PRODUCT, self::WAREHOUSE));
    }

    #[Test]
    public function theReasonIsStoredTrimmed(): void
    {
        $this->service->adjustStock(self::PRODUCT, $this->input('9', '12', '  damaged  '), $this->admin);

        self::assertSame('damaged', $this->ledger->all()[0]->note);
    }

    /** Batas 255 karakter berlaku untuk alasan yang disimpan, yaitu setelah trim. */
    #[Test]
    public function theLengthLimitAppliesToTheTrimmedReason(): void
    {
        $note = str_repeat('x', 255);

        $this->service->adjustStock(self::PRODUCT, $this->input('9', '12', '  ' . $note . '  '), $this->admin);

        self::assertSame($note, $this->ledger->all()[0]->note);
    }

    // ------------------------------------------------------- refused

    #[Test]
    public function salesCannotCorrectStock(): void
    {
        $this->expectRefusal(
            fn () => $this->service->adjustStock(self::PRODUCT, $this->input('9', '12'), $this->sales),
            ForbiddenException::class,
        );
    }

    #[Test]
    public function anInvalidCountedQuantityIsAFieldError(): void
    {
        foreach (['-1', '2.5', 'abc', ''] as $counted) {
            $errors = $this->validationErrors($this->input($counted, '12'));

            self::assertArrayHasKey('counted_quantity', $errors, 'counted = "' . $counted . '"');
        }
    }

    #[Test]
    public function aMissingOrInvalidExpectedQuantityIsAFieldError(): void
    {
        foreach (['', 'x', '-1'] as $expected) {
            $errors = $this->validationErrors($this->input('9', $expected));

            self::assertArrayHasKey('expected_quantity', $errors, 'expected = "' . $expected . '"');
        }
    }

    #[Test]
    public function aMissingOrTooLongReasonIsAFieldError(): void
    {
        foreach (['', '   ', str_repeat('x', 256)] as $note) {
            self::assertArrayHasKey('note', $this->validationErrors($this->input('9', '12', $note)));
        }
    }

    #[Test]
    public function aMissingUnknownOrInactiveWarehouseIsAFieldError(): void
    {
        foreach (['', '999', (string) self::INACTIVE_WAREHOUSE] as $warehouse) {
            $errors = $this->validationErrors($this->input('9', '12', self::REASON, $warehouse));

            self::assertArrayHasKey('warehouse_id', $errors, 'warehouse = "' . $warehouse . '"');
        }
    }

    #[Test]
    public function anUnknownProductIsNotFound(): void
    {
        $this->expectRefusal(
            fn () => $this->service->adjustStock(999, $this->input('9', '12'), $this->admin),
            NotFoundException::class,
        );
    }

    /**
     * Stock berubah setelah user melihatnya (misalnya ada goods issue di
     * tengah penghitungan): koreksi ditolak, bukan diterapkan buta (FR-004).
     */
    #[Test]
    public function aStaleExpectedQuantityIsRefusedWithTheCurrentQuantity(): void
    {
        $errors = $this->validationErrors($this->input('9', '14'));

        self::assertArrayHasKey('stock', $errors);
        self::assertStringStartsWith(self::STALE_PREFIX . ' 12 ', $errors['stock']);
    }

    #[Test]
    public function aCountEqualToTheSystemQuantityIsRefused(): void
    {
        $errors = $this->validationErrors($this->input('12', '12'));

        self::assertSame(
            ['counted_quantity' => 'The count matches the system quantity — nothing to adjust.'],
            $errors,
        );
    }

    // ------------------------------------- riwayat koreksi (US2, FR-009)

    #[Test]
    public function recentAdjustmentsAreNewestFirstWithTheResultingQuantity(): void
    {
        $this->ledger->withLabels(
            [],
            [],
            [self::WAREHOUSE => 'Main Warehouse'],
            [1 => 'Admin One', 5 => 'Warehouse One'],
        );

        // Seluruh stock awal juga tercatat di ledger, agar saldo berjalan sah.
        $this->ledger->recordAt('2026-03-01 08:00:00');
        $this->ledger->append(StockLedger::receipt(self::PRODUCT, self::WAREHOUSE, 12, 1, 1));

        $this->ledger->recordAt('2026-03-02 09:00:00');
        $this->service->adjustStock(self::PRODUCT, $this->input('9', '12'), $this->warehouseStaff);

        $this->ledger->recordAt('2026-03-03 10:00:00');
        $this->ledger->append(StockLedger::issue(self::PRODUCT, self::WAREHOUSE, 2, 1, 5));
        $this->stocks->adjust(self::PRODUCT, self::WAREHOUSE, -2);

        $this->ledger->recordAt('2026-03-04 11:00:00');
        $this->service->adjustStock(self::PRODUCT, $this->input('10', '7', 'found behind shelf'), $this->admin);

        // Koreksi product lain tidak ikut.
        $this->service->adjustStock(self::INACTIVE_PRODUCT, $this->input('1', '3'), $this->admin);

        self::assertSame([
            [
                'createdAt'       => '2026-03-04 11:00:00',
                'warehouseName'   => 'Main Warehouse',
                'quantity'        => 3,
                'balanceAfter'    => 10,
                'performedByName' => 'Admin One',
                'note'            => 'found behind shelf',
            ],
            [
                'createdAt'       => '2026-03-02 09:00:00',
                'warehouseName'   => 'Main Warehouse',
                'quantity'        => -3,
                'balanceAfter'    => 9,
                'performedByName' => 'Warehouse One',
                'note'            => self::REASON,
            ],
        ], $this->service->recentAdjustments(self::PRODUCT));
    }

    #[Test]
    public function recentAdjustmentsAreLimitedToTheTenNewest(): void
    {
        $quantity = 12;

        for ($i = 1; $i <= 12; $i++) {
            $this->ledger->recordAt(sprintf('2026-03-%02d 08:00:00', $i));
            $input = $this->input((string) ($quantity + 1), (string) $quantity);
            $this->service->adjustStock(self::PRODUCT, $input, $this->admin);
            $quantity++;
        }

        $recent = $this->service->recentAdjustments(self::PRODUCT);

        self::assertCount(10, $recent);
        self::assertSame('2026-03-12 08:00:00', $recent[0]['createdAt']);
        self::assertSame('2026-03-03 08:00:00', $recent[9]['createdAt']);
    }

    #[Test]
    public function aProductWithoutAdjustmentsHasAnEmptyHistory(): void
    {
        self::assertSame([], $this->service->recentAdjustments(self::PRODUCT));
    }

    // -------------------------------------------------------- helper

    /** @return array<string, string> */
    private function input(
        string $counted,
        string $expected,
        string $note = self::REASON,
        ?string $warehouse = null,
    ): array {
        return [
            'warehouse_id'      => $warehouse ?? (string) self::WAREHOUSE,
            'counted_quantity'  => $counted,
            'expected_quantity' => $expected,
            'note'              => $note,
        ];
    }

    /**
     * Menjalankan koreksi yang harus ditolak dengan ValidationException dan
     * memastikan tidak ada apa pun yang berubah.
     *
     * @param array<string, string> $input
     * @return array<string, string>
     */
    private function validationErrors(array $input): array
    {
        $errors = [];

        $this->expectRefusal(
            function () use ($input, &$errors): void {
                try {
                    $this->service->adjustStock(self::PRODUCT, $input, $this->admin);
                } catch (ValidationException $e) {
                    $errors = $e->errors();

                    throw $e;
                }
            },
            ValidationException::class,
        );

        return $errors;
    }

    /**
     * @param callable(): mixed $action
     * @param class-string<\Throwable> $exception
     */
    private function expectRefusal(callable $action, string $exception): void
    {
        $before = [
            $this->quantity(self::PRODUCT, self::WAREHOUSE),
            $this->stocks->findFor(self::PRODUCT, self::EMPTY_WAREHOUSE)?->quantity,
            $this->ledger->count(),
        ];

        try {
            $action();
            self::fail('Koreksi seharusnya ditolak dengan ' . $exception);
        } catch (\Throwable $e) {
            self::assertInstanceOf($exception, $e);
        }

        self::assertSame($before, [
            $this->quantity(self::PRODUCT, self::WAREHOUSE),
            $this->stocks->findFor(self::PRODUCT, self::EMPTY_WAREHOUSE)?->quantity,
            $this->ledger->count(),
        ], 'Stock dan ledger tidak boleh berubah saat koreksi ditolak');
    }

    private function quantity(int $productId, int $warehouseId): int
    {
        return $this->stocks->findFor($productId, $warehouseId)->quantity ?? 0;
    }
}
