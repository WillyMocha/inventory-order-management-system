<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Product;
use App\Service\MasterDataService;
use App\Service\ProductService;
use App\Support\Exception\NotFoundException;
use App\Support\Request;
use App\Support\Response;

/**
 * JSON API ketersediaan stock (API-01, FR-028).
 *
 * Bentuk setiap respons mengikuti contracts/openapi.yaml persis — nama field,
 * tipe, dan kelengkapannya. Angka dikirim sebagai integer, bukan string:
 * PDO mengembalikan kolom numerik sebagai string, dan meneruskannya begitu
 * saja akan mematahkan consumer JSON-nya.
 *
 * Controller ini TIDAK menerima Session. Authentication dan authorization
 * sudah diselesaikan route table beserta guard sebelum request sampai ke sini,
 * dan front controller yang menerjemahkan setiap exception menjadi envelope
 * JSON — 401 untuk yang belum masuk, 404 untuk yang tidak ditemukan — tidak
 * pernah halaman HTML (contracts/http-routes.md).
 */
final class StockApiController
{
    public function __construct(
        private readonly ProductService $productService,
        private readonly MasterDataService $masterData,
    ) {
    }

    /**
     * GET /api/products/{sku}/availability
     *
     * @throws NotFoundException bila SKU tidak dikenal
     */
    public function availability(Request $request): Response
    {
        $breakdown = $this->productService->stockBreakdownBySku($this->requireSku($request));

        /** @var Product $product */
        $product = $breakdown['product'];

        return Response::json([
            'sku'           => $product->sku,
            'productName'   => $product->name,
            'unit'          => $product->unit,
            'reorderPoint'  => (int) $product->reorderPoint,
            'totalQuantity' => (int) $breakdown['total'],
            'lowStock'      => (bool) $breakdown['isLowStock'],
            'warehouses'    => array_map(
                static fn (array $row): array => [
                    'warehouseId'   => (int) $row['warehouseId'],
                    'warehouseName' => (string) $row['warehouseName'],
                    'quantity'      => (int) $row['quantity'],
                ],
                $breakdown['warehouses'],
            ),
        ]);
    }

    /**
     * GET /api/products/{productId}/warehouses/{warehouseId}/available
     *
     * Dipakai form Sales Order untuk menampilkan available stock di samping
     * setiap line. Nilainya indikatif — lihat ProductService::availableQuantity().
     *
     * @throws NotFoundException bila product atau warehouse tidak ada
     */
    public function available(Request $request): Response
    {
        $productId = $this->requireId($request, 'productId');
        $warehouseId = $this->requireId($request, 'warehouseId');

        // Warehouse yang tidak ada menghasilkan 404 dari sini; tanpa
        // pemeriksaan ini, id warehouse asal akan dijawab availableQuantity 0
        // seolah-olah warehouse-nya sah tetapi kosong.
        $this->masterData->requireWarehouse($warehouseId);

        return Response::json([
            'productId'         => $productId,
            'warehouseId'       => $warehouseId,
            'availableQuantity' => $this->productService->availableQuantity($productId, $warehouseId),
        ]);
    }

    /**
     * SKU selalu dipakai sebagai bound parameter, tidak pernah diinterpolasi
     * ke SQL. Bentuknya juga dibatasi sesuai contract; SKU di luar bentuk itu
     * dijawab 404, sama seperti SKU yang memang tidak ada — tidak ada gunanya
     * membedakan keduanya bagi pemanggil.
     *
     * @throws NotFoundException
     */
    private function requireSku(Request $request): string
    {
        $sku = $request->routeParam('sku');

        if ($sku === null || preg_match('/^[A-Za-z0-9._-]{1,64}$/', $sku) !== 1) {
            throw new NotFoundException();
        }

        return $sku;
    }

    /** @throws NotFoundException */
    private function requireId(Request $request, string $key): int
    {
        $id = $request->routeParamInt($key);

        if ($id === null || $id < 1) {
            throw new NotFoundException();
        }

        return $id;
    }
}
