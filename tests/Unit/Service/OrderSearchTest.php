<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Entity\Enum\PurchaseOrderStatus;
use App\Entity\Enum\SalesOrderStatus;
use App\Entity\PurchaseOrder;
use App\Entity\SalesOrder;
use App\Repository\Mysql\MysqlProductRepository;
use App\Repository\Mysql\MysqlPurchaseOrderRepository;
use App\Repository\Mysql\MysqlSalesOrderRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClassConstant;
use Tests\Unit\Fake\InMemoryPurchaseOrderRepository;
use Tests\Unit\Fake\InMemorySalesOrderRepository;
use Tests\Unit\Fake\SortAllowlistProbe;

/**
 * Unit test pencarian dan pengurutan order (FIND-01, FR-024).
 *
 * Fokus utamanya keamanan: nama kolom TIDAK dapat di-bind sebagai parameter,
 * sehingga sort adalah satu-satunya tempat di seluruh aplikasi yang nilainya
 * masuk ke string SQL. Karena itu key sort harus lewat allowlist, dan itulah
 * yang diuji di sini — bersama pemeriksaan bahwa setiap kolom di dalam
 * allowlist memang identifier yang aman (security standard §5).
 */
final class OrderSearchTest extends TestCase
{
    /** Nama kolom yang sah: huruf kecil, underscore, opsional satu prefix tabel. */
    private const string SAFE_COLUMN = '/^[a-z_]+(\.[a-z_]+)?$/';

    private SortAllowlistProbe $probe;

    /** @var array<string, string> */
    private array $allowed;

    protected function setUp(): void
    {
        parent::setUp();

        $this->probe = new SortAllowlistProbe();
        $this->allowed = [
            'date'   => 'order_date',
            'number' => 'order_number',
            'status' => 'status',
        ];
    }

    // ------------------------------------------------------ sort allowlist

    #[Test]
    public function anAllowlistedKeyMapsToItsColumn(): void
    {
        self::assertSame('order_date', $this->probe->resolve($this->allowed, 'date', 'id'));
        self::assertSame('order_number', $this->probe->resolve($this->allowed, 'number', 'id'));
        self::assertSame('status', $this->probe->resolve($this->allowed, 'status', 'id'));
    }

    #[Test]
    public function anArbitraryColumnNameIsRejected(): void
    {
        // Kolom yang benar-benar ada di tabel pun harus ditolak bila tidak
        // terdaftar — allowlist, bukan denylist.
        self::assertSame('order_date', $this->probe->resolve($this->allowed, 'created_by', 'order_date'));
        self::assertSame('order_date', $this->probe->resolve($this->allowed, 'approved_by', 'order_date'));
    }

    #[Test]
    public function aMissingSortKeyFallsBackToTheDefault(): void
    {
        self::assertSame('order_date', $this->probe->resolve($this->allowed, null, 'order_date'));
        self::assertSame('order_date', $this->probe->resolve($this->allowed, '', 'order_date'));
    }

    #[Test]
    public function anInjectionAttemptIsNeverReturned(): void
    {
        $payloads = [
            "order_date; DROP TABLE sales_order",
            "order_date, (SELECT password_hash FROM `user` LIMIT 1)",
            "1=1",
            "order_date' OR '1'='1",
            "order_date/*",
            "(CASE WHEN 1=1 THEN order_date ELSE id END)",
            "order_date UNION SELECT 1",
            "../../etc/passwd",
            "status`",
        ];

        foreach ($payloads as $payload) {
            $resolved = $this->probe->resolve($this->allowed, $payload, 'order_date');

            // Yang penting bukan sekadar "tidak sama dengan payload", tetapi
            // bahwa hasilnya SELALU salah satu kolom dari allowlist.
            self::assertSame(
                'order_date',
                $resolved,
                'Payload harus jatuh ke fallback: ' . $payload,
            );
            self::assertMatchesRegularExpression(self::SAFE_COLUMN, $resolved);
        }
    }

    #[Test]
    public function theResolvedColumnIsAlwaysASafeIdentifier(): void
    {
        // Termasuk untuk key yang sah — kalau suatu saat ada yang menambahkan
        // ekspresi ke dalam allowlist, test ini yang menangkapnya.
        foreach (array_keys($this->allowed) as $key) {
            self::assertMatchesRegularExpression(
                self::SAFE_COLUMN,
                $this->probe->resolve($this->allowed, $key, 'id'),
            );
        }
    }

    // ---------------------------------------------------------- direction

    #[Test]
    public function bothSortDirectionsAreSupported(): void
    {
        self::assertSame('ASC', $this->probe->direction('asc'));
        self::assertSame('DESC', $this->probe->direction('desc'));
    }

    #[Test]
    public function theDirectionComparisonIsCaseInsensitive(): void
    {
        self::assertSame('ASC', $this->probe->direction('ASC'));
        self::assertSame('ASC', $this->probe->direction('Asc'));
    }

    #[Test]
    public function anUnrecognisedDirectionBecomesDescending(): void
    {
        foreach ([null, '', 'sideways', 'ASC; DROP TABLE sales_order', 'asc desc'] as $requested) {
            self::assertSame(
                'DESC',
                $this->probe->direction($requested),
                'Direction yang tidak dikenal harus menjadi DESC',
            );
        }
    }

    #[Test]
    public function theDirectionIsOnlyEverOneOfTwoLiterals(): void
    {
        foreach ([null, 'asc', 'desc', 'ASC', 'garbage', '1'] as $requested) {
            self::assertContains($this->probe->direction($requested), ['ASC', 'DESC']);
        }
    }

    // ------------------------------- allowlists of the real repositories

    /**
     * Allowlist yang sesungguhnya dipakai repository harus berisi identifier
     * aman saja. Dibaca lewat reflection karena const-nya private — dan
     * private memang benar; yang perlu diuji adalah isinya, bukan aksesnya.
     */
    #[Test]
    public function everyRealRepositoryAllowlistContainsOnlySafeIdentifiers(): void
    {
        $repositories = [
            MysqlSalesOrderRepository::class,
            MysqlPurchaseOrderRepository::class,
            MysqlProductRepository::class,
        ];

        foreach ($repositories as $repository) {
            $allowlist = (new ReflectionClassConstant($repository, 'SORTABLE'))->getValue();

            self::assertIsArray($allowlist);
            self::assertNotEmpty($allowlist, $repository . ' harus mendeklarasikan sort key-nya');

            foreach ($allowlist as $key => $column) {
                self::assertIsString($key);
                self::assertIsString($column);
                self::assertMatchesRegularExpression(
                    self::SAFE_COLUMN,
                    $column,
                    $repository . ' memetakan "' . $key . '" ke kolom yang tidak aman: ' . $column,
                );
                // Key juga muncul di URL, jadi harus sederhana.
                self::assertMatchesRegularExpression('/^[a-z]+$/', $key);
            }
        }
    }

    // --------------------------------------------- criteria filtering (SO)

    #[Test]
    public function salesOrdersCanBeFilteredByStatus(): void
    {
        $orders = new InMemorySalesOrderRepository([
            $this->salesOrder(1, 'SO-0001', SalesOrderStatus::Draft, 3),
            $this->salesOrder(2, 'SO-0002', SalesOrderStatus::Approved, 3),
            $this->salesOrder(3, 'SO-0003', SalesOrderStatus::Approved, 4),
        ]);

        $approved = $orders->search(['status' => SalesOrderStatus::Approved->value], 10, 0);

        self::assertCount(2, $approved);
        self::assertSame(2, $orders->countBy(['status' => SalesOrderStatus::Approved->value]));
    }

    #[Test]
    public function salesOrdersCanBeFoundByPartialOrderNumber(): void
    {
        $orders = new InMemorySalesOrderRepository([
            $this->salesOrder(1, 'SO-20260911-0001', SalesOrderStatus::Draft, 3),
            $this->salesOrder(2, 'SO-20260912-0002', SalesOrderStatus::Draft, 3),
        ]);

        self::assertCount(2, $orders->search(['search' => 'SO-2026'], 10, 0));
        self::assertCount(1, $orders->search(['search' => '0911'], 10, 0));
        self::assertCount(0, $orders->search(['search' => 'PO-'], 10, 0));
    }

    #[Test]
    public function theOwnershipScopeCombinesWithAStatusFilter(): void
    {
        // Kombinasi inilah yang dipakai halaman Sales: miliknya sendiri DAN
        // berstatus tertentu.
        $orders = new InMemorySalesOrderRepository([
            $this->salesOrder(1, 'SO-0001', SalesOrderStatus::Approved, 3),
            $this->salesOrder(2, 'SO-0002', SalesOrderStatus::Draft, 3),
            $this->salesOrder(3, 'SO-0003', SalesOrderStatus::Approved, 4),
        ]);

        $mine = $orders->search(
            ['status' => SalesOrderStatus::Approved->value, 'createdBy' => 3],
            10,
            0,
        );

        self::assertCount(1, $mine);
        self::assertSame(1, $mine[0]->id);
    }

    #[Test]
    public function countAndSearchAgreeUnderTheSameCriteria(): void
    {
        // Kalau keduanya tidak sepakat, jumlah halaman pagination akan salah.
        $orders = new InMemorySalesOrderRepository([
            $this->salesOrder(1, 'SO-0001', SalesOrderStatus::Draft, 3),
            $this->salesOrder(2, 'SO-0002', SalesOrderStatus::Draft, 3),
            $this->salesOrder(3, 'SO-0003', SalesOrderStatus::Approved, 4),
        ]);

        $criteria = ['status' => SalesOrderStatus::Draft->value];

        self::assertSame(
            $orders->countBy($criteria),
            count($orders->search($criteria, 100, 0)),
        );
    }

    #[Test]
    public function pagingASalesOrderListDoesNotRepeatOrSkipRows(): void
    {
        $seed = [];
        for ($i = 1; $i <= 25; $i++) {
            $seed[] = $this->salesOrder($i, sprintf('SO-%04d', $i), SalesOrderStatus::Draft, 3);
        }

        $orders = new InMemorySalesOrderRepository($seed);

        $seen = [];
        foreach ([0, 10, 20] as $offset) {
            foreach ($orders->search([], 10, $offset) as $order) {
                $seen[] = (int) $order->id;
            }
        }

        self::assertCount(25, $seen);
        self::assertSame($seen, array_unique($seen), 'Tidak boleh ada baris yang muncul dua kali');
    }

    // --------------------------------------------- criteria filtering (PO)

    #[Test]
    public function purchaseOrdersCanBeFilteredByStatus(): void
    {
        $orders = new InMemoryPurchaseOrderRepository([
            $this->purchaseOrder(1, 'PO-0001', PurchaseOrderStatus::Draft),
            $this->purchaseOrder(2, 'PO-0002', PurchaseOrderStatus::Ordered),
            $this->purchaseOrder(3, 'PO-0003', PurchaseOrderStatus::Received),
        ]);

        self::assertCount(1, $orders->search(['status' => PurchaseOrderStatus::Ordered->value], 10, 0));
        self::assertSame(1, $orders->countBy(['status' => PurchaseOrderStatus::Received->value]));
    }

    #[Test]
    public function purchaseOrdersCanBeFoundByPartialOrderNumber(): void
    {
        $orders = new InMemoryPurchaseOrderRepository([
            $this->purchaseOrder(1, 'PO-20260911-0001', PurchaseOrderStatus::Draft),
            $this->purchaseOrder(2, 'PO-20260912-0002', PurchaseOrderStatus::Draft),
        ]);

        self::assertCount(2, $orders->search(['search' => 'PO-2026'], 10, 0));
        self::assertCount(1, $orders->search(['search' => '0912'], 10, 0));
    }

    #[Test]
    public function anUnmatchedFilterReturnsNothingRatherThanEverything(): void
    {
        // Filter yang tidak cocok harus mengosongkan hasil; kalau justru
        // mengembalikan semua, user akan menganggap filternya bekerja.
        $orders = new InMemoryPurchaseOrderRepository([
            $this->purchaseOrder(1, 'PO-0001', PurchaseOrderStatus::Draft),
        ]);

        self::assertSame([], $orders->search(['search' => 'tidak-ada'], 10, 0));
        self::assertSame(0, $orders->countBy(['search' => 'tidak-ada']));
    }

    // ----------------------------------------------------------- helpers

    private function salesOrder(int $id, string $number, SalesOrderStatus $status, int $createdBy): SalesOrder
    {
        return new SalesOrder($id, $number, 10, $createdBy, null, 20, $status, '2026-09-11', []);
    }

    private function purchaseOrder(int $id, string $number, PurchaseOrderStatus $status): PurchaseOrder
    {
        return new PurchaseOrder($id, $number, 40, 20, $status, '2026-09-11', 5, []);
    }
}
