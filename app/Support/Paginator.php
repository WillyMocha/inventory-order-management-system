<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Pagination sisi server, 10 baris per halaman (FIND-01).
 *
 * Filter dan sort yang sedang aktif dibawa di query string, sehingga halaman
 * hasil filter tetap dapat dibagikan lewat URL dan tidak hilang saat berpindah
 * halaman (FR-025).
 */
final class Paginator
{
    public const int DEFAULT_PER_PAGE = 10;

    private readonly int $currentPage;

    /**
     * @param array<string, string|int> $queryParams filter/sort yang sedang aktif
     */
    public function __construct(
        private readonly int $totalItems,
        int $currentPage,
        private readonly array $queryParams = [],
        private readonly int $perPage = self::DEFAULT_PER_PAGE,
    ) {
        $this->currentPage = max(1, min($currentPage, max(1, $this->totalPages())));
    }

    public function currentPage(): int
    {
        return $this->currentPage;
    }

    public function perPage(): int
    {
        return $this->perPage;
    }

    public function totalItems(): int
    {
        return $this->totalItems;
    }

    public function totalPages(): int
    {
        return (int) max(1, ceil($this->totalItems / $this->perPage));
    }

    public function offset(): int
    {
        return ($this->currentPage - 1) * $this->perPage;
    }

    public function hasPrevious(): bool
    {
        return $this->currentPage > 1;
    }

    public function hasNext(): bool
    {
        return $this->currentPage < $this->totalPages();
    }

    /**
     * URL halaman tertentu dengan seluruh filter aktif dipertahankan.
     */
    public function urlForPage(string $basePath, int $page): string
    {
        $params = $this->queryParams;
        $params['page'] = $page;

        return $basePath . '?' . http_build_query($params);
    }

    /**
     * Nomor halaman yang ditampilkan, dipangkas di sekitar halaman aktif.
     *
     * @return list<int>
     */
    public function pageWindow(int $radius = 2): array
    {
        $start = max(1, $this->currentPage - $radius);
        $end = min($this->totalPages(), $this->currentPage + $radius);

        return range($start, $end);
    }

    /** Rentang baris yang sedang ditampilkan, untuk teks "1-10 of 30". */
    public function firstItemNumber(): int
    {
        return $this->totalItems === 0 ? 0 : $this->offset() + 1;
    }

    public function lastItemNumber(): int
    {
        return min($this->offset() + $this->perPage, $this->totalItems);
    }
}
