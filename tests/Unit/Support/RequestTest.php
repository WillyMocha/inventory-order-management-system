<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Request;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * State query string untuk daftar ber-filter dan ber-sort (FIND-01).
 *
 * Request hanya dapat dibuat dari superglobal, jadi $_GET diisi per test lalu
 * dikembalikan. Tidak ada session, database, maupun network.
 */
final class RequestTest extends TestCase
{
    /** @var array<mixed> */
    private array $originalGet = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalGet = $_GET;
    }

    protected function tearDown(): void
    {
        $_GET = $this->originalGet;

        parent::tearDown();
    }

    // ------------------------------------------------------- queryState

    #[Test]
    public function queryStateKeepsOnlyTheRequestedKeysThatAreFilled(): void
    {
        $request = $this->requestWith([
            'search' => '  kabel  ',
            'status' => '',
            'page'   => '3',
            'other'  => 'ignored',
        ]);

        // Kosong dibuang, spasi dipangkas, dan key di luar daftar — termasuk
        // page, yang dikelola Paginator — tidak ikut terbawa ke link.
        self::assertSame(['search' => 'kabel'], $request->queryState(['search', 'status']));
    }

    #[Test]
    public function queryStateFollowsTheOrderOfTheRequestedKeys(): void
    {
        $request = $this->requestWith(['status' => 'Draft', 'search' => 'SO-1']);

        self::assertSame(
            ['search' => 'SO-1', 'status' => 'Draft'],
            $request->queryState(['search', 'status']),
        );
    }

    #[Test]
    public function queryStateIgnoresANonStringValueSuchAsAnArray(): void
    {
        // ?status[]=x tidak boleh berubah menjadi "Array" atau membuat error.
        $request = $this->requestWith(['status' => ['x'], 'search' => 'ok']);

        self::assertSame(['search' => 'ok'], $request->queryState(['search', 'status']));
    }

    // ------------------------------------------------------ sortCriteria

    #[Test]
    public function sortCriteriaRefusesAKeyOutsideTheAllowlist(): void
    {
        // Nama kolom tidak pernah diteruskan dari input user.
        $request = $this->requestWith(['sort' => 'password_hash', 'direction' => 'asc']);

        self::assertSame([], $request->sortCriteria(['date', 'number'], 'desc'));
    }

    #[Test]
    public function sortCriteriaAcceptsTheOppositeDirectionCaseInsensitively(): void
    {
        $request = $this->requestWith(['sort' => 'date', 'direction' => 'ASC']);

        self::assertSame(['sort' => 'date', 'direction' => 'asc'], $request->sortCriteria(['date'], 'desc'));
    }

    #[Test]
    public function sortCriteriaFallsBackToTheDefaultForAnyOtherDirection(): void
    {
        foreach (['', 'sideways', 'desc'] as $direction) {
            $request = $this->requestWith(['sort' => 'date', 'direction' => $direction]);

            self::assertSame(
                ['sort' => 'date', 'direction' => 'desc'],
                $request->sortCriteria(['date'], 'desc'),
                'direction "' . $direction . '"',
            );
        }
    }

    #[Test]
    public function sortCriteriaHonoursAnAscendingDefault(): void
    {
        // Daftar product urut naik secara default; order urut turun.
        $request = $this->requestWith(['sort' => 'name', 'direction' => 'nonsense']);

        self::assertSame(['sort' => 'name', 'direction' => 'asc'], $request->sortCriteria(['name'], 'asc'));
        self::assertSame(
            ['sort' => 'name', 'direction' => 'desc'],
            $this->requestWith(['sort' => 'name', 'direction' => 'desc'])->sortCriteria(['name'], 'asc'),
        );
    }

    /** @param array<string, mixed> $query */
    private function requestWith(array $query): Request
    {
        $_GET = $query;

        return Request::fromGlobals();
    }
}
