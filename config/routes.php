<?php

/**
 * Route table lengkap, diambil dari
 * specs/001-inventory-order-management/contracts/http-routes.md.
 *
 * Setiap entry mencantumkan role yang diizinkan secara EKSPLISIT. Ini adalah
 * sumber kebenaran authorization — guard membaca daftar ini, sehingga hak
 * akses tidak tersebar di dalam controller.
 *
 * Konvensi nilai roles:
 *   null  = route publik (hanya halaman login)
 *   []    = tidak ada yang boleh (deny by default; tidak dipakai saat ini)
 *   [...] = daftar role yang diizinkan
 *
 * CATATAN: sebagian controller baru dibuat pada Phase 3 dan seterusnya.
 * Route-nya sudah didaftarkan lengkap di sini agar matriks authorization utuh
 * sejak awal; router mengembalikan 404 yang aman bila kelas controller-nya
 * belum ada, dan mencatatnya ke server log.
 */

declare(strict_types=1);

use App\Entity\Enum\Role;
use App\Support\Router;

$admin = Role::Admin;
$sales = Role::Sales;
$warehouse = Role::WarehouseStaff;

$all = [$admin, $sales, $warehouse];
$adminOnly = [$admin];
$adminWarehouse = [$admin, $warehouse];
$adminSales = [$admin, $sales];

return static function (Router $router) use ($all, $adminOnly, $adminWarehouse, $adminSales): void {
    // ---------------------------------------------------------------------
    // Authentication
    // Tidak ada registrasi publik dan tidak ada password reset (spec A-012).
    // ---------------------------------------------------------------------
    $router->add('GET', '/login', 'AuthController', 'showLogin', null);
    $router->add('POST', '/login', 'AuthController', 'login', null);
    $router->add('POST', '/logout', 'AuthController', 'logout', $all);

    // ---------------------------------------------------------------------
    // Dashboard
    // ---------------------------------------------------------------------
    $router->add('GET', '/', 'DashboardController', 'index', $all);
    $router->add('GET', '/dashboard', 'DashboardController', 'index', $all);

    // ---------------------------------------------------------------------
    // User management (USR-01) - Admin saja
    // ---------------------------------------------------------------------
    $router->add('GET', '/users', 'UserController', 'index', $adminOnly);
    $router->add('GET', '/users/create', 'UserController', 'create', $adminOnly);
    $router->add('POST', '/users', 'UserController', 'store', $adminOnly);
    $router->add('GET', '/users/{id}/edit', 'UserController', 'edit', $adminOnly);
    $router->add('POST', '/users/{id}', 'UserController', 'update', $adminOnly);
    $router->add('POST', '/users/{id}/toggle-active', 'UserController', 'toggleActive', $adminOnly);
    // Step-up re-auth: Admin wajib memasukkan ulang password miliknya (§7).
    $router->add('POST', '/users/{id}/password', 'UserController', 'changePassword', $adminOnly);

    // ---------------------------------------------------------------------
    // Product (PRD-01) - baca untuk semua role, tulis Admin saja
    // ---------------------------------------------------------------------
    $router->add('GET', '/products', 'ProductController', 'index', $all);
    $router->add('GET', '/products/create', 'ProductController', 'create', $adminOnly);
    $router->add('POST', '/products', 'ProductController', 'store', $adminOnly);
    $router->add('GET', '/products/{id}', 'ProductController', 'show', $all);
    $router->add('GET', '/products/{id}/edit', 'ProductController', 'edit', $adminOnly);
    $router->add('POST', '/products/{id}', 'ProductController', 'update', $adminOnly);
    $router->add('POST', '/products/{id}/toggle-active', 'ProductController', 'toggleActive', $adminOnly);
    // Menyajikan file dari luar document root (research R-006).
    $router->add('GET', '/products/{id}/image', 'ProductController', 'image', $all);

    // ---------------------------------------------------------------------
    // Category - Admin saja
    // ---------------------------------------------------------------------
    $router->add('GET', '/categories', 'CategoryController', 'index', $adminOnly);
    $router->add('GET', '/categories/create', 'CategoryController', 'create', $adminOnly);
    $router->add('POST', '/categories', 'CategoryController', 'store', $adminOnly);
    $router->add('GET', '/categories/{id}/edit', 'CategoryController', 'edit', $adminOnly);
    $router->add('POST', '/categories/{id}', 'CategoryController', 'update', $adminOnly);

    // ---------------------------------------------------------------------
    // Warehouse (WH-01) - Warehouse Staff hanya membaca
    // ---------------------------------------------------------------------
    $router->add('GET', '/warehouses', 'WarehouseController', 'index', $adminWarehouse);
    $router->add('GET', '/warehouses/create', 'WarehouseController', 'create', $adminOnly);
    $router->add('POST', '/warehouses', 'WarehouseController', 'store', $adminOnly);
    $router->add('GET', '/warehouses/{id}/edit', 'WarehouseController', 'edit', $adminOnly);
    $router->add('POST', '/warehouses/{id}', 'WarehouseController', 'update', $adminOnly);
    $router->add('POST', '/warehouses/{id}/toggle-active', 'WarehouseController', 'toggleActive', $adminOnly);

    // ---------------------------------------------------------------------
    // Supplier - Admin saja
    // ---------------------------------------------------------------------
    $router->add('GET', '/suppliers', 'SupplierController', 'index', $adminOnly);
    $router->add('GET', '/suppliers/create', 'SupplierController', 'create', $adminOnly);
    $router->add('POST', '/suppliers', 'SupplierController', 'store', $adminOnly);
    $router->add('GET', '/suppliers/{id}/edit', 'SupplierController', 'edit', $adminOnly);
    $router->add('POST', '/suppliers/{id}', 'SupplierController', 'update', $adminOnly);
    $router->add('POST', '/suppliers/{id}/toggle-active', 'SupplierController', 'toggleActive', $adminOnly);

    // ---------------------------------------------------------------------
    // Customer - Sales boleh membaca (dibutuhkan saat membuat Sales Order)
    // ---------------------------------------------------------------------
    $router->add('GET', '/customers', 'CustomerController', 'index', $adminSales);
    $router->add('GET', '/customers/create', 'CustomerController', 'create', $adminOnly);
    $router->add('POST', '/customers', 'CustomerController', 'store', $adminOnly);
    $router->add('GET', '/customers/{id}/edit', 'CustomerController', 'edit', $adminOnly);
    $router->add('POST', '/customers/{id}', 'CustomerController', 'update', $adminOnly);
    $router->add('POST', '/customers/{id}/toggle-active', 'CustomerController', 'toggleActive', $adminOnly);

    // ---------------------------------------------------------------------
    // Purchase Order & goods receipt (PO-01)
    // Sales menerima 403 pada seluruh route ini.
    // Perhatikan: cancel HANYA Admin - role-nya per aksi, tidak seragam.
    // ---------------------------------------------------------------------
    $router->add('GET', '/purchase-orders', 'PurchaseOrderController', 'index', $adminWarehouse);
    $router->add('GET', '/purchase-orders/create', 'PurchaseOrderController', 'create', $adminWarehouse);
    $router->add('POST', '/purchase-orders', 'PurchaseOrderController', 'store', $adminWarehouse);
    $router->add('GET', '/purchase-orders/{id}', 'PurchaseOrderController', 'show', $adminWarehouse);
    $router->add('POST', '/purchase-orders/{id}/submit', 'PurchaseOrderController', 'submit', $adminWarehouse);
    $router->add('POST', '/purchase-orders/{id}/cancel', 'PurchaseOrderController', 'cancel', $adminOnly);
    $router->add('GET', '/purchase-orders/{id}/receive', 'PurchaseOrderController', 'receiveForm', $adminWarehouse);
    $router->add('POST', '/purchase-orders/{id}/receive', 'PurchaseOrderController', 'receive', $adminWarehouse);

    // ---------------------------------------------------------------------
    // Sales Order, approval & goods issue (SO-01)
    // Route paling sensitif. Sales hanya melihat order miliknya; membuka
    // order milik orang lain menghasilkan 404, bukan 403 (§2).
    // ---------------------------------------------------------------------
    $router->add('GET', '/sales-orders', 'SalesOrderController', 'index', $all);
    $router->add('GET', '/sales-orders/create', 'SalesOrderController', 'create', $adminSales);
    $router->add('POST', '/sales-orders', 'SalesOrderController', 'store', $adminSales);
    $router->add('GET', '/sales-orders/{id}', 'SalesOrderController', 'show', $all);
    $router->add('POST', '/sales-orders/{id}/submit', 'SalesOrderController', 'submit', $adminSales);
    // Sales tidak pernah boleh approve, termasuk order miliknya (FR-018).
    $router->add('POST', '/sales-orders/{id}/approve', 'SalesOrderController', 'approve', $adminOnly);
    $router->add('POST', '/sales-orders/{id}/reject', 'SalesOrderController', 'reject', $adminOnly);
    $router->add('POST', '/sales-orders/{id}/cancel', 'SalesOrderController', 'cancel', $adminSales);
    $router->add('GET', '/sales-orders/{id}/issue', 'SalesOrderController', 'issueForm', $adminWarehouse);
    $router->add('POST', '/sales-orders/{id}/issue', 'SalesOrderController', 'issue', $adminWarehouse);

    // ---------------------------------------------------------------------
    // Report (REPORT-01)
    // ---------------------------------------------------------------------
    $router->add('GET', '/reports', 'ReportController', 'index', $all);
    $router->add('GET', '/reports/stock-movement.csv', 'ReportController', 'stockMovementCsv', $adminWarehouse);
    $router->add('GET', '/reports/orders.csv', 'ReportController', 'ordersCsv', $all);
    // PO bukan bagian Sales (§1.2) - Sales menerima 403.
    $router->add(
        'GET',
        '/reports/purchase-orders.csv',
        'ReportController',
        'purchaseOrdersCsv',
        $adminWarehouse,
    );

    // ---------------------------------------------------------------------
    // JSON API (API-01) - contracts/openapi.yaml
    // Session yang sama, guard yang sama; 401 dikembalikan sebagai JSON.
    // ---------------------------------------------------------------------
    $router->add('GET', '/api/products/{sku}/availability', 'Api\StockApiController', 'availability', $all);
    $router->add(
        'GET',
        '/api/products/{productId}/warehouses/{warehouseId}/available',
        'Api\StockApiController',
        'available',
        $all,
    );
    // Low stock bukan bagian dashboard Sales (§1.2) - Sales menerima 403.
    $router->add('GET', '/api/dashboard/low-stock', 'Api\DashboardApiController', 'lowStock', $adminWarehouse);

    // ---------------------------------------------------------------------
    // Health check - dipakai memverifikasi checkpoint Phase 2 dan
    // healthcheck container. Tidak membocorkan informasi apa pun.
    // ---------------------------------------------------------------------
    $router->add('GET', '/_health', 'HealthController', 'index', null);
};
