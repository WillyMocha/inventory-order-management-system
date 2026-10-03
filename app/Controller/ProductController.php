<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Enum\Role;
use App\Service\MasterDataService;
use App\Service\ProductImageService;
use App\Service\ProductService;
use App\Service\StockService;
use App\Support\Csrf;
use App\Support\Exception\NotFoundException;
use App\Support\Exception\ValidationException;
use App\Support\Paginator;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;
use App\Support\View;
use RuntimeException;

/**
 * Katalog product (PRD-01, WH-01).
 *
 * Baca terbuka untuk seluruh role; tulis hanya Admin. Pembatasan itu
 * ditegakkan route table dan Authorization guard sebelum request sampai ke
 * sini.
 */
final class ProductController
{
    /** Key query string yang dibawa link pagination dan sort (FIND-01). */
    private const array FILTER_KEYS = ['search', 'category', 'stock', 'sort', 'direction'];

    /**
     * Key sort yang boleh muncul di query string. Pemetaan ke nama kolom
     * dilakukan allowlist MysqlProductRepository::SORTABLE; daftar di sini
     * menjaga query string tetap bersih dan membuat indikator kolom aktif pada
     * view tidak menyala untuk key yang tidak dikenal.
     */
    private const array SORT_KEYS = ['name', 'sku', 'price'];

    public function __construct(
        private readonly View $view,
        private readonly ProductService $productService,
        private readonly ProductImageService $imageService,
        private readonly MasterDataService $masterData,
        private readonly StockService $stockService,
        private readonly Session $session,
        private readonly Csrf $csrf,
    ) {
    }

    public function index(Request $request): Response
    {
        $criteria = $this->criteriaFrom($request);
        $filters = $request->queryState(self::FILTER_KEYS);

        // Sort dipisahkan dari filter: menghitung total tidak peduli urutan,
        // dan countBy() memang tidak menerima key sort.
        $sorted = $criteria + $request->sortCriteria(self::SORT_KEYS, 'asc');

        $paginator = new Paginator($this->productService->count($criteria), $request->queryInt('page', 1), $filters);
        $products = $this->productService->search($sorted, $paginator->perPage(), $paginator->offset());

        // Total stock per product dibutuhkan pada baris tabel; diambil sekali
        // per product yang tampil (maksimal 10 baris).
        $totals = [];
        foreach ($products as $product) {
            $totals[(int) $product->id] = $this->productService->totalStockFor((int) $product->id);
        }

        return Response::html($this->view->render('products/index', [
            'title'      => 'Products',
            'activeNav'  => 'products',
            'products'   => $products,
            'totals'     => $totals,
            'paginator'  => $paginator,
            'basePath'   => '/products',
            'filters'    => $filters,
            'hasFilters' => $filters !== [],
            'categories' => $this->masterData->allCategories(),
            'categoryNames' => $this->categoryNames(),
            'summary'    => $this->summary(),
            'canManage'  => $this->session->role()?->value === 'Admin',
        ]));
    }

    public function show(Request $request): Response
    {
        $breakdown = $this->productService->stockBreakdown($this->requireId($request));
        $product = $breakdown['product'];
        // Tombol koreksi dan riwayatnya untuk Admin dan Warehouse Staff saja
        // (spec 003 A-009). Presentasi: route table dan StockService yang menegakkan.
        $canAdjust = in_array($this->session->role(), [Role::Admin, Role::WarehouseStaff], true);

        return Response::html($this->view->render('products/detail', [
            'title'      => $product->name,
            'activeNav'  => 'products',
            'product'    => $product,
            'breakdown'  => $breakdown,
            'category'   => $this->masterData->requireCategory($product->categoryId),
            'referenced' => $this->productService->isReferencedByOrder((int) $product->id),
            'canManage'  => $this->session->role()?->value === 'Admin',
            'canAdjust'  => $canAdjust,
            // Query riwayat tidak dijalankan untuk Sales.
            'adjustments' => $canAdjust ? $this->stockService->recentAdjustments((int) $product->id) : [],
            'csrf'       => $this->csrf,
        ]));
    }

    public function create(Request $request): Response
    {
        return Response::html($this->renderForm());
    }

    public function store(Request $request): Response
    {
        try {
            $id = $this->productService->create($request->bodyAll());
            $this->attachImageIfPresent($request, $id, null);
        } catch (ValidationException $e) {
            return Response::html($this->renderForm(null, $request->bodyAll(), $e->errors()), 422);
        }

        $this->session->flash('success', 'Product created.');

        return Response::redirect('/products/' . $id);
    }

    public function edit(Request $request): Response
    {
        $product = $this->productService->requireProduct($this->requireId($request));

        return Response::html($this->renderForm($product));
    }

    public function update(Request $request): Response
    {
        $id = $this->requireId($request);
        $product = $this->productService->requireProduct($id);

        try {
            $this->productService->update($id, $request->bodyAll());
            $this->attachImageIfPresent($request, $id, $product->imagePath);
        } catch (ValidationException $e) {
            return Response::html($this->renderForm($product, $request->bodyAll(), $e->errors()), 422);
        }

        $this->session->flash('success', 'Product updated.');

        return Response::redirect('/products/' . $id);
    }

    public function toggleActive(Request $request): Response
    {
        $id = $this->requireId($request);

        $this->productService->toggleActive($id);
        $this->session->flash('success', 'Product status updated.');

        return Response::redirect('/products/' . $id);
    }

    /**
     * Menyajikan image product dari LUAR document root.
     *
     * Inilah yang membuat file yang diunggah tidak pernah dapat dieksekusi
     * sebagai script: file-nya tidak berada di direktori yang disajikan web
     * server (research R-006).
     */
    public function image(Request $request): Response
    {
        $product = $this->productService->requireProduct($this->requireId($request));

        if (!$product->hasImage()) {
            throw new NotFoundException();
        }

        try {
            $file = $this->imageService->read((string) $product->imagePath);
        } catch (ValidationException | RuntimeException) {
            // Referensi image rusak bukan alasan menampilkan error teknis.
            throw new NotFoundException();
        }

        return Response::file($file['contents'], $file['mime']);
    }

    /**
     * Image bersifat opsional (PRD-01): tidak ada file berarti tidak ada
     * perubahan, bukan kegagalan.
     */
    private function attachImageIfPresent(Request $request, int $productId, ?string $previousImage): void
    {
        $file = $request->file('image');

        if ($file === null || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return;
        }

        $storedName = $this->imageService->store($file);
        $this->productService->updateImagePath($productId, $storedName);

        // File lama dihapus hanya setelah yang baru berhasil tersimpan.
        $this->imageService->delete($previousImage);
    }

    /**
     * @param array<string, mixed> $old
     * @param array<string, string> $errors
     */
    private function renderForm(?object $product = null, array $old = [], array $errors = []): string
    {
        return $this->view->render('products/form', [
            'title'      => $product === null ? 'Create product' : 'Edit product',
            'activeNav'  => 'products',
            'product'    => $product,
            'old'        => $old,
            'errors'     => $errors,
            'categories' => $this->masterData->allCategories(),
            'csrf'       => $this->csrf,
        ]);
    }

    /**
     * Peta id => nama category, agar baris tabel tidak perlu satu query per
     * baris untuk menampilkan nama category-nya.
     *
     * @return array<int, string>
     */
    private function categoryNames(): array
    {
        $names = [];

        foreach ($this->masterData->allCategories() as $category) {
            $names[(int) $category->id] = $category->name;
        }

        return $names;
    }

    /** @return array{total: int, lowStock: int, inventoryValue: string} */
    private function summary(): array
    {
        return [
            'total'          => $this->productService->count(['active' => true]),
            'lowStock'       => $this->productService->count(['active' => true, 'lowStock' => true]),
            'inventoryValue' => $this->productService->totalInventoryValue(),
        ];
    }

    /** @return array{search?: string, categoryId?: int, lowStock?: bool, active?: bool} */
    private function criteriaFrom(Request $request): array
    {
        $criteria = [];

        if ($request->queryString('search') !== '') {
            $criteria['search'] = $request->queryString('search');
        }

        if ($request->queryInt('category') > 0) {
            $criteria['categoryId'] = $request->queryInt('category');
        }

        $stock = $request->queryString('stock');
        if ($stock === 'low') {
            $criteria['lowStock'] = true;
        } elseif ($stock === 'normal') {
            $criteria['lowStock'] = false;
        }

        return $criteria;
    }

    private function requireId(Request $request): int
    {
        $id = $request->routeParamInt('id');

        if ($id === null) {
            throw new NotFoundException();
        }

        return $id;
    }
}
