<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Paginator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit test Paginator (FIND-01, FR-025).
 *
 * Dua hal yang dijaga di sini: aritmetika halaman harus benar, dan SELURUH
 * filter yang sedang aktif harus terbawa pada setiap link halaman — kalau
 * filter hilang saat berpindah halaman, hasil pencarian ikut hilang dan
 * halamannya tidak dapat dibagikan lewat URL.
 */
final class PaginatorTest extends TestCase
{
    #[Test]
    public function tenRowsPerPageIsTheDefault(): void
    {
        self::assertSame(10, (new Paginator(0, 1))->perPage());
        self::assertSame(10, Paginator::DEFAULT_PER_PAGE);
    }

    // ------------------------------------------------------ page counting

    #[Test]
    public function thirtyItemsMakeThreePages(): void
    {
        self::assertSame(3, (new Paginator(30, 1))->totalPages());
    }

    #[Test]
    public function aPartialLastPageStillCounts(): void
    {
        // 25 baris = 2 halaman penuh + 1 halaman berisi 5.
        self::assertSame(3, (new Paginator(25, 1))->totalPages());
    }

    #[Test]
    public function anEmptyResultStillHasOnePage(): void
    {
        // Nol halaman akan membuat link pagination hilang seluruhnya dan
        // halamannya tampak rusak; satu halaman kosong lebih benar.
        $paginator = new Paginator(0, 1);

        self::assertSame(1, $paginator->totalPages());
        self::assertSame(1, $paginator->currentPage());
        self::assertFalse($paginator->hasNext());
        self::assertFalse($paginator->hasPrevious());
    }

    #[Test]
    public function exactlyOneFullPageDoesNotProduceASecond(): void
    {
        self::assertSame(1, (new Paginator(10, 1))->totalPages());
        self::assertFalse((new Paginator(10, 1))->hasNext());
    }

    // ------------------------------------------------------------ offsets

    #[Test]
    public function offsetsFollowThePageNumber(): void
    {
        self::assertSame(0, (new Paginator(30, 1))->offset());
        self::assertSame(10, (new Paginator(30, 2))->offset());
        self::assertSame(20, (new Paginator(30, 3))->offset());
    }

    #[Test]
    public function aPageBeyondTheLastIsClampedToTheLast(): void
    {
        // ?page=99 harus menampilkan halaman terakhir, bukan halaman kosong
        // atau offset di luar rentang.
        $paginator = new Paginator(30, 99);

        self::assertSame(3, $paginator->currentPage());
        self::assertSame(20, $paginator->offset());
    }

    #[Test]
    public function aPageBelowOneIsClampedToOne(): void
    {
        foreach ([0, -5] as $requested) {
            $paginator = new Paginator(30, $requested);

            self::assertSame(1, $paginator->currentPage());
            self::assertSame(0, $paginator->offset(), 'Offset negatif akan membuat query gagal');
        }
    }

    #[Test]
    public function theOffsetIsNeverNegativeForAnEmptyResult(): void
    {
        self::assertSame(0, (new Paginator(0, 3))->offset());
    }

    // ---------------------------------------------- filters in page links

    #[Test]
    public function everyActiveFilterIsPreservedInAPageLink(): void
    {
        $paginator = new Paginator(30, 1, [
            'search'   => 'widget',
            'category' => '4',
            'stock'    => 'low',
        ]);

        $url = $paginator->urlForPage('/products', 2);

        self::assertStringStartsWith('/products?', $url);

        // Diperiksa lewat parse, bukan lewat urutan string — urutan parameter
        // bukan bagian dari kontraknya.
        $query = $this->queryOf($url);

        self::assertSame('widget', $query['search'] ?? null);
        self::assertSame('4', $query['category'] ?? null);
        self::assertSame('low', $query['stock'] ?? null);
        self::assertSame('2', $query['page'] ?? null);
    }

    #[Test]
    public function theSortAndDirectionSurviveAPageChange(): void
    {
        // Kalau sort hilang saat berpindah halaman, halaman kedua akan
        // menampilkan urutan yang berbeda dari halaman pertama.
        $paginator = new Paginator(30, 1, ['sort' => 'date', 'direction' => 'asc']);

        $query = $this->queryOf($paginator->urlForPage('/sales-orders', 3));

        self::assertSame('date', $query['sort'] ?? null);
        self::assertSame('asc', $query['direction'] ?? null);
        self::assertSame('3', $query['page'] ?? null);
    }

    #[Test]
    public function aFilterValueNeedingEncodingIsEncoded(): void
    {
        $paginator = new Paginator(30, 1, ['search' => 'a&b c=d']);

        $url = $paginator->urlForPage('/products', 2);

        // Nilai mentah tidak boleh muncul apa adanya, kalau tidak query
        // string-nya pecah.
        self::assertStringNotContainsString('a&b c=d', $url);
        self::assertSame('a&b c=d', $this->queryOf($url)['search'] ?? null);
    }

    #[Test]
    public function aPageLinkWithoutFiltersCarriesOnlyThePage(): void
    {
        $query = $this->queryOf((new Paginator(30, 1))->urlForPage('/products', 2));

        self::assertSame(['page' => '2'], $query);
    }

    #[Test]
    public function anExistingPageParamIsReplacedNotDuplicated(): void
    {
        // Filter yang masih membawa page lama tidak boleh menghasilkan dua
        // parameter page.
        $paginator = new Paginator(30, 1, ['page' => '1', 'search' => 'widget']);

        $url = $paginator->urlForPage('/products', 3);

        self::assertSame(1, substr_count($url, 'page='));
        self::assertSame('3', $this->queryOf($url)['page'] ?? null);
    }

    // -------------------------------------------------------- page window

    #[Test]
    public function thePageWindowIsTrimmedAroundTheCurrentPage(): void
    {
        // 100 baris = 10 halaman; di halaman 5 dengan radius 2 -> 3..7.
        self::assertSame([3, 4, 5, 6, 7], (new Paginator(100, 5))->pageWindow());
    }

    #[Test]
    public function thePageWindowDoesNotRunPastEitherEnd(): void
    {
        self::assertSame([1, 2, 3], (new Paginator(100, 1))->pageWindow());
        self::assertSame([8, 9, 10], (new Paginator(100, 10))->pageWindow());
    }

    #[Test]
    public function thePageWindowOfASinglePageIsJustThatPage(): void
    {
        self::assertSame([1], (new Paginator(4, 1))->pageWindow());
    }

    // ------------------------------------------------------- range labels

    #[Test]
    public function theDisplayedRangeReadsCorrectly(): void
    {
        $first = new Paginator(30, 1);

        self::assertSame(1, $first->firstItemNumber());
        self::assertSame(10, $first->lastItemNumber());

        $last = new Paginator(25, 3);

        self::assertSame(21, $last->firstItemNumber());
        self::assertSame(25, $last->lastItemNumber(), 'Halaman terakhir tidak boleh melebihi total');
    }

    #[Test]
    public function theRangeOfAnEmptyResultStartsAtZero(): void
    {
        $paginator = new Paginator(0, 1);

        self::assertSame(0, $paginator->firstItemNumber());
        self::assertSame(0, $paginator->lastItemNumber());
    }

    #[Test]
    public function aCustomPerPageIsHonoured(): void
    {
        $paginator = new Paginator(30, 2, [], 5);

        self::assertSame(5, $paginator->perPage());
        self::assertSame(6, $paginator->totalPages());
        self::assertSame(5, $paginator->offset());
    }

    /** @return array<string, string> */
    private function queryOf(string $url): array
    {
        $queryString = parse_url($url, PHP_URL_QUERY);

        self::assertIsString($queryString);

        $parsed = [];
        parse_str($queryString, $parsed);

        /** @var array<string, string> $parsed */
        return $parsed;
    }
}
