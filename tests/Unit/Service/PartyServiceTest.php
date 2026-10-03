<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Entity\Customer;
use App\Entity\Supplier;
use App\Repository\CustomerRepositoryInterface;
use App\Repository\SupplierRepositoryInterface;
use App\Service\PartyService;
use App\Support\Exception\NotFoundException;
use App\Support\Exception\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Fake\InMemoryCustomerRepository;
use Tests\Unit\Fake\InMemorySupplierRepository;

/**
 * Unit test PartyService (Supplier dan Customer).
 *
 * Keduanya dijaga sebagai entity TERPISAH sesuai data-model.md, walaupun
 * field-nya identik. Test ini juga mengunci pemisahan itu.
 */
final class PartyServiceTest extends TestCase
{
    private InMemorySupplierRepository $suppliers;
    private InMemoryCustomerRepository $customers;
    private PartyService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->suppliers = new InMemorySupplierRepository([
            new Supplier(1, 'PT Sinar Jaya', '021-500-1000', 'Jl. Industri No. 10', true),
        ]);

        $this->customers = new InMemoryCustomerRepository([
            new Customer(1, 'PT Bank Wijaya', '021-700-2000', 'Jl. Merdeka No. 5', true),
        ]);

        $this->service = new PartyService($this->suppliers, $this->customers);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function validData(array $overrides = []): array
    {
        return array_merge([
            'name'    => 'CV Mitra Baru',
            'contact' => '022-555-1234',
            'address' => 'Jl. Baru No. 1, Bandung',
        ], $overrides);
    }

    // ---------------------------------------------------------- supplier

    #[Test]
    public function createsASupplier(): void
    {
        $id = $this->service->createSupplier($this->validData());
        $created = $this->suppliers->findById($id);

        self::assertNotNull($created);
        self::assertSame('CV Mitra Baru', $created->name);
        self::assertTrue($created->isActive);
    }

    #[Test]
    public function updatesASupplierAndKeepsItsActiveState(): void
    {
        $this->service->toggleSupplierActive(1);
        $this->service->updateSupplier(1, $this->validData(['name' => 'Renamed Supplier']));

        $updated = $this->suppliers->findById(1);
        self::assertNotNull($updated);
        self::assertSame('Renamed Supplier', $updated->name);
        self::assertFalse($updated->isActive, 'Update tidak boleh diam-diam mengaktifkan kembali');
    }

    #[Test]
    public function updatesACustomerWithoutTouchingItsActiveState(): void
    {
        // Cerminan aturan yang sama pada Supplier: status punya toggle-nya
        // sendiri, sehingga form edit tidak boleh mengubahnya diam-diam.
        $this->service->toggleCustomerActive(1);

        $this->service->updateCustomer(1, $this->validData(['name' => 'PT Bank Wijaya Nusantara']));

        $updated = $this->customers->findById(1);

        self::assertNotNull($updated);
        self::assertSame('PT Bank Wijaya Nusantara', $updated->name);
        self::assertFalse($updated->isActive, 'Update tidak boleh diam-diam mengaktifkan kembali');
    }

    #[Test]
    public function countsReflectWhatIsStored(): void
    {
        // Dipakai halaman daftar untuk paging; angkanya harus mengikuti data.
        self::assertSame(1, $this->service->countCustomers([]));
        self::assertSame(1, $this->service->countSuppliers([]));

        $this->service->createCustomer($this->validData(['name' => 'RS Harapan Sehat']));
        $this->service->createSupplier($this->validData(['name' => 'PT Sumber Baru']));

        self::assertSame(2, $this->service->countCustomers([]));
        self::assertSame(2, $this->service->countSuppliers([]));
    }

    #[Test]
    public function activeCustomersExcludeTheDeactivatedOnes(): void
    {
        self::assertCount(1, $this->service->activeCustomers());

        $this->service->toggleCustomerActive(1);

        self::assertSame([], $this->service->activeCustomers());
    }

    #[Test]
    public function rejectsASupplierWithMissingFields(): void
    {
        try {
            $this->service->createSupplier(['name' => '', 'contact' => '', 'address' => '']);
            self::fail('Field kosong seharusnya ditolak');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('name', $e->errors());
            self::assertArrayHasKey('contact', $e->errors());
            self::assertArrayHasKey('address', $e->errors());
        }
    }

    /**
     * §1.3: supplier dinonaktifkan, bukan dihapus - termasuk yang sudah
     * dipakai Purchase Order.
     */
    #[Test]
    public function aSupplierUsedByAPurchaseOrderIsReportedAsReferencedAndStillDeactivates(): void
    {
        $this->suppliers->markReferenced(1);

        self::assertTrue($this->service->isSupplierReferenced(1));

        $this->service->toggleSupplierActive(1);

        $after = $this->suppliers->findById(1);
        self::assertNotNull($after, 'Supplier harus tetap ada setelah dinonaktifkan');
        self::assertFalse($after->isActive);
    }

    #[Test]
    public function activeSuppliersExcludeDeactivatedOnes(): void
    {
        $this->service->toggleSupplierActive(1);

        self::assertSame([], $this->service->activeSuppliers());
    }

    #[Test]
    public function anUnknownSupplierIsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->service->requireSupplier(999);
    }

    // ---------------------------------------------------------- customer

    #[Test]
    public function createsACustomer(): void
    {
        $id = $this->service->createCustomer($this->validData(['name' => 'RS Harapan']));
        $created = $this->customers->findById($id);

        self::assertNotNull($created);
        self::assertSame('RS Harapan', $created->name);
    }

    #[Test]
    public function aCustomerUsedBySalesOrderIsReportedAsReferencedAndStillDeactivates(): void
    {
        $this->customers->markReferenced(1);

        self::assertTrue($this->service->isCustomerReferenced(1));

        $this->service->toggleCustomerActive(1);

        $after = $this->customers->findById(1);
        self::assertNotNull($after, 'Customer harus tetap ada setelah dinonaktifkan');
        self::assertFalse($after->isActive);
    }

    #[Test]
    public function rejectsACustomerWithMissingFields(): void
    {
        $this->expectException(ValidationException::class);
        $this->service->createCustomer(['name' => '', 'contact' => '', 'address' => '']);
    }

    #[Test]
    public function anUnknownCustomerIsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->service->requireCustomer(999);
    }

    // --------------------------------------------------- tetap terpisah

    /**
     * Supplier dan Customer TIDAK di-merge (data-model.md). Membuat supplier
     * tidak boleh memunculkan customer, dan sebaliknya.
     */
    #[Test]
    public function supplierAndCustomerRemainSeparateCollections(): void
    {
        $this->service->createSupplier($this->validData(['name' => 'Only A Supplier']));

        self::assertCount(2, $this->service->searchSuppliers([], 10, 0));
        self::assertCount(1, $this->service->searchCustomers([], 10, 0));

        $customerNames = array_map(
            static fn (Customer $c): string => $c->name,
            $this->service->searchCustomers([], 10, 0),
        );

        self::assertNotContains('Only A Supplier', $customerNames);
    }

    /**
     * Kedua repository berada di balik interface yang berbeda - bukan satu
     * interface bersama dengan discriminator.
     */
    #[Test]
    public function theTwoPartiesAreBackedByDistinctRepositoryContracts(): void
    {
        self::assertNotSame(SupplierRepositoryInterface::class, CustomerRepositoryInterface::class);
        self::assertInstanceOf(SupplierRepositoryInterface::class, $this->suppliers);
        self::assertInstanceOf(CustomerRepositoryInterface::class, $this->customers);
        self::assertNotInstanceOf(CustomerRepositoryInterface::class, $this->suppliers);
    }
}
