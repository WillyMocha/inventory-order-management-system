<?php

declare(strict_types=1);

namespace Tests\Integration;

final class HarnessSmokeTest extends IntegrationTestCase
{
    public function testConnectsToTestDatabaseAndRollsBack(): void
    {
        $db = $this->pdo->query('SELECT DATABASE()')?->fetchColumn();
        self::assertSame('ioms_test', $db, 'Integration test harus memakai database test terpisah');
        self::assertTrue($this->pdo->inTransaction(), 'Setiap test harus dibungkus transaction');
    }

    public function testSecondConnectionIsIndependent(): void
    {
        $other = $this->newSeparateConnection();
        self::assertFalse($other->inTransaction(), 'Connection kedua harus terpisah dari yang pertama');
    }
}
