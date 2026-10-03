<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Product;
use App\Service\ProductService;
use App\Support\Request;
use App\Support\Response;

/**
 * JSON API ringkasan low stock (API-01, FR-028, DASH-01).
 *
 * Hanya Admin dan Warehouse Staff yang boleh mencapai endpoint ini — low stock
 * bukan bagian dashboard Sales (§1.2). Pembatasan itu ada di route table dan
 * ditegakkan guard sebelum request sampai ke sini; Sales menerima 403 sebagai
 * JSON, bukan halaman error HTML.
 *
 * Bentuk responsnya mengikuti contracts/openapi.yaml.
 */
final class DashboardApiController
{
    /** Default `limit` menurut contract. */
    public const int DEFAULT_LIMIT = 20;

    /**
     * Batas atas menurut contract. Nilai berlebih DIJEPIT, bukan ditolak:
     * memaksa pemanggil menebak batasnya tidak membuat siapa pun lebih aman,
     * sedangkan meneruskan limit tak terbatas membuat endpoint murah ini
     * mudah dijadikan mahal.
     */
    public const int MAX_LIMIT = 100;

    public function __construct(
        private readonly ProductService $productService,
    ) {
    }

    /** GET /api/dashboard/low-stock */
    public function lowStock(Request $request): Response
    {
        $rows = $this->productService->lowStock($this->limitFrom($request));

        return Response::json([
            // count adalah TOTAL product yang menipis, bukan jumlah baris yang
            // terkirim. Kalau keduanya disamakan, memperpendek daftar akan
            // mengecilkan angka di dashboard — persis kebalikan dari yang
            // dibutuhkan.
            'count'    => $this->productService->count(['lowStock' => true, 'active' => true]),
            'products' => array_map(
                static function (array $row): array {
                    /** @var Product $product */
                    $product = $row['product'];

                    return [
                        'productId'     => (int) $product->id,
                        'sku'           => $product->sku,
                        'productName'   => $product->name,
                        'totalQuantity' => (int) $row['totalQuantity'],
                        'reorderPoint'  => (int) $product->reorderPoint,
                    ];
                },
                $rows,
            ),
        ]);
    }

    /**
     * Limit di luar rentang yang didokumentasikan dikembalikan ke nilai yang
     * masuk akal, bukan menjadi error: parameter ini opsional dan tidak ada
     * keputusan bisnis yang bergantung padanya.
     */
    private function limitFrom(Request $request): int
    {
        $limit = $request->queryInt('limit', self::DEFAULT_LIMIT);

        if ($limit < 1) {
            return self::DEFAULT_LIMIT;
        }

        return min($limit, self::MAX_LIMIT);
    }
}
