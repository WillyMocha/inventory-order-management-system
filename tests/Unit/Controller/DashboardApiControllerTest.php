<?php

declare(strict_types=1);

namespace Tests\Unit\Controller;

use App\Controller\Api\DashboardApiController;
use App\Entity\Product;
use App\Service\ProductService;
use App\Support\Request;
use App\Support\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Fake\InMemoryCategoryRepository;
use Tests\Unit\Fake\InMemoryProductRepository;
use Tests\Unit\Fake\InMemoryProductStockRepository;

/**
 * Unit test DashboardApiController (API-01, FR-028).
 *
 * Endpoint ini hanya boleh dicapai Admin dan Warehouse Staff (§1.2) — Sales
 * menerima 403. Pembatasan itu ada di route table dan ditegakkan guard, jadi
 * yang diuji di sini adalah bentuk respons; penolakan role-nya diuji terhadap
 * route table sungguhan di StockApiTest.
 */
final class DashboardApiControllerTest extends TestCase
{
    private DashboardApiController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $products = new InMemoryProductRepository([
            $this->product(1, 'SKU-000001', 'Kabel UTP Cat6 305m', 10),
            $this->product(2, 'SKU-000002', 'Switch 24-Port', 4),
            $this->product(3, 'SKU-000003', 'Patch Panel 24', 6),
            $this->product(4, 'SKU-000004', 'Rack 20U', 2),
        ]);

        // Tiga product di bawah atau tepat pada reorder point, satu jauh di atas.
        $products->setTotalQuantity(1, 4);
        $products->setTotalQuantity(2, 4);
        $products->setTotalQuantity(3, 0);
        $products->setTotalQuantity(4, 50);

        $this->controller = new DashboardApiController(new ProductService(
            $products,
            new InMemoryProductStockRepository(),
            new InMemoryCategoryRepository([]),
        ));
    }

    protected function tearDown(): void
    {
        $_GET = [];

        parent::tearDown();
    }

    #[Test]
    public function theSummaryCarriesEveryFieldTheContractRequires(): void
    {
        $body = $this->decode($this->controller->lowStock($this->request([])));

        self::assertArrayHasKey('count', $body);
        self::assertArrayHasKey('products', $body);
    }

    #[Test]
    public function eachProductCarriesEveryFieldTheContractRequires(): void
    {
        $body = $this->decode($this->controller->lowStock($this->request([])));

        foreach ($body['products'] as $row) {
            foreach (['productId', 'sku', 'productName', 'totalQuantity', 'reorderPoint'] as $field) {
                self::assertArrayHasKey($field, $row, 'Field ' . $field . ' hilang dari respons.');
            }
        }
    }

    #[Test]
    public function onlyProductsAtOrBelowTheReorderPointAreListed(): void
    {
        $body = $this->decode($this->controller->lowStock($this->request([])));

        $skus = array_column($body['products'], 'sku');

        self::assertContains('SKU-000001', $skus);
        self::assertContains('SKU-000002', $skus);
        self::assertContains('SKU-000003', $skus);
        self::assertNotContains('SKU-000004', $skus, 'Product jauh di atas reorder point tidak boleh ikut.');
    }

    #[Test]
    public function theCountIsTheTotalAndDoesNotShrinkWithTheLimit(): void
    {
        // Angka ringkasan dihitung terpisah dari daftarnya. Kalau count hanya
        // menghitung baris yang terkirim, memperpendek daftar akan mengecilkan
        // angka di dashboard — persis kebalikan dari yang dibutuhkan.
        $all = $this->decode($this->controller->lowStock($this->request([])));
        $one = $this->decode($this->controller->lowStock($this->request(['limit' => '1'])));

        self::assertSame(3, $all['count']);
        self::assertSame(3, $one['count']);
        self::assertCount(3, $all['products']);
        self::assertCount(1, $one['products']);
    }

    #[Test]
    public function theLimitDefaultsToTheDocumentedValue(): void
    {
        self::assertSame(20, DashboardApiController::DEFAULT_LIMIT);
    }

    #[Test]
    public function aLimitAboveTheMaximumIsClampedRatherThanRefused(): void
    {
        $body = $this->decode($this->controller->lowStock($this->request(['limit' => '5000'])));

        // Contract membatasi limit pada 100; nilai berlebih dijepit, bukan
        // dipakai apa adanya — kalau tidak, endpoint ini mudah dibuat mahal.
        self::assertCount(3, $body['products']);
        self::assertSame(3, $body['count']);
    }

    #[Test]
    public function aZeroOrNegativeLimitFallsBackToTheDefault(): void
    {
        $zero = $this->decode($this->controller->lowStock($this->request(['limit' => '0'])));
        $negative = $this->decode($this->controller->lowStock($this->request(['limit' => '-5'])));

        self::assertCount(3, $zero['products']);
        self::assertCount(3, $negative['products']);
    }

    #[Test]
    public function aNonNumericLimitFallsBackToTheDefault(): void
    {
        $body = $this->decode($this->controller->lowStock($this->request(['limit' => 'all'])));

        self::assertCount(3, $body['products']);
    }

    #[Test]
    public function typesMatchTheContractRatherThanBeingStringified(): void
    {
        $body = $this->decode($this->controller->lowStock($this->request([])));

        self::assertIsInt($body['count']);
        self::assertIsInt($body['products'][0]['productId']);
        self::assertIsInt($body['products'][0]['totalQuantity']);
        self::assertIsInt($body['products'][0]['reorderPoint']);
        self::assertIsString($body['products'][0]['sku']);
    }

    #[Test]
    public function anEmptyResultIsAnEmptyListNotAnError(): void
    {
        $controller = new DashboardApiController(new ProductService(
            new InMemoryProductRepository([]),
            new InMemoryProductStockRepository(),
            new InMemoryCategoryRepository([]),
        ));

        $body = $this->decode($controller->lowStock($this->request([])));

        self::assertSame(0, $body['count']);
        self::assertSame([], $body['products']);
    }

    #[Test]
    public function theSummaryRespondsAsJson(): void
    {
        $response = $this->controller->lowStock($this->request([]));

        self::assertSame(200, $response->statusCode());
        self::assertJson($response->body());
    }

    // ------------------------------------------------------- Helpers

    private function product(int $id, string $sku, string $name, int $reorderPoint): Product
    {
        return new Product($id, $sku, $name, 1, 'pcs', '100000.00', '135000.00', $reorderPoint, null, true);
    }

    /** @param array<string, string> $query */
    private function request(array $query): Request
    {
        $_GET = $query;

        return Request::fromGlobals();
    }

    /** @return array<string, mixed> */
    private function decode(Response $response): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
