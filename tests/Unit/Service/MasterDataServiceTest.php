<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Entity\Category;
use App\Entity\Warehouse;
use App\Service\MasterDataService;
use App\Support\Exception\NotFoundException;
use App\Support\Exception\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Tests\Unit\Fake\InMemoryCategoryRepository;
use Tests\Unit\Fake\InMemoryWarehouseRepository;

/**
 * Unit test MasterDataService (FR-009, FR-010).
 *
 * Category dan Warehouse adalah master data yang dirujuk product dan order.
 * Aturannya sedikit tetapi nyata: nama wajib dan unik untuk category, nama dan
 * lokasi wajib untuk warehouse, dan warehouse dinonaktifkan — tidak pernah
 * dihapus — karena order lama tetap menunjuk ke sana.
 */
final class MasterDataServiceTest extends TestCase
{
    private InMemoryCategoryRepository $categories;
    private InMemoryWarehouseRepository $warehouses;
    private MasterDataService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->categories = new InMemoryCategoryRepository([
            new Category(1, 'Networking', 'Perangkat jaringan'),
            new Category(2, 'Power', null),
        ]);

        $this->warehouses = new InMemoryWarehouseRepository([
            new Warehouse(1, 'Gudang Pusat Jakarta', 'Jakarta', true),
            new Warehouse(2, 'Gudang Surabaya', 'Surabaya', false),
        ]);

        $this->service = new MasterDataService($this->categories, $this->warehouses);
    }

    // -------------------------------------------------------- Category

    #[Test]
    public function createsACategory(): void
    {
        $id = $this->service->createCategory(['name' => 'Storage', 'description' => 'Disk dan NAS']);

        $created = $this->service->requireCategory($id);

        self::assertSame('Storage', $created->name);
        self::assertSame('Disk dan NAS', $created->description);
    }

    #[Test]
    public function rejectsACategoryWithoutAName(): void
    {
        try {
            $this->service->createCategory(['name' => '   ']);
            self::fail('Category tanpa nama seharusnya ditolak.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('name', $e->errors());
        }
    }

    #[Test]
    public function rejectsADuplicateCategoryName(): void
    {
        try {
            $this->service->createCategory(['name' => 'Networking']);
            self::fail('Nama category yang sudah dipakai seharusnya ditolak.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('name', $e->errors());
        }
    }

    #[Test]
    public function rejectsACategoryNameLongerThanTheColumn(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->createCategory(['name' => str_repeat('a', 151)]);
    }

    #[Test]
    public function allowsACategoryToKeepItsOwnNameOnUpdate(): void
    {
        // Pemeriksaan keunikan harus mengecualikan baris yang sedang diubah,
        // kalau tidak menyimpan ulang tanpa mengganti nama akan gagal.
        $this->service->updateCategory(1, ['name' => 'Networking', 'description' => 'Diperbarui']);

        self::assertSame('Diperbarui', $this->service->requireCategory(1)->description);
    }

    #[Test]
    public function rejectsRenamingACategoryOntoAnotherExistingName(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->updateCategory(1, ['name' => 'Power']);
    }

    #[Test]
    public function anEmptyCategoryDescriptionIsStoredAsNullNotAnEmptyString(): void
    {
        $id = $this->service->createCategory(['name' => 'Kabel', 'description' => '   ']);

        self::assertNull($this->service->requireCategory($id)->description);
    }

    #[Test]
    public function aMissingCategoryDescriptionIsAccepted(): void
    {
        // Description memang opsional; ketiadaannya bukan error.
        $id = $this->service->createCategory(['name' => 'Aksesoris']);

        self::assertNull($this->service->requireCategory($id)->description);
    }

    #[Test]
    public function anUnknownCategoryIsNotFound(): void
    {
        $this->expectException(NotFoundException::class);

        $this->service->requireCategory(999);
    }

    #[Test]
    public function updatingAnUnknownCategoryIsNotFound(): void
    {
        $this->expectException(NotFoundException::class);

        $this->service->updateCategory(999, ['name' => 'Apa pun']);
    }

    #[Test]
    public function listsEveryCategory(): void
    {
        self::assertCount(2, $this->service->allCategories());
    }

    // ------------------------------------------------------- Warehouse

    #[Test]
    public function createsAWarehouseAsActive(): void
    {
        $id = $this->service->createWarehouse(['name' => 'Gudang Medan', 'location' => 'Medan']);

        $created = $this->service->requireWarehouse($id);

        self::assertSame('Gudang Medan', $created->name);
        self::assertSame('Medan', $created->location);
        self::assertTrue($created->isActive, 'Warehouse baru harus langsung aktif.');
    }

    #[Test]
    public function rejectsAWarehouseWithoutAName(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->createWarehouse(['name' => '', 'location' => 'Medan']);
    }

    #[Test]
    public function rejectsAWarehouseWithoutALocation(): void
    {
        try {
            $this->service->createWarehouse(['name' => 'Gudang Medan', 'location' => '  ']);
            self::fail('Warehouse tanpa lokasi seharusnya ditolak.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('location', $e->errors());
        }
    }

    #[Test]
    public function updatingAWarehouseNeverChangesItsActiveState(): void
    {
        // Status punya toggle-nya sendiri. Form edit tidak boleh diam-diam
        // menghidupkan kembali warehouse yang sengaja dinonaktifkan.
        $this->service->updateWarehouse(2, ['name' => 'Gudang Surabaya', 'location' => 'Sidoarjo']);

        $updated = $this->service->requireWarehouse(2);

        self::assertSame('Sidoarjo', $updated->location);
        self::assertFalse($updated->isActive);
    }

    #[Test]
    public function togglingFlipsTheActiveStateBothWays(): void
    {
        $this->service->toggleWarehouseActive(1);
        self::assertFalse($this->service->requireWarehouse(1)->isActive);

        $this->service->toggleWarehouseActive(1);
        self::assertTrue($this->service->requireWarehouse(1)->isActive);
    }

    #[Test]
    public function activeWarehousesExcludeTheDeactivatedOnes(): void
    {
        $active = $this->service->activeWarehouses();

        self::assertCount(1, $active);
        self::assertSame('Gudang Pusat Jakarta', $active[0]->name);
    }

    #[Test]
    public function allWarehousesIncludeTheDeactivatedOnes(): void
    {
        // Halaman administrasi tetap harus dapat melihat dan menghidupkan
        // kembali warehouse yang nonaktif.
        self::assertCount(2, $this->service->allWarehouses());
    }

    #[Test]
    public function anUnknownWarehouseIsNotFound(): void
    {
        $this->expectException(NotFoundException::class);

        $this->service->requireWarehouse(999);
    }

    #[Test]
    public function togglingAnUnknownWarehouseIsNotFound(): void
    {
        $this->expectException(NotFoundException::class);

        $this->service->toggleWarehouseActive(999);
    }

    #[Test]
    public function thereIsNoWayToDeleteMasterData(): void
    {
        // §1.3: master data dinonaktifkan, bukan dihapus — order lama tetap
        // menunjuk ke sana. Jalur penghapusannya memang tidak pernah dibuat.
        //
        // Diperiksa lewat reflection terhadap SELURUH method public, bukan
        // terhadap dua nama tertentu: `deletePermanently()` yang kelak
        // ditambahkan pun akan tertangkap.
        $names = array_map(
            static fn (ReflectionMethod $m): string => strtolower($m->getName()),
            (new ReflectionClass(MasterDataService::class))->getMethods(ReflectionMethod::IS_PUBLIC),
        );

        foreach ($names as $name) {
            self::assertStringNotContainsString('delete', $name, 'Master data tidak boleh punya jalur hapus.');
            self::assertStringNotContainsString('destroy', $name);
            self::assertStringNotContainsString('remove', $name);
        }
    }
}
