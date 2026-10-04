<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/**
 * Database::transaction() bersarang memakai SAVEPOINT (tech-debt TD-1).
 *
 * Test ini sengaja BERJALAN di dalam pembungkus transaction IntegrationTestCase:
 * itulah keadaan yang dulu membuat rollback milik Service tidak pernah terjadi.
 */
final class NestedTransactionTest extends IntegrationTestCase
{
    #[Test]
    public function aFailedNestedTransactionIsUndoneWhileTheOuterOneSurvives(): void
    {
        $before = $this->categoryCount();
        $raised = null;

        try {
            $this->database->transaction(function (): void {
                $this->insertCategory('Nested Failure');

                throw new RuntimeException('forced');
            });
        } catch (RuntimeException $e) {
            $raised = $e->getMessage();
        }

        self::assertSame('forced', $raised, 'Exception dari callback harus naik ke pemanggil');
        self::assertSame($before, $this->categoryCount(), 'Penulisan nested call harus dibatalkan');
        self::assertTrue($this->pdo->inTransaction(), 'Transaction terluar tidak boleh ikut berakhir');
    }

    #[Test]
    public function aSuccessfulNestedTransactionIsKeptButNotCommitted(): void
    {
        $before = $this->categoryCount();

        $result = $this->database->transaction(function (): string {
            $this->insertCategory('Nested Success');

            return 'done';
        });

        self::assertSame('done', $result);
        self::assertSame($before + 1, $this->categoryCount());
        // Masih di dalam transaction terluar: rollback harness akan membuangnya.
        self::assertTrue($this->pdo->inTransaction());
    }

    #[Test]
    public function onlyTheFailingLevelIsUndoneWhenNestedTwice(): void
    {
        $before = $this->categoryCount();

        $this->database->transaction(function (): void {
            $this->insertCategory('Level One');

            try {
                $this->database->transaction(function (): void {
                    $this->insertCategory('Level Two');

                    throw new RuntimeException('inner');
                });
            } catch (RuntimeException) {
                // Kegagalan level dua ditangani; level satu berlanjut.
            }
        });

        self::assertSame($before + 1, $this->categoryCount(), 'Hanya penulisan level dua yang dibatalkan');
        self::assertSame(1, $this->countByName('Level One'));
        self::assertSame(0, $this->countByName('Level Two'));
    }

    private function insertCategory(string $name): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO category (name, description, created_at, updated_at)
                  VALUES (:name, NULL, NOW(), NOW())',
        );
        $statement->execute(['name' => 'TD1 ' . $name]);
    }

    private function categoryCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM category')->fetchColumn();
    }

    private function countByName(string $name): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM category WHERE name = :name');
        $statement->execute(['name' => 'TD1 ' . $name]);

        return (int) $statement->fetchColumn();
    }
}
