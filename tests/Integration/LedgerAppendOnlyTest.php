<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Entity\StockLedger;
use App\Repository\Mysql\MysqlStockLedgerRepository;
use PDOException;
use PHPUnit\Framework\Attributes\Test;

/**
 * stock_ledger append-only dijaga MySQL sendiri (tech-debt TD-9,
 * database/005_ledger_append_only.sql).
 *
 * Sebelumnya hanya konvensi aplikasi yang menahan UPDATE dan DELETE. Test di
 * sini membuktikan bahwa SQL langsung dengan user aplikasi pun kini ditolak
 * trigger, sementara penulisan ledger yang sah (INSERT) tetap berjalan.
 */
final class LedgerAppendOnlyTest extends IntegrationTestCase
{
    use SalesOrderFixtures;

    /** SQLSTATE yang dilempar SIGNAL pada trigger. */
    private const string SIGNALLED = '45000';

    private int $ledgerId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedSalesOrderFixtures();
        $this->ledgerId = (new MysqlStockLedgerRepository($this->database))->append(
            StockLedger::adjustment($this->productId, $this->warehouseId, 5, 'Fixture count', (int) $this->admin->id),
        );
    }

    #[Test]
    public function bothTriggersExist(): void
    {
        $names = $this->pdo->query(
            "SELECT TRIGGER_NAME FROM information_schema.TRIGGERS
              WHERE EVENT_OBJECT_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = 'stock_ledger'
              ORDER BY TRIGGER_NAME",
        )?->fetchAll(\PDO::FETCH_COLUMN);

        self::assertSame(['trg_stock_ledger_no_delete', 'trg_stock_ledger_no_update'], $names);
    }

    #[Test]
    public function anUpdateIsRefusedByTheDatabase(): void
    {
        $this->assertSignalled(
            'UPDATE stock_ledger SET quantity = 999 WHERE id = ' . $this->ledgerId,
            'rows cannot be updated',
        );

        self::assertSame(5, $this->ledgerQuantity());
    }

    #[Test]
    public function anUpdateIsRefusedEvenWithTheCleanupFlag(): void
    {
        // Pengecualian pembersihan fixture hanya berlaku untuk DELETE; riwayat
        // stock tidak pernah boleh ditulis ulang.
        $this->pdo->exec('SET @ioms_allow_ledger_cleanup = 1');

        try {
            $this->assertSignalled(
                'UPDATE stock_ledger SET quantity = 999 WHERE id = ' . $this->ledgerId,
                'rows cannot be updated',
            );
        } finally {
            $this->pdo->exec('SET @ioms_allow_ledger_cleanup = NULL');
        }
    }

    #[Test]
    public function aDeleteIsRefusedByTheDatabase(): void
    {
        $this->assertSignalled(
            'DELETE FROM stock_ledger WHERE id = ' . $this->ledgerId,
            'rows cannot be deleted',
        );

        self::assertSame(5, $this->ledgerQuantity());
    }

    #[Test]
    public function onlyAnExplicitCleanupSessionMayDelete(): void
    {
        $this->pdo->exec('SET @ioms_allow_ledger_cleanup = 1');

        try {
            $deleted = $this->pdo->exec('DELETE FROM stock_ledger WHERE id = ' . $this->ledgerId);
        } finally {
            $this->pdo->exec('SET @ioms_allow_ledger_cleanup = NULL');
        }

        self::assertSame(1, $deleted);
    }

    #[Test]
    public function appendingStillWorks(): void
    {
        $before = $this->ledgerRowCount();

        (new MysqlStockLedgerRepository($this->database))->append(
            StockLedger::adjustment($this->productId, $this->warehouseId, -2, 'Second count', (int) $this->admin->id),
        );

        self::assertSame($before + 1, $this->ledgerRowCount());
    }

    // ---------------------------------------------------------- helper

    private function assertSignalled(string $sql, string $message): void
    {
        $thrown = null;

        try {
            $this->pdo->exec($sql);
        } catch (PDOException $e) {
            $thrown = $e;
        }

        self::assertNotNull($thrown, 'Trigger seharusnya menolak: ' . $sql);
        self::assertSame(self::SIGNALLED, $thrown->getCode());
        self::assertStringContainsString($message, $thrown->getMessage());
    }

    private function ledgerQuantity(): int
    {
        $sql = 'SELECT quantity FROM stock_ledger WHERE id = ' . $this->ledgerId;

        return (int) $this->pdo->query($sql)?->fetchColumn();
    }
}
