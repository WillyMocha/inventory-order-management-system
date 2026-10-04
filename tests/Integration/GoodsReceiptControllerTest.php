<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Controller\GoodsReceiptController;
use App\Entity\Enum\PurchaseOrderStatus;
use App\Entity\PurchaseOrder;
use App\Entity\PurchaseOrderItem;
use App\Repository\Mysql\MysqlPurchaseOrderRepository;
use App\Support\Exception\NotFoundException;
use App\Support\Exception\UnauthenticatedException;
use PHPUnit\Framework\Attributes\Test;

/**
 * Lapisan HTTP goods receipt Purchase Order terhadap MySQL sungguhan.
 *
 * GoodsReceiptController dipisahkan dari PurchaseOrderController pada
 * tech-debt TD-11. Validasi outstanding dan ledger diuji di StockService; di
 * sini yang dibuktikan adalah pembacaan input `received[itemId]`, redirect
 * beserta flash, dan form yang dirender ulang dengan 422 tanpa membuang angka
 * yang sudah diisi.
 */
final class GoodsReceiptControllerTest extends IntegrationTestCase
{
    use SalesOrderFixtures;
    use OrderControllerHarness;

    private const string DETAIL = '/purchase-orders/';

    private GoodsReceiptController $controller;
    private MysqlPurchaseOrderRepository $orders;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedSalesOrderFixtures();
        $this->wireOrderServices();
        $this->orders = new MysqlPurchaseOrderRepository($this->database);

        $this->controller = new GoodsReceiptController(
            $this->view,
            $this->purchaseOrderService,
            $this->stockService,
            $this->productService,
            $this->partyService,
            $this->masterDataService,
            $this->userService,
            $this->session,
            $this->csrf,
        );
    }

    protected function tearDown(): void
    {
        $this->resetGlobals();
        parent::tearDown();
    }

    #[Test]
    public function theReceiveFormShowsEachLineWithItsOutstanding(): void
    {
        $id = $this->orderedPurchaseOrder([[$this->productId, 5]]);
        $this->signInAs($this->warehouseStaff);

        $response = $this->controller->receiveForm($this->request($id));

        self::assertSame(200, $response->statusCode());
        self::assertStringContainsString('Fixture Product One', $response->body());
        self::assertStringContainsString('Fixture Supplier', $response->body());
    }

    #[Test]
    public function theReceiveFormOfADraftRedirectsBack(): void
    {
        $id = $this->orders->save(new PurchaseOrder(
            null,
            'PO-FIXTURE-' . bin2hex(random_bytes(6)),
            $this->supplierId,
            $this->warehouseId,
            PurchaseOrderStatus::Draft,
            date('Y-m-d'),
            (int) $this->warehouseStaff->id,
            [new PurchaseOrderItem(null, null, $this->productId, 5, 0, '1000.00')],
        ));
        $this->signInAs($this->warehouseStaff);

        $response = $this->controller->receiveForm($this->request($id));

        $this->assertRedirectWithFlash(
            $response,
            self::DETAIL . $id,
            'error',
            'Goods can only be received for an ordered or partially received order.',
        );
    }

    #[Test]
    public function receivingPartOfAnOrderAddsStockAndRedirectsWithSuccess(): void
    {
        $id = $this->orderedPurchaseOrder([[$this->productId, 5], [$this->secondProductId, 2]]);
        [$firstItem, $secondItem] = $this->purchaseItemIds($id);
        $this->signInAs($this->warehouseStaff);

        // Line kedua dibiarkan kosong: menerima sebagian line saja itu wajar.
        $response = $this->controller->receive($this->request($id, ['csrf_token' => 'x', 'received' => [
            (string) $firstItem  => '3',
            (string) $secondItem => '',
        ]]));

        $this->assertRedirectWithFlash(
            $response,
            self::DETAIL . $id,
            'success',
            'Goods received. Stock and ledger updated.',
        );
        self::assertSame(3, $this->stockQuantity($this->productId, $this->warehouseId));
        self::assertSame(0, $this->stockQuantity($this->secondProductId, $this->warehouseId));
        self::assertSame(PurchaseOrderStatus::PartiallyReceived, $this->statusOf($id));
    }

    #[Test]
    public function overReceivingReRendersTheFormWith422AndKeepsTheEnteredValue(): void
    {
        $id = $this->orderedPurchaseOrder([[$this->productId, 5]]);
        [$item] = $this->purchaseItemIds($id);
        $this->signInAs($this->warehouseStaff);

        $response = $this->controller->receive($this->request($id, ['csrf_token' => 'x', 'received' => [
            (string) $item => '99',
        ]]));

        self::assertSame(422, $response->statusCode());
        self::assertStringContainsString('value="99"', $response->body());
        self::assertSame(0, $this->stockQuantity($this->productId, $this->warehouseId));
        self::assertSame(PurchaseOrderStatus::Ordered, $this->statusOf($id));
    }

    #[Test]
    public function aReceiptWithNoUsableQuantityIsRefusedWith422(): void
    {
        $id = $this->orderedPurchaseOrder([[$this->productId, 5]]);
        $this->signInAs($this->warehouseStaff);

        // Item id yang bukan angka positif dan nilai kosong sama-sama diabaikan.
        $response = $this->controller->receive($this->request($id, ['csrf_token' => 'x', 'received' => [
            'abc' => '3',
            '0'   => '2',
        ]]));

        self::assertSame(422, $response->statusCode());
        self::assertStringContainsString('Enter a quantity for at least one line to receive.', $response->body());
    }

    #[Test]
    public function receivingWithoutASessionIsUnauthenticated(): void
    {
        $id = $this->orderedPurchaseOrder([[$this->productId, 5]]);
        [$item] = $this->purchaseItemIds($id);

        $this->expectException(UnauthenticatedException::class);
        $this->controller->receive($this->request($id, ['csrf_token' => 'x', 'received' => [(string) $item => '1']]));
    }

    #[Test]
    public function theReceiveFormWithoutAnOrderIdIsNotFound(): void
    {
        $this->signInAs($this->warehouseStaff);

        $this->expectException(NotFoundException::class);
        $this->controller->receiveForm($this->request());
    }

    private function statusOf(int $id): PurchaseOrderStatus
    {
        $order = $this->orders->findById($id);
        self::assertNotNull($order);

        return $order->status;
    }
}
