<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Controller\PurchaseOrderController;
use App\Entity\Enum\PurchaseOrderStatus;
use App\Entity\PurchaseOrder;
use App\Entity\PurchaseOrderItem;
use App\Repository\Mysql\MysqlPurchaseOrderRepository;
use App\Support\Exception\NotFoundException;
use App\Support\Exception\UnauthenticatedException;
use PHPUnit\Framework\Attributes\Test;

/**
 * Lapisan HTTP Purchase Order terhadap MySQL sungguhan: daftar, detail, create,
 * edit Draft (spec 004), submit, dan cancel.
 *
 * Aturan bisnisnya diuji di PurchaseOrderServiceTest dan
 * PurchaseOrderServiceEditTest; di sini yang dibuktikan adalah status code,
 * redirect beserta flash, dan form yang dirender ulang dengan 422.
 */
final class PurchaseOrderControllerTest extends IntegrationTestCase
{
    use SalesOrderFixtures;
    use OrderControllerHarness;

    private const string DETAIL = '/purchase-orders/';

    private PurchaseOrderController $controller;
    private MysqlPurchaseOrderRepository $orders;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedSalesOrderFixtures();
        $this->wireOrderServices();
        $this->orders = new MysqlPurchaseOrderRepository($this->database);

        $this->controller = new PurchaseOrderController(
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

    // ------------------------------------------------------- list & detail

    #[Test]
    public function theListShowsAMatchingOrder(): void
    {
        $id = $this->draftPurchaseOrder(4);
        $this->signInAs($this->admin);

        $response = $this->controller->index($this->request(null, [], ['search' => $this->numberOf($id)]));

        self::assertSame(200, $response->statusCode());
        self::assertStringContainsString($this->numberOf($id), $response->body());
    }

    #[Test]
    public function theDetailOfADraftOffersEditToItsCreator(): void
    {
        $id = $this->draftPurchaseOrder(4);
        $this->signInAs($this->warehouseStaff);

        $response = $this->controller->show($this->request($id));

        self::assertSame(200, $response->statusCode());
        self::assertStringContainsString($this->numberOf($id), $response->body());
        self::assertStringContainsString(self::DETAIL . $id . '/edit', $response->body());
    }

    #[Test]
    public function aDetailWithoutAnOrderIdIsNotFound(): void
    {
        $this->signInAs($this->admin);

        $this->expectException(NotFoundException::class);
        $this->controller->show($this->request());
    }

    // ---------------------------------------------------------------- create

    #[Test]
    public function theCreateFormRenders(): void
    {
        $this->signInAs($this->warehouseStaff);

        $response = $this->controller->create($this->request());

        self::assertSame(200, $response->statusCode());
        self::assertStringContainsString('Create purchase order', $response->body());
    }

    #[Test]
    public function storingAValidOrderRedirectsToTheNewDraft(): void
    {
        $this->signInAs($this->warehouseStaff);

        $response = $this->controller->store($this->request(null, $this->payload(6)));

        self::assertSame(302, $response->statusCode());
        self::assertStringStartsWith(self::DETAIL, (string) $response->header('Location'));
        self::assertSame(
            ['type' => 'success', 'message' => 'Purchase order created as a draft.'],
            $this->session->pullFlash(),
        );
    }

    #[Test]
    public function storingAnInvalidOrderReRendersTheFormWith422(): void
    {
        $this->signInAs($this->warehouseStaff);

        $response = $this->controller->store($this->request(null, ['csrf_token' => 'x', 'items' => [
            ['product_id' => '', 'quantity' => ''],
        ]]));

        self::assertSame(422, $response->statusCode());
        self::assertStringContainsString('Create purchase order', $response->body());
    }

    #[Test]
    public function storingWithoutASessionIsUnauthenticated(): void
    {
        $this->expectException(UnauthenticatedException::class);
        $this->controller->store($this->request(null, $this->payload(6)));
    }

    // ------------------------------------------------------ edit (spec 004)

    #[Test]
    public function theEditFormIsPrefilledFromTheDraft(): void
    {
        $id = $this->draftPurchaseOrder(4);
        $this->signInAs($this->warehouseStaff);

        $response = $this->controller->edit($this->request($id));

        self::assertSame(200, $response->statusCode());
        self::assertStringContainsString('Edit purchase order ' . $this->numberOf($id), $response->body());
    }

    #[Test]
    public function theEditFormOfAnOrderedOrderRedirectsBack(): void
    {
        $id = $this->orderedPurchaseOrder([[$this->productId, 4]]);
        $this->signInAs($this->warehouseStaff);

        $response = $this->controller->edit($this->request($id));

        $this->assertRedirectWithFlash($response, self::DETAIL . $id, 'error', 'Only a draft order can be edited.');
    }

    #[Test]
    public function updatingADraftSavesTheNewLines(): void
    {
        $id = $this->draftPurchaseOrder(4);
        $this->signInAs($this->warehouseStaff);

        $response = $this->controller->update($this->request($id, $this->payload(9)));

        $this->assertRedirectWithFlash($response, self::DETAIL . $id, 'success', 'Purchase order updated.');
        self::assertSame(9, $this->requireOrder($id)->items[0]->quantity);
    }

    #[Test]
    public function anInvalidUpdateReRendersTheEditFormWith422(): void
    {
        $id = $this->draftPurchaseOrder(4);
        $this->signInAs($this->warehouseStaff);

        $response = $this->controller->update($this->request($id, $this->payload(0)));

        self::assertSame(422, $response->statusCode());
        self::assertStringContainsString('Edit purchase order', $response->body());
        self::assertSame(4, $this->requireOrder($id)->items[0]->quantity);
    }

    #[Test]
    public function updatingAnOrderThatLeftDraftComesBackAsAnErrorFlash(): void
    {
        $id = $this->orderedPurchaseOrder([[$this->productId, 4]]);
        $this->signInAs($this->admin);

        $response = $this->controller->update($this->request($id, $this->payload(9)));

        self::assertSame(302, $response->statusCode());
        self::assertSame('error', $this->session->pullFlash()['type'] ?? null);
        self::assertSame(4, $this->requireOrder($id)->items[0]->quantity);
    }

    // ------------------------------------------------------ submit & cancel

    #[Test]
    public function submittingADraftOrdersIt(): void
    {
        $id = $this->draftPurchaseOrder(4);
        $this->signInAs($this->warehouseStaff);

        $response = $this->controller->submit($this->request($id, ['csrf_token' => 'x']));

        $this->assertRedirectWithFlash(
            $response,
            self::DETAIL . $id,
            'success',
            'Purchase order submitted to the supplier.',
        );
        self::assertSame(PurchaseOrderStatus::Ordered, $this->requireOrder($id)->status);
    }

    #[Test]
    public function submittingTwiceComesBackAsAnErrorFlash(): void
    {
        $id = $this->orderedPurchaseOrder([[$this->productId, 4]]);
        $this->signInAs($this->warehouseStaff);

        $response = $this->controller->submit($this->request($id, ['csrf_token' => 'x']));

        self::assertSame(302, $response->statusCode());
        self::assertSame('error', $this->session->pullFlash()['type'] ?? null);
    }

    #[Test]
    public function cancellingAnOrderedOrderMarksItCancelled(): void
    {
        $id = $this->orderedPurchaseOrder([[$this->productId, 4]]);
        $this->signInAs($this->admin);

        $response = $this->controller->cancel($this->request($id, ['csrf_token' => 'x']));

        $this->assertRedirectWithFlash($response, self::DETAIL . $id, 'success', 'Purchase order cancelled.');
        self::assertSame(PurchaseOrderStatus::Cancelled, $this->requireOrder($id)->status);
    }

    // --------------------------------------------------------------- helper

    /** @return array<string, mixed> */
    private function payload(int $quantity): array
    {
        return [
            'csrf_token'   => 'x',
            'supplier_id'  => (string) $this->supplierId,
            'warehouse_id' => (string) $this->warehouseId,
            'order_date'   => date('Y-m-d'),
            'items'        => [
                ['product_id' => (string) $this->productId, 'quantity' => (string) $quantity],
                ['product_id' => '', 'quantity' => ''],
            ],
        ];
    }

    private function draftPurchaseOrder(int $quantity): int
    {
        return $this->orders->save(new PurchaseOrder(
            null,
            'PO-FIXTURE-' . bin2hex(random_bytes(6)),
            $this->supplierId,
            $this->warehouseId,
            PurchaseOrderStatus::Draft,
            date('Y-m-d'),
            (int) $this->warehouseStaff->id,
            [new PurchaseOrderItem(null, null, $this->productId, $quantity, 0, '1000.00')],
        ));
    }

    private function requireOrder(int $id): PurchaseOrder
    {
        $order = $this->orders->findById($id);
        self::assertNotNull($order);

        return $order;
    }

    private function numberOf(int $id): string
    {
        return $this->requireOrder($id)->orderNumber;
    }
}
