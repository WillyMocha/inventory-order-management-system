<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Customer;
use App\Entity\Supplier;
use App\Repository\CustomerRepositoryInterface;
use App\Repository\SupplierRepositoryInterface;
use App\Support\Exception\NotFoundException;
use App\Support\Validator;

/**
 * Supplier dan Customer.
 *
 * Keduanya adalah entity TERPISAH dengan repository terpisah pula
 * (data-model.md): brief menuliskannya pada satu baris tabel hanya karena
 * field-nya identik, bukan karena keduanya satu resource. Relasinya berbeda -
 * Supplier ke Purchase Order, Customer ke Sales Order - dan tidak ada endpoint
 * yang memperlakukan keduanya sebagai satu collection.
 *
 * Yang dibagikan di sini hanyalah aturan validasinya, karena bentuk field-nya
 * memang sama. Datanya tidak pernah bercampur.
 *
 * Seperti Product, tidak ada operasi delete: §1.3 menyatakan supplier dan
 * customer dinonaktifkan, bukan dihapus permanen.
 */
final class PartyService
{
    public function __construct(
        private readonly SupplierRepositoryInterface $suppliers,
        private readonly CustomerRepositoryInterface $customers,
    ) {
    }

    // ------------------------------------------------------------ Supplier

    /**
     * @param array<string, mixed> $data
     *
     * @throws \App\Support\Exception\ValidationException
     */
    public function createSupplier(array $data): int
    {
        $this->validateParty($data);

        return $this->suppliers->save(new Supplier(
            null,
            trim((string) $data['name']),
            trim((string) $data['contact']),
            trim((string) $data['address']),
            true,
        ));
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws NotFoundException
     * @throws \App\Support\Exception\ValidationException
     */
    public function updateSupplier(int $id, array $data): void
    {
        $existing = $this->requireSupplier($id);
        $this->validateParty($data);

        $this->suppliers->save(new Supplier(
            $id,
            trim((string) $data['name']),
            trim((string) $data['contact']),
            trim((string) $data['address']),
            // Status aktif punya aksinya sendiri; update tidak boleh diam-diam
            // mengaktifkan kembali record yang sengaja dinonaktifkan.
            $existing->isActive,
        ));
    }

    /** @throws NotFoundException */
    public function toggleSupplierActive(int $id): void
    {
        $supplier = $this->requireSupplier($id);

        $this->suppliers->setActive($id, !$supplier->isActive);
    }

    public function isSupplierReferenced(int $id): bool
    {
        return $this->suppliers->isReferencedByOrder($id);
    }

    /** @return list<Supplier> */
    public function activeSuppliers(): array
    {
        return $this->suppliers->allActive();
    }

    /**
     * @param array{search?: string, active?: bool} $criteria
     * @return list<Supplier>
     */
    public function searchSuppliers(array $criteria, int $limit, int $offset): array
    {
        return $this->suppliers->search($criteria, $limit, $offset);
    }

    /** @param array{search?: string, active?: bool} $criteria */
    public function countSuppliers(array $criteria): int
    {
        return $this->suppliers->countBy($criteria);
    }

    /** @throws NotFoundException */
    public function requireSupplier(int $id): Supplier
    {
        $supplier = $this->suppliers->findById($id);

        if ($supplier === null) {
            throw new NotFoundException();
        }

        return $supplier;
    }

    // ------------------------------------------------------------ Customer

    /**
     * @param array<string, mixed> $data
     *
     * @throws \App\Support\Exception\ValidationException
     */
    public function createCustomer(array $data): int
    {
        $this->validateParty($data);

        return $this->customers->save(new Customer(
            null,
            trim((string) $data['name']),
            trim((string) $data['contact']),
            trim((string) $data['address']),
            true,
        ));
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws NotFoundException
     * @throws \App\Support\Exception\ValidationException
     */
    public function updateCustomer(int $id, array $data): void
    {
        $existing = $this->requireCustomer($id);
        $this->validateParty($data);

        $this->customers->save(new Customer(
            $id,
            trim((string) $data['name']),
            trim((string) $data['contact']),
            trim((string) $data['address']),
            $existing->isActive,
        ));
    }

    /** @throws NotFoundException */
    public function toggleCustomerActive(int $id): void
    {
        $customer = $this->requireCustomer($id);

        $this->customers->setActive($id, !$customer->isActive);
    }

    public function isCustomerReferenced(int $id): bool
    {
        return $this->customers->isReferencedByOrder($id);
    }

    /** @return list<Customer> */
    public function activeCustomers(): array
    {
        return $this->customers->allActive();
    }

    /**
     * @param array{search?: string, active?: bool} $criteria
     * @return list<Customer>
     */
    public function searchCustomers(array $criteria, int $limit, int $offset): array
    {
        return $this->customers->search($criteria, $limit, $offset);
    }

    /** @param array{search?: string, active?: bool} $criteria */
    public function countCustomers(array $criteria): int
    {
        return $this->customers->countBy($criteria);
    }

    /** @throws NotFoundException */
    public function requireCustomer(int $id): Customer
    {
        $customer = $this->customers->findById($id);

        if ($customer === null) {
            throw new NotFoundException();
        }

        return $customer;
    }

    // ------------------------------------------------------------- shared

    /**
     * Aturan validasi yang sama untuk kedua entity - bentuk field-nya memang
     * identik. Ini berbagi ATURAN, bukan berbagi data maupun tabel.
     *
     * @param array<string, mixed> $data
     */
    private function validateParty(array $data): void
    {
        Validator::make($data)
            ->required('name', 'Name')
            ->maxLength('name', 'Name', 150)
            ->required('contact', 'Contact')
            ->maxLength('contact', 'Contact', 150)
            ->required('address', 'Address')
            ->validate();
    }
}
