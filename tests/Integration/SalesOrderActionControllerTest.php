<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Controller\GoodsIssueController;
use App\Controller\SalesOrderApprovalController;
use App\Entity\Enum\SalesOrderStatus;
use App\Repository\Mysql\MysqlSalesOrderRepository;
use App\Support\Exception\ForbiddenException;
use App\Support\Exception\NotFoundException;
use App\Support\Exception\UnauthenticatedException;
use PHPUnit\Framework\Attributes\Test;

/**
 * Lapisan HTTP approval dan goods issue Sales Order terhadap MySQL sungguhan.
 *
 * Kedua controller dipisahkan dari SalesOrderController pada tech-debt TD-11.
 * Aturan bisnisnya (D-01, kecukupan stock) diuji di unit test service; di sini
 * yang dibuktikan adalah terjemahannya ke HTTP: redirect ke detail beserta
 * flash, 422 dengan form dirender ulang, dan exception yang dibiarkan sampai ke
 * front controller (403/404/login).
 */
final class SalesOrderActionControllerTest extends IntegrationTestCase
{
    use SalesOrderFixtures;
    use OrderControllerHarness;

    private const string DETAIL = '/sales-orders/';

    private SalesOrderApprovalController $approvals;
    private GoodsIssueController $issues;
    private MysqlSalesOrderRepository $orders;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedSalesOrderFixtures();
        $this->wireOrderServices();
        $this->orders = new MysqlSalesOrderRepository($this->database);

        $this->approvals = new SalesOrderApprovalController($this->approvalService, $this->userService, $this->session);
        $this->issues = new GoodsIssueController(
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

    // ------------------------------------------------------- approve/reject

    #[Test]
    public function approvingAPendingOrderRedirectsToItsDetailWithSuccess(): void
    {
        $id = $this->pendingOrder();
        $this->signInAs($this->admin);

        $response = $this->approvals->approve($this->request($id, ['csrf_token' => 'x']));

        $this->assertRedirectWithFlash($response, self::DETAIL . $id, 'success', 'Sales order approved.');
        self::assertSame(SalesOrderStatus::Approved, $this->statusOf($id));
    }

    #[Test]
    public function rejectingAPendingOrderCancelsIt(): void
    {
        $id = $this->pendingOrder();
        $this->signInAs($this->admin);

        $response = $this->approvals->reject($this->request($id, ['csrf_token' => 'x']));

        $this->assertRedirectWithFlash($response, self::DETAIL . $id, 'success', 'Sales order rejected and cancelled.');
        self::assertSame(SalesOrderStatus::Cancelled, $this->statusOf($id));
    }

    #[Test]
    public function aDecisionOnAnOrderThatIsNotPendingComesBackAsAnErrorFlash(): void
    {
        $id = $this->draftOrder([[$this->productId, 1]]);
        $this->signInAs($this->admin);

        $response = $this->approvals->approve($this->request($id, ['csrf_token' => 'x']));

        self::assertSame(302, $response->statusCode());
        self::assertSame(self::DETAIL . $id, $response->header('Location'));
        self::assertSame('error', $this->session->pullFlash()['type'] ?? null);
        self::assertSame(SalesOrderStatus::Draft, $this->statusOf($id));
    }

    #[Test]
    public function aSalesUserIsRefusedByTheServiceNotJustTheRoute(): void
    {
        $id = $this->pendingOrder();
        $this->signInAs($this->salesCreator);

        $this->expectException(ForbiddenException::class);
        $this->approvals->approve($this->request($id, ['csrf_token' => 'x']));
    }

    #[Test]
    public function aDecisionWithoutASessionIsUnauthenticated(): void
    {
        $id = $this->pendingOrder();

        $this->expectException(UnauthenticatedException::class);
        $this->approvals->reject($this->request($id, ['csrf_token' => 'x']));
    }

    #[Test]
    public function aDecisionWithoutAnOrderIdIsNotFound(): void
    {
        $this->signInAs($this->admin);

        $this->expectException(NotFoundException::class);
        $this->approvals->approve($this->request(null, ['csrf_token' => 'x']));
    }

    // ---------------------------------------------------------- goods issue

    #[Test]
    public function theIssueFormListsEachLineWithTheStockAvailable(): void
    {
        $id = $this->approvedOrder([[$this->productId, 3]]);
        $this->setStock($this->productId, $this->warehouseId, 10);
        $this->signInAs($this->warehouseStaff);

        $response = $this->issues->issueForm($this->request($id));

        self::assertSame(200, $response->statusCode());
        self::assertStringContainsString('Fixture Product One', $response->body());
        self::assertStringContainsString('Fixture Warehouse Site', $response->body());
    }

    #[Test]
    public function theIssueFormOfAnOrderThatIsNotApprovedRedirectsBack(): void
    {
        $id = $this->draftOrder([[$this->productId, 3]]);
        $this->signInAs($this->warehouseStaff);

        $response = $this->issues->issueForm($this->request($id));

        $this->assertRedirectWithFlash($response, self::DETAIL . $id, 'error', 'Only an approved order can be issued.');
    }

    #[Test]
    public function issuingGoodsReducesStockAndRedirectsWithSuccess(): void
    {
        $id = $this->approvedOrder([[$this->productId, 3]]);
        $this->setStock($this->productId, $this->warehouseId, 10);
        $this->signInAs($this->warehouseStaff);

        $response = $this->issues->issue($this->request($id, ['csrf_token' => 'x']));

        $this->assertRedirectWithFlash(
            $response,
            self::DETAIL . $id,
            'success',
            'Goods issued. Stock and ledger updated.',
        );
        self::assertSame(7, $this->stockQuantity($this->productId, $this->warehouseId));
        self::assertSame(SalesOrderStatus::Fulfilled, $this->statusOf($id));
    }

    #[Test]
    public function insufficientStockReRendersTheFormWith422AndChangesNothing(): void
    {
        $id = $this->approvedOrder([[$this->productId, 3]]);
        $this->setStock($this->productId, $this->warehouseId, 1);
        $this->signInAs($this->warehouseStaff);

        $response = $this->issues->issue($this->request($id, ['csrf_token' => 'x']));

        self::assertSame(422, $response->statusCode());
        self::assertStringContainsString('Fixture Product One', $response->body());
        self::assertSame(1, $this->stockQuantity($this->productId, $this->warehouseId));
        self::assertSame(SalesOrderStatus::Approved, $this->statusOf($id));
    }

    #[Test]
    public function issuingWithoutASessionIsUnauthenticated(): void
    {
        $id = $this->approvedOrder([[$this->productId, 3]]);

        $this->expectException(UnauthenticatedException::class);
        $this->issues->issue($this->request($id, ['csrf_token' => 'x']));
    }

    #[Test]
    public function theIssueFormWithoutAnOrderIdIsNotFound(): void
    {
        $this->signInAs($this->warehouseStaff);

        $this->expectException(NotFoundException::class);
        $this->issues->issueForm($this->request());
    }

    // --------------------------------------------------------------- helper

    private function pendingOrder(): int
    {
        $id = $this->draftOrder([[$this->productId, 2]]);
        $this->orders->updateStatus($id, SalesOrderStatus::Draft, SalesOrderStatus::PendingApproval);

        return $id;
    }

    private function statusOf(int $id): SalesOrderStatus
    {
        $order = $this->orders->findById($id);
        self::assertNotNull($order);

        return $order->status;
    }
}
