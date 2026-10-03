<?php

declare(strict_types=1);

namespace Tests\Unit\Controller;

use App\Controller\Api\StockApiController;
use App\Entity\Product;
use App\Entity\Warehouse;
use App\Service\MasterDataService;
use App\Service\ProductService;
use App\Support\Exception\NotFoundException;
use App\Support\Request;
use App\Support\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Fake\InMemoryCategoryRepository;
use Tests\Unit\Fake\InMemoryProductRepository;
use Tests\Unit\Fake\InMemoryProductStockRepository;
use Tests\Unit\Fake\InMemoryWarehouseRepository;

/**
 * Unit test StockApiController (API-01, FR-028).
 *
 * Controller ini TIDAK menerima Session, dan itu disengaja: authentication dan
 * authorization sudah diselesaikan route table beserta guard sebelum request
 * sampai ke sini (lihat contracts/http-routes.md). Karena itu bentuk responsnya
 * dapat diuji penuh tanpa session sama sekali — yang diuji di sini adalah
 * kesesuaian body JSON dengan contracts/openapi.yaml, field demi field.
 *
 * Perilaku 401 dan 403 diuji di StockApiTest terhadap route table sungguhan.
 */
final class StockApiControllerTest extends TestCase
{
    private StockApiController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $products = new InMemoryProductRepository([
            new Product(1, 'SKU-000001', 'Kabel UTP Cat6 305m', 1, 'roll', '1150000.00', '1450000.00', 10, null, true),
            new Product(2, 'SKU-000002', 'Switch 24-Port', 1, 'pcs', '2150000.00', '2750000.00', 4, null, true),
        ]);
        $products->setTotalQuantity(1, 42);
        $products->setTotalQuantity(2, 3);

        $stocks = new InMemoryProductStockRepository(
            ['1:1' => 30, '1:2' => 12, '2:1' => 3, '2:2' => 0],
            [1 => 'Gudang Pusat Jakarta', 2 => 'Gudang Surabaya'],
        );

        $productService = new ProductService($products, $stocks, new InMemoryCategoryRepository([]));

        $warehouses = new InMemoryWarehouseRepository([
            new Warehouse(1, 'Gudang Pusat Jakarta', 'Jakarta', true),
            new Warehouse(2, 'Gudang Surabaya', 'Surabaya', true),
        ]);

        $this->controller = new StockApiController(
            $productService,
            new MasterDataService(new InMemoryCategoryRepository([]), $warehouses),
        );
    }

    // ------------------------------------------------- availability(sku)

    #[Test]
    public function availabilityReturnsEveryFieldTheContractRequires(): void
    {
        $body = $this->decode($this->controller->availability($this->request(['sku' => 'SKU-000001'])));

        foreach (['sku', 'productName', 'unit', 'reorderPoint', 'totalQuantity', 'lowStock', 'warehouses'] as $field) {
            self::assertArrayHasKey($field, $body, 'Field ' . $field . ' hilang dari respons.');
        }
    }

    #[Test]
    public function availabilityCarriesTheDocumentedValues(): void
    {
        $body = $this->decode($this->controller->availability($this->request(['sku' => 'SKU-000001'])));

        self::assertSame('SKU-000001', $body['sku']);
        self::assertSame('Kabel UTP Cat6 305m', $body['productName']);
        self::assertSame('roll', $body['unit']);
        self::assertSame(10, $body['reorderPoint']);
        self::assertSame(42, $body['totalQuantity']);
        self::assertFalse($body['lowStock']);
    }

    #[Test]
    public function availabilityBreaksStockDownPerWarehouse(): void
    {
        $body = $this->decode($this->controller->availability($this->request(['sku' => 'SKU-000001'])));

        self::assertCount(2, $body['warehouses']);

        foreach ($body['warehouses'] as $row) {
            foreach (['warehouseId', 'warehouseName', 'quantity'] as $field) {
                self::assertArrayHasKey($field, $row);
            }
        }

        self::assertSame(1, $body['warehouses'][0]['warehouseId']);
        self::assertSame('Gudang Pusat Jakarta', $body['warehouses'][0]['warehouseName']);
        self::assertSame(30, $body['warehouses'][0]['quantity']);
    }

    #[Test]
    public function theWarehouseQuantitiesSumToTheReportedTotal(): void
    {
        $body = $this->decode($this->controller->availability($this->request(['sku' => 'SKU-000001'])));

        self::assertSame($body['totalQuantity'], array_sum(array_column($body['warehouses'], 'quantity')));
    }

    #[Test]
    public function lowStockIsTrueWhenTheTotalIsAtOrBelowTheReorderPoint(): void
    {
        // Product 2: total 3, reorder point 4 (spec A-008).
        $body = $this->decode($this->controller->availability($this->request(['sku' => 'SKU-000002'])));

        self::assertTrue($body['lowStock']);
    }

    #[Test]
    public function typesMatchTheContractRatherThanBeingStringified(): void
    {
        // Kesalahan klasik: PDO mengembalikan string, lalu angka ikut terkirim
        // sebagai string dan consumer JSON-nya patah.
        $body = $this->decode($this->controller->availability($this->request(['sku' => 'SKU-000001'])));

        self::assertIsInt($body['reorderPoint']);
        self::assertIsInt($body['totalQuantity']);
        self::assertIsBool($body['lowStock']);
        self::assertIsInt($body['warehouses'][0]['warehouseId']);
        self::assertIsInt($body['warehouses'][0]['quantity']);
    }

    #[Test]
    public function anUnknownSkuIsNotFoundRatherThanAnEmptyResult(): void
    {
        $this->expectException(NotFoundException::class);

        $this->controller->availability($this->request(['sku' => 'SKU-NOPE']));
    }

    #[Test]
    public function aMissingSkuParameterIsNotFound(): void
    {
        $this->expectException(NotFoundException::class);

        $this->controller->availability($this->request([]));
    }

    #[Test]
    public function availabilityRespondsAsJson(): void
    {
        $response = $this->controller->availability($this->request(['sku' => 'SKU-000001']));

        self::assertSame(200, $response->statusCode());
        self::assertJson($response->body());
    }

    // --------------------------- available(productId, warehouseId)

    #[Test]
    public function availableReturnsEveryFieldTheContractRequires(): void
    {
        $body = $this->decode($this->controller->available(
            $this->request(['productId' => '1', 'warehouseId' => '2']),
        ));

        self::assertSame(['productId', 'warehouseId', 'availableQuantity'], array_keys($body));
        self::assertSame(1, $body['productId']);
        self::assertSame(2, $body['warehouseId']);
        self::assertSame(12, $body['availableQuantity']);
    }

    #[Test]
    public function aPairWithNoStockRowReportsZeroRatherThanFailing(): void
    {
        // Product 2 tidak pernah disimpan di warehouse 2. Nol adalah jawaban
        // yang benar, bukan 404: pasangannya sah, stocknya saja yang kosong.
        $body = $this->decode($this->controller->available(
            $this->request(['productId' => '2', 'warehouseId' => '2']),
        ));

        self::assertSame(0, $body['availableQuantity']);
    }

    #[Test]
    public function anUnknownProductIsNotFound(): void
    {
        $this->expectException(NotFoundException::class);

        $this->controller->available($this->request(['productId' => '99', 'warehouseId' => '1']));
    }

    #[Test]
    public function anUnknownWarehouseIsNotFound(): void
    {
        $this->expectException(NotFoundException::class);

        $this->controller->available($this->request(['productId' => '1', 'warehouseId' => '99']));
    }

    #[Test]
    public function aNonNumericIdIsNotFoundRatherThanCoercedToZero(): void
    {
        $this->expectException(NotFoundException::class);

        $this->controller->available($this->request(['productId' => 'abc', 'warehouseId' => '1']));
    }

    #[Test]
    public function availableRespondsAsJson(): void
    {
        $response = $this->controller->available(
            $this->request(['productId' => '1', 'warehouseId' => '1']),
        );

        self::assertSame(200, $response->statusCode());
        self::assertJson($response->body());
    }

    // ------------------------------------------------------- Helpers

    /** @param array<string, string> $routeParams */
    private function request(array $routeParams): Request
    {
        return Request::fromGlobals()->withRouteParams($routeParams);
    }

    /** @return array<string, mixed> */
    private function decode(Response $response): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
