<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Controller\SalesOrderController;
use App\Entity\Enum\SalesOrderStatus;
use App\Entity\SalesOrder;
use App\Repository\Mysql\MysqlSalesOrderRepository;
use App\Support\Exception\ForbiddenException;
use App\Support\Exception\NotFoundException;
use App\Support\Exception\UnauthenticatedException;
use PHPUnit\Framework\Attributes\Test;

/**
 * Lapisan HTTP Sales Order terhadap MySQL sungguhan: daftar, detail, create,
 * edit Draft (spec 004), submit, dan cancel.
 *
 * Aturan bisnisnya diuji di SalesOrderServiceTest dan SalesOrderServiceEditTest;
 * di sini yang dibuktikan adalah status code, redirect beserta flash, form yang
 * dirender ulang dengan 422, dan exception yang dibiarkan sampai ke front
 * controller.
 */
final class SalesOrderControllerTest extends IntegrationTestCase
{
    use SalesOrderFixtures;
    use OrderControllerHarness;

    private const string DETAIL = '/sales-orders/';

    private SalesOrderController $controller;
    private MysqlSalesOrderRepository $orders;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedSalesOrderFixtures();
        $this->wireOrderServices();
        $this->orders = new MysqlSalesOrderRepository($this->database);

        $this->controller = new SalesOrderController(
            $this->view,
            $this->salesOrderService,
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
    public function theListShowsTheSalesUsersOwnOrder(): void
    {
        $id = $this->draftOrder([[$this->productId, 2]]);
        $this->signInAs($this->salesCreator);

        $response = $this->controller->index($this->request(null, [], ['search' => $this->numberOf($id)]));

        self::assertSame(200, $response->statusCode());
        self::assertStringContainsString($this->numberOf($id), $response->body());
    }

    #[Test]
    public function theDetailOfADraftOffersEditToItsCreator(): void
    {
        $id = $this->draftOrder([[$this->productId, 2]]);
        $this->signInAs($this->salesCreator);

        $response = $this->controller->show($this->request($id));

        self::assertSame(200, $response->statusCode());
        self::assertStringContainsString($this->numberOf($id), $response->body());
        self::assertStringContainsString(self::DETAIL . $id . '/edit', $response->body());
    }

    #[Test]
    public function aDetailWithoutASessionIsUnauthenticated(): void
    {
        $id = $this->draftOrder([[$this->productId, 2]]);

        $this->expectException(UnauthenticatedException::class);
        $this->controller->show($this->request($id));
    }

    // ---------------------------------------------------------------- create

    #[Test]
    public function theCreateFormRenders(): void
    {
        $this->signInAs($this->salesCreator);

        $response = $this->controller->create($this->request());

        self::assertSame(200, $response->statusCode());
        self::assertStringContainsString('Create sales order', $response->body());
    }

    #[Test]
    public function storingAValidOrderRedirectsToTheNewDraft(): void
    {
        $this->signInAs($this->salesCreator);

        $response = $this->controller->store($this->request(null, $this->payload(3)));

        self::assertSame(302, $response->statusCode());
        self::assertStringStartsWith(self::DETAIL, (string) $response->header('Location'));
        self::assertSame(
            ['type' => 'success', 'message' => 'Sales order created as a draft.'],
            $this->session->pullFlash(),
        );
    }

    #[Test]
    public function storingAnInvalidOrderReRendersTheFormWith422(): void
    {
        $this->signInAs($this->salesCreator);

        $response = $this->controller->store($this->request(null, ['csrf_token' => 'x', 'items' => ['not-a-line']]));

        self::assertSame(422, $response->statusCode());
        self::assertStringContainsString('Create sales order', $response->body());
    }

    // ------------------------------------------------------ edit (spec 004)

    #[Test]
    public function theEditFormIsPrefilledFromTheDraft(): void
    {
        $id = $this->draftOrder([[$this->productId, 2]]);
        $this->signInAs($this->salesCreator);

        $response = $this->controller->edit($this->request($id));

        self::assertSame(200, $response->statusCode());
        self::assertStringContainsString('Edit sales order ' . $this->numberOf($id), $response->body());
    }

    #[Test]
    public function theEditFormOfAnApprovedOrderRedirectsBack(): void
    {
        $id = $this->approvedOrder([[$this->productId, 2]]);
        $this->signInAs($this->salesCreator);

        $response = $this->controller->edit($this->request($id));

        $this->assertRedirectWithFlash($response, self::DETAIL . $id, 'error', 'Only a draft order can be edited.');
    }

    #[Test]
    public function anAdminCannotOpenTheEditFormOfAnotherUsersOrder(): void
    {
        $id = $this->draftOrder([[$this->productId, 2]]);
        $this->signInAs($this->admin);

        $this->expectException(ForbiddenException::class);
        $this->controller->edit($this->request($id));
    }

    #[Test]
    public function updatingADraftSavesTheNewLines(): void
    {
        $id = $this->draftOrder([[$this->productId, 2]]);
        $this->signInAs($this->salesCreator);

        $response = $this->controller->update($this->request($id, $this->payload(5)));

        $this->assertRedirectWithFlash($response, self::DETAIL . $id, 'success', 'Sales order updated.');
        self::assertSame(5, $this->requireOrder($id)->items[0]->quantity);
    }

    #[Test]
    public function anInvalidUpdateReRendersTheEditFormWith422(): void
    {
        $id = $this->draftOrder([[$this->productId, 2]]);
        $this->signInAs($this->salesCreator);

        $response = $this->controller->update($this->request($id, $this->payload(0)));

        self::assertSame(422, $response->statusCode());
        self::assertStringContainsString('Edit sales order', $response->body());
        self::assertSame(2, $this->requireOrder($id)->items[0]->quantity);
    }

    #[Test]
    public function updatingAnOrderThatLeftDraftComesBackAsAnErrorFlash(): void
    {
        $id = $this->approvedOrder([[$this->productId, 2]]);
        $this->signInAs($this->salesCreator);

        $response = $this->controller->update($this->request($id, $this->payload(5)));

        self::assertSame(302, $response->statusCode());
        self::assertSame('error', $this->session->pullFlash()['type'] ?? null);
        self::assertSame(2, $this->requireOrder($id)->items[0]->quantity);
    }

    #[Test]
    public function anUpdateWithoutAnOrderIdIsNotFound(): void
    {
        $this->signInAs($this->salesCreator);

        $this->expectException(NotFoundException::class);
        $this->controller->update($this->request(null, $this->payload(5)));
    }

    // ------------------------------------------------------ submit & cancel

    #[Test]
    public function submittingADraftSendsItForApproval(): void
    {
        $id = $this->draftOrder([[$this->productId, 2]]);
        $this->signInAs($this->salesCreator);

        $response = $this->controller->submit($this->request($id, ['csrf_token' => 'x']));

        $this->assertRedirectWithFlash(
            $response,
            self::DETAIL . $id,
            'success',
            'Sales order submitted for approval.',
        );
        self::assertSame(SalesOrderStatus::PendingApproval, $this->requireOrder($id)->status);
    }

    #[Test]
    public function cancellingADraftMarksItCancelled(): void
    {
        $id = $this->draftOrder([[$this->productId, 2]]);
        $this->signInAs($this->salesCreator);

        $response = $this->controller->cancel($this->request($id, ['csrf_token' => 'x']));

        $this->assertRedirectWithFlash($response, self::DETAIL . $id, 'success', 'Sales order cancelled.');
        self::assertSame(SalesOrderStatus::Cancelled, $this->requireOrder($id)->status);
    }

    #[Test]
    public function submittingAnApprovedOrderComesBackAsAnErrorFlash(): void
    {
        $id = $this->approvedOrder([[$this->productId, 2]]);
        $this->signInAs($this->salesCreator);

        $response = $this->controller->submit($this->request($id, ['csrf_token' => 'x']));

        self::assertSame(302, $response->statusCode());
        self::assertSame('error', $this->session->pullFlash()['type'] ?? null);
    }

    // --------------------------------------------------------------- helper

    /** @return array<string, mixed> */
    private function payload(int $quantity): array
    {
        return [
            'csrf_token'   => 'x',
            'customer_id'  => (string) $this->customerId,
            'warehouse_id' => (string) $this->warehouseId,
            'order_date'   => date('Y-m-d'),
            'items'        => [
                ['product_id' => (string) $this->productId, 'quantity' => (string) $quantity],
                ['product_id' => '', 'quantity' => ''],
            ],
        ];
    }

    private function requireOrder(int $id): SalesOrder
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
