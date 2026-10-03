<?php

/**
 * Generator database/002_seed.sql.
 *
 * Seed tidak ditulis tangan. Script ini menyusun RIWAYAT pergerakan stock —
 * goods receipt dari Purchase Order dan goods issue dari Sales Order yang
 * Fulfilled — lalu menghitung product_stock DARI riwayat itu. Dengan begitu
 * invariant NFR-002 sudah berlaku sejak seed:
 *
 *   SUM(stock_ledger.quantity) per (product, warehouse) = product_stock.quantity
 *
 * Menyunting 002_seed.sql langsung hampir pasti merusak invariant itu; ubah
 * data demo di sini, lalu generate ulang.
 *
 * Penggunaan (di dalam container, tanpa dependency apa pun selain PHP):
 *   docker compose exec app php database/generate-seed.php
 *
 * Lalu terapkan ke database demo:
 *   docker compose exec app composer db:reset
 *
 * Output-nya deterministik: dijalankan dua kali menghasilkan file yang identik
 * byte demi byte, sehingga diff git hanya memuat perubahan yang disengaja.
 *
 * Exit code:
 *   0  berhasil, 002_seed.sql ditulis ulang
 *   1  data demo melanggar aturan (stock negatif, approver = creator) atau
 *      file tidak dapat ditulis — tidak ada file yang ditulis
 */

declare(strict_types=1);

/** Hash bcrypt untuk password seluruh akun demo: Password123! */
const PASSWORD_HASH = '$2y$12$fzyqGMIurudUFOEQ8veiJOf6oy7UroymDYZSNirOaJK43ua6.uN06';

const OUTPUT_FILE = __DIR__ . '/002_seed.sql';

$fail = static function (string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
};

/** @var list<string> $lines baris-baris file SQL yang sedang disusun */
$lines = [];

$w = static function (string $line = '') use (&$lines): void {
    $lines[] = $line;
};

/** String literal SQL: backslash digandakan, kutip tunggal digandakan. */
$q = static function (string $value): string {
    return "'" . str_replace(["\\", "'"], ["\\\\", "''"], $value) . "'";
};

$dateExpr = static fn (int $dayOffset): string => "DATE_SUB(CURDATE(), INTERVAL {$dayOffset} DAY)";
$datetimeExpr = static fn (int $dayOffset): string => "DATE_SUB(NOW(), INTERVAL {$dayOffset} DAY)";

/** Kunci array untuk pasangan (product, warehouse). */
$pair = static fn (int $productId, int $warehouseId): string => $productId . ':' . $warehouseId;

$w('-- =============================================================================');
$w('-- Seed data demo.');
$w('--');
$w('-- Memenuhi NFR-011 / brief §7.1: 2 Admin, 2 Sales, 2 Warehouse Staff,');
$w('-- 2 warehouse, 30 product dengan reorder point bervariasi (7 di antaranya');
$w('-- berada pada atau di bawah reorder point), dan 32 order gabungan PO/SO');
$w('-- dengan seluruh status terwakili, termasuk PendingApproval dan Cancelled.');
$w('--');
$w('-- PENTING: seluruh baris product_stock DIHITUNG dari stock_ledger, bukan diisi');
$w('-- angka sembarang. Invariant NFR-002 berlaku sejak seed:');
$w('--   SUM(stock_ledger.quantity) per (product, warehouse) = product_stock.quantity');
$w('--');
$w('-- Password seluruh akun demo: Password123!');
$w('-- File ini di-generate oleh script; perbarui generator-nya, bukan file ini.');
$w('-- =============================================================================');
$w();

// ----------------------------------------------------------------- users
/** @var list<array{int, string, string, string}> $users id, nama, email, role */
$users = [
    [1, 'Rina Kusuma', 'admin@ioms.test', 'Admin'],
    [2, 'Bagus Prakoso', 'sales1@ioms.test', 'Sales'],
    [3, 'Dewi Anggraini', 'sales2@ioms.test', 'Sales'],
    [4, 'Tono Wijaya', 'warehouse1@ioms.test', 'WarehouseStaff'],
    [5, 'Sari Melati', 'warehouse2@ioms.test', 'WarehouseStaff'],
    // Admin kedua: approve menuntut approved_by <> created_by, sehingga Sales
    // Order yang dibuat seorang Admin hanya dapat disetujui Admin LAIN.
    // Dengan satu Admin saja, order seperti itu buntu (docs/planning/decisions.md).
    // Diberi id 6 agar id user lain tidak bergeser.
    [6, 'Hendra Saputra', 'admin2@ioms.test', 'Admin'],
];
$w('-- User: 2 Admin, 2 Sales, 2 Warehouse Staff (§7.1 meminta minimal 1 Admin)');
$w('INSERT INTO `user` (id, name, email, password_hash, role, is_active, created_at, updated_at) VALUES');
$w(implode(",\n", array_map(
    static fn (array $u): string => sprintf(
        '(%d, %s, %s, %s, %s, 1, NOW(), NOW())',
        $u[0],
        $q($u[1]),
        $q($u[2]),
        $q(PASSWORD_HASH),
        $q($u[3]),
    ),
    $users,
)) . ';');
$w();

// ------------------------------------------------------------ warehouses
/** @var list<array{int, string, string}> $warehouses */
$warehouses = [
    [1, 'Gudang Pusat Jakarta', 'Jl. Raya Bekasi KM 21, Jakarta Timur'],
    [2, 'Gudang Surabaya', 'Jl. Rungkut Industri III No. 12, Surabaya'],
];
$w('-- Warehouse: 2 lokasi agar stock multi-lokasi dapat didemokan (WH-01)');
$w('INSERT INTO warehouse (id, name, location, is_active, created_at, updated_at) VALUES');
$w(implode(",\n", array_map(
    static fn (array $wh): string => sprintf('(%d, %s, %s, 1, NOW(), NOW())', $wh[0], $q($wh[1]), $q($wh[2])),
    $warehouses,
)) . ';');
$w();

// ------------------------------------------------------------ categories
$categories = ['Networking', 'Power & UPS', 'Storage', 'Peripherals', 'Cabling',
    'Server Parts', 'Security', 'Office Supplies'];
$w('-- Category');
$w('INSERT INTO category (id, name, description, created_at, updated_at) VALUES');
$categoryRows = [];
foreach ($categories as $index => $category) {
    $categoryRows[] = sprintf('(%d, %s, %s, NOW(), NOW())', $index + 1, $q($category), $q('Kategori ' . $category));
}
$w(implode(",\n", $categoryRows) . ';');
$w();

// -------------------------------------------------------------- products
/** @var list<array{string, string, int, int}> $products nama, unit, harga beli, harga jual */
$products = [
    ['Kabel UTP Cat6 305m', 'roll', 1150000, 1450000],
    ['Switch 24-Port Gigabit', 'pcs', 2150000, 2750000],
    ['Router Wireless AC1200', 'pcs', 780000, 995000],
    ['Access Point Ceiling AC1750', 'pcs', 1250000, 1600000],
    ['Konektor RJ45 Cat6 (100pcs)', 'box', 145000, 199000],
    ['UPS 1200VA Line Interactive', 'pcs', 1450000, 1850000],
    ['UPS 3000VA Online', 'pcs', 6900000, 8500000],
    ['Baterai UPS 12V 9Ah', 'pcs', 320000, 425000],
    ['Stabilizer 5000VA', 'pcs', 1750000, 2200000],
    ['SSD NVMe 1TB', 'pcs', 1050000, 1350000],
    ['SSD SATA 512GB', 'pcs', 620000, 799000],
    ['HDD Enterprise 4TB', 'pcs', 2100000, 2650000],
    ['RAM DDR4 16GB ECC', 'pcs', 1350000, 1700000],
    ['RAM DDR4 32GB ECC', 'pcs', 2600000, 3250000],
    ['Keyboard Mechanical TKL', 'pcs', 450000, 595000],
    ['Mouse Wireless Ergonomic', 'pcs', 185000, 249000],
    ['Monitor 24 inch IPS FHD', 'pcs', 1550000, 1950000],
    ['Monitor 27 inch IPS QHD', 'pcs', 2850000, 3550000],
    ['Docking Station USB-C', 'pcs', 890000, 1150000],
    ['Webcam 1080p', 'pcs', 395000, 520000],
    ['Headset Call Center', 'pcs', 275000, 365000],
    ['Kabel HDMI 2.0 3m', 'pcs', 85000, 125000],
    ['Kabel Power IEC C13 1.8m', 'pcs', 35000, 55000],
    ['Cable Tray 2m', 'pcs', 265000, 340000],
    ['Patch Panel 24-Port', 'pcs', 485000, 625000],
    ['Rack Server 20U', 'unit', 3900000, 4850000],
    ['Fan Rack 4-Way', 'pcs', 420000, 545000],
    ['CCTV Dome 5MP', 'pcs', 650000, 850000],
    ['NVR 8-Channel', 'pcs', 2250000, 2850000],
    ['Label Printer Thermal', 'pcs', 1150000, 1495000],
];
$categoryOf = [1, 1, 1, 1, 5, 2, 2, 2, 2, 3, 3, 3, 6, 6, 4, 4, 4, 4, 4, 4, 4, 5, 5, 5, 5, 6, 6, 7, 7, 8];
$reorderPoints = [10, 4, 6, 5, 20, 5, 2, 12, 3, 8, 10, 4, 6, 3, 15, 25, 6, 4, 8, 12, 18, 30, 40, 10, 8,
    2, 6, 10, 3, 4];

$w('-- Product: 30 item, reorder point bervariasi (PRD-01)');
$w('INSERT INTO product (id, sku, name, category_id, unit, purchase_price, selling_price,');
$w('                     reorder_point, image_path, is_active, created_at, updated_at) VALUES');
$productRows = [];
/** @var array<int, int> $purchasePrice */
$purchasePrice = [];
/** @var array<int, int> $sellingPrice */
$sellingPrice = [];
foreach ($products as $index => [$name, $unit, $buy, $sell]) {
    $productId = $index + 1;
    $purchasePrice[$productId] = $buy;
    $sellingPrice[$productId] = $sell;
    $productRows[] = sprintf(
        '(%d, %s, %s, %d, %s, %d.00, %d.00, %d, NULL, 1, NOW(), NOW())',
        $productId,
        $q(sprintf('SKU-%06d', $productId)),
        $q($name),
        $categoryOf[$index],
        $q($unit),
        $buy,
        $sell,
        $reorderPoints[$index],
    );
}
$w(implode(",\n", $productRows) . ';');
$w();

// --------------------------------------------------- suppliers / customers
$suppliers = ['PT Sinar Jaya Elektronik', 'CV Mitra Teknologi', 'PT Global Network Solusi',
    'PT Andalan Komputindo', 'CV Berkah Digital', 'PT Nusantara Data',
    'PT Cipta Sarana Teknik', 'CV Prima Elektrindo', 'PT Maju Bersama Niaga',
    'CV Sumber Rejeki IT', 'PT Intan Sukses Mandiri', 'PT Bina Karya Elektro',
    'CV Tunas Harapan', 'PT Delta Mitra Utama', 'CV Karya Abadi Teknik'];
$customers = ['PT Bank Wijaya Nusantara', 'RS Harapan Sehat', 'Universitas Cendekia',
    'PT Logistik Andal', 'Pemkot Surabaya - Diskominfo', 'PT Asuransi Bhakti',
    'Hotel Grand Melati', 'PT Manufaktur Presisi', 'Yayasan Pendidikan Tunas',
    'PT Ritel Sejahtera', 'Klinik Medika Prima', 'PT Konstruksi Bangun',
    'CV Percetakan Cahaya', 'PT Agro Lestari', 'Koperasi Karya Mandiri'];

$w('-- Supplier dan Customer: dua entity terpisah (data-model.md), 15 masing-masing');
$w('INSERT INTO supplier (id, name, contact, address, is_active, created_at, updated_at) VALUES');
$supplierRows = [];
foreach ($suppliers as $i => $name) {
    $supplierRows[] = sprintf(
        '(%d, %s, %s, %s, 1, NOW(), NOW())',
        $i + 1,
        $q($name),
        $q(sprintf('02%d-5%03d-%04d', 1 + $i % 6, 100 + $i * 7, 1000 + $i * 137)),
        $q(sprintf('Jl. Industri No. %d, Indonesia', 10 + $i * 3)),
    );
}
$w(implode(",\n", $supplierRows) . ';');
$w();
$w('INSERT INTO customer (id, name, contact, address, is_active, created_at, updated_at) VALUES');
$customerRows = [];
foreach ($customers as $i => $name) {
    $customerRows[] = sprintf(
        '(%d, %s, %s, %s, 1, NOW(), NOW())',
        $i + 1,
        $q($name),
        $q(sprintf('02%d-7%03d-%04d', 1 + $i % 6, 200 + $i * 5, 2000 + $i * 91)),
        $q(sprintf('Jl. Merdeka No. %d, Indonesia', 5 + $i * 4)),
    );
}
$w(implode(",\n", $customerRows) . ';');
$w();

// ------------------------------------------------------------------ ORDERS
// stock[pasangan] dan ledger dibangun bersamaan, sehingga keduanya tidak
// mungkin tidak sepakat.
/** @var array<string, int> $stock */
$stock = [];
/** @var list<array{int, int, string, int, string, int, int, int}> $ledger product, warehouse, tipe, qty, ref, ref id, user, hari */
$ledger = [];

/** qty positif untuk Receipt, negatif untuk Issue. */
$addLedger = static function (
    int $productId,
    int $warehouseId,
    string $type,
    int $quantity,
    string $referenceType,
    int $referenceId,
    int $userId,
    int $day,
) use (
    &$ledger,
    &$stock,
    $pair,
): void {
    $ledger[] = [$productId, $warehouseId, $type, $quantity, $referenceType, $referenceId, $userId, $day];
    $key = $pair($productId, $warehouseId);
    $stock[$key] = ($stock[$key] ?? 0) + $quantity;
};

// --- Target stock akhir ------------------------------------------------
// Tujuh product sengaja dibuat berada pada atau di bawah reorder point agar
// dashboard low-stock, filter stock status, dan script check-low-stock benar-
// benar ada isinya (NFR-011, DASH-01, JOB-01).
const LOW_STOCK_PRODUCTS = [2 => 3, 7 => 1, 9 => 2, 14 => 2, 18 => 4, 26 => 1, 29 => 3];

// Pembagian antar warehouse. Product low-stock ditaruh seluruhnya di Jakarta
// sehingga baris Surabaya-nya tetap ada dengan quantity 0 - WH-01 meminta
// setiap product punya baris stock per warehouse.
/** @var array<string, int> $target */
$target = [];
for ($productId = 1; $productId <= 30; $productId++) {
    if (isset(LOW_STOCK_PRODUCTS[$productId])) {
        $target[$pair($productId, 1)] = LOW_STOCK_PRODUCTS[$productId];
        $target[$pair($productId, 2)] = 0;
        continue;
    }

    $total = $reorderPoints[$productId - 1] * 3 + 12;
    $target[$pair($productId, 1)] = $total - intdiv($total, 3);
    $target[$pair($productId, 2)] = intdiv($total, 3);
}

// --- Purchase Order tambahan (partial / belum diterima) -----------------
// Product low-stock sengaja TIDAK muncul di sini agar targetnya tidak
// terlampaui. Item: [product, qty dipesan, qty diterima].
/** @var list<array{string, int, int, list<array{int, int, int}>}> $poPlan status, warehouse, hari, item */
$poPlan = [
    ['PartiallyReceived', 1, 21, [[3, 20, 8], [17, 15, 5]]],
    ['PartiallyReceived', 2, 14, [[12, 16, 6], [30, 10, 3]]],
    ['Ordered', 1, 9, [[7, 6, 0], [26, 4, 0]]],
    ['Ordered', 2, 6, [[14, 12, 0], [18, 10, 0]]],
    ['Draft', 1, 3, [[3, 10, 0], [4, 8, 0]]],
    ['Draft', 2, 2, [[28, 15, 0]]],
    ['Cancelled', 1, 34, [[9, 6, 0]]],
    ['Cancelled', 2, 28, [[21, 25, 0], [16, 30, 0]]],
];

// --- Sales Order --------------------------------------------------------
// Hanya Fulfilled yang menghasilkan ledger Issue. Draft, PendingApproval,
// Approved, dan Cancelled tidak menyentuh stock sama sekali.
/** @var list<array{string, int, int, int|null, int, list<array{int, int}>}> $soPlan status, warehouse, creator, approver, hari, item */
$soPlan = [
    ['Fulfilled', 1, 2, 1, 40, [[1, 8], [5, 12], [22, 15]]],
    ['Fulfilled', 1, 3, 1, 36, [[4, 4], [25, 6]]],
    ['Fulfilled', 2, 2, 5, 30, [[10, 6], [11, 8], [16, 20]]],
    ['Fulfilled', 2, 3, 1, 25, [[15, 12], [17, 5]]],
    ['Fulfilled', 1, 2, 4, 19, [[6, 5], [8, 10]]],
    ['Fulfilled', 2, 3, 5, 16, [[19, 7], [21, 14]]],
    ['Fulfilled', 1, 2, 1, 12, [[23, 35], [24, 8]]],
    ['Approved', 1, 3, null, 7, [[3, 4], [4, 3]]],
    ['Approved', 2, 2, null, 5, [[12, 3]]],
    ['PendingApproval', 1, 2, null, 4, [[7, 2], [9, 2]]],
    ['PendingApproval', 2, 3, null, 3, [[13, 4], [14, 2]]],
    ['PendingApproval', 1, 3, null, 2, [[26, 1]]],
    ['Draft', 1, 2, null, 1, [[27, 3], [30, 2]]],
    ['Draft', 2, 3, null, 1, [[28, 5]]],
    ['Draft', 1, 2, null, 0, [[20, 6]]],
    ['Cancelled', 2, 3, null, 22, [[18, 3]]],
    ['Cancelled', 1, 2, null, 45, [[29, 2], [26, 1]]],
];

// Hitung mundur: berapa yang harus diterima di awal agar stock akhir tepat
// sama dengan target, setelah memperhitungkan issue dan partial receipt.
/** @var array<string, int> $issuesByPair */
$issuesByPair = [];
foreach ($soPlan as [$status, $warehouseId, , , , $items]) {
    if ($status !== 'Fulfilled') {
        continue;
    }

    foreach ($items as [$productId, $quantity]) {
        $key = $pair($productId, $warehouseId);
        $issuesByPair[$key] = ($issuesByPair[$key] ?? 0) + $quantity;
    }
}

/** @var array<string, int> $partialByPair */
$partialByPair = [];
foreach ($poPlan as [, $warehouseId, , $items]) {
    foreach ($items as [$productId, , $received]) {
        if ($received > 0) {
            $key = $pair($productId, $warehouseId);
            $partialByPair[$key] = ($partialByPair[$key] ?? 0) + $received;
        }
    }
}

// Disusun urut product lalu warehouse, sama dengan urutan iterasi berikutnya.
/** @var array<int, list<array{int, int}>> $openingByWarehouse warehouse => [product, qty] */
$openingByWarehouse = [1 => [], 2 => []];
for ($productId = 1; $productId <= 30; $productId++) {
    foreach ([1, 2] as $warehouseId) {
        $key = $pair($productId, $warehouseId);
        $needed = $target[$key] + ($issuesByPair[$key] ?? 0) - ($partialByPair[$key] ?? 0);

        if ($needed > 0) {
            $openingByWarehouse[$warehouseId][] = [$productId, $needed];
        }
    }
}

// Opening stock dipecah menjadi beberapa PO "Received" bertanggal lama, agar
// terlihat seperti riwayat pembelian yang wajar, bukan satu dump raksasa.
$openingPurchaseOrders = [];
foreach ([1, 2] as $warehouseId) {
    foreach (array_chunk($openingByWarehouse[$warehouseId], 8) as $chunk) {
        $day = 120 - count($openingPurchaseOrders) * 6;
        $openingPurchaseOrders[] = [
            'Received',
            $warehouseId,
            $day,
            array_map(static fn (array $line): array => [$line[0], $line[1], $line[1]], $chunk),
        ];
    }
}

// Opening PO didahulukan agar penomoran mengikuti urutan waktu.
$poPlan = array_merge($openingPurchaseOrders, $poPlan);

// --- Emit Purchase Order + ledger Receipt ------------------------------
$poRows = [];
$poItems = [];
$poItemId = 0;
foreach ($poPlan as $index => [$status, $warehouseId, $day, $items]) {
    $poId = $index + 1;
    $supplierId = (($poId - 1) % 15) + 1;
    $creator = $poId % 2 === 1 ? 1 : 4;
    $poRows[] = sprintf(
        '(%d, %s, %d, %d, %s, %s, %d, %s, %s)',
        $poId,
        $q(sprintf('PO-2026-%04d', $poId)),
        $supplierId,
        $warehouseId,
        $q($status),
        $dateExpr($day),
        $creator,
        $datetimeExpr($day),
        $datetimeExpr($day),
    );

    foreach ($items as [$productId, $quantity, $received]) {
        $poItemId++;
        $poItems[] = sprintf(
            '(%d, %d, %d, %d, %d, %d.00)',
            $poItemId,
            $poId,
            $productId,
            $quantity,
            $received,
            $purchasePrice[$productId],
        );

        if ($received > 0) {
            $addLedger(
                $productId,
                $warehouseId,
                'Receipt',
                $received,
                'PurchaseOrder',
                $poId,
                $creator,
                max(0, $day - 1),
            );
        }
    }
}

// --- Emit Sales Order + ledger Issue -----------------------------------
$soRows = [];
$soItems = [];
$soItemId = 0;
foreach ($soPlan as $index => [$status, $warehouseId, $creator, $approver, $day, $items]) {
    $soId = $index + 1;
    $customerId = (($soId - 1) % 15) + 1;

    // approver tidak boleh sama dengan creator - segregation of duties
    // berlaku juga pada data demo (§1.2, FR-018).
    if ($approver !== null && $approver === $creator) {
        $fail(sprintf('SO %d: approver == creator', $soId));
    }

    $soRows[] = sprintf(
        '(%d, %s, %d, %d, %s, %d, %s, %s, %s, %s, %s)',
        $soId,
        $q(sprintf('SO-2026-%04d', $soId)),
        $customerId,
        $creator,
        $approver === null ? 'NULL' : (string) $approver,
        $warehouseId,
        $q($status),
        $dateExpr($day),
        $approver === null ? 'NULL' : $datetimeExpr($day),
        $datetimeExpr($day),
        $datetimeExpr($day),
    );

    foreach ($items as [$productId, $quantity]) {
        $soItemId++;
        $soItems[] = sprintf(
            '(%d, %d, %d, %d, %d.00)',
            $soItemId,
            $soId,
            $productId,
            $quantity,
            $sellingPrice[$productId],
        );

        if ($status === 'Fulfilled') {
            $issuer = $soId % 2 === 1 ? 4 : 5;
            $addLedger($productId, $warehouseId, 'Issue', -$quantity, 'SalesOrder', $soId, $issuer, max(0, $day - 1));
        }
    }
}

$w(sprintf('-- Purchase Order: %d order, seluruh status terwakili (PO-01)', count($poPlan)));
$w('INSERT INTO purchase_order (id, order_number, supplier_id, warehouse_id, status,');
$w('                            order_date, created_by, created_at, updated_at) VALUES');
$w(implode(",\n", $poRows) . ';');
$w();
$w('INSERT INTO purchase_order_item (id, purchase_order_id, product_id, quantity,');
$w('                                 received_quantity, purchase_price) VALUES');
$w(implode(",\n", $poItems) . ';');
$w();

$w(sprintf('-- Sales Order: %d order, termasuk PendingApproval dan Cancelled (SO-01, §7.1)', count($soPlan)));
$w('-- approved_by SELALU berbeda dari created_by - aturan segregation of duties');
$w('-- berlaku juga pada data demo (§1.2, FR-018).');
$w('INSERT INTO sales_order (id, order_number, customer_id, created_by, approved_by, warehouse_id,');
$w('                         status, order_date, approved_at, created_at, updated_at) VALUES');
$w(implode(",\n", $soRows) . ';');
$w();
$w('INSERT INTO sales_order_item (id, sales_order_id, product_id, quantity, selling_price) VALUES');
$w(implode(",\n", $soItems) . ';');
$w();

// ------------------------------------------------------------------ ledger
$w(sprintf('-- Stock ledger: %d pergerakan. Append-only.', count($ledger)));
$w('-- Positif untuk Receipt, negatif untuk Issue.');
$w('INSERT INTO stock_ledger (product_id, warehouse_id, movement_type, quantity,');
$w('                          reference_type, reference_id, performed_by, created_at) VALUES');
$w(implode(",\n", array_map(
    static fn (array $row): string => sprintf(
        '(%d, %d, %s, %d, %s, %d, %d, %s)',
        $row[0],
        $row[1],
        $q($row[2]),
        $row[3],
        $q($row[4]),
        $row[5],
        $row[6],
        $datetimeExpr($row[7]),
    ),
    $ledger,
)) . ';');
$w();

// ----------------------------------------------------------- product_stock
$w('-- Product stock: DIHITUNG dari stock_ledger di atas, bukan angka lepas.');
$w('-- Setiap baris di sini sama dengan SUM(ledger.quantity) untuk pasangan');
$w('-- (product, warehouse) tersebut - invariant NFR-002 berlaku sejak seed.');
$w('INSERT INTO product_stock (product_id, warehouse_id, quantity, updated_at) VALUES');
$stockRows = [];
for ($productId = 1; $productId <= 30; $productId++) {
    foreach ([1, 2] as $warehouseId) {
        $quantity = $stock[$pair($productId, $warehouseId)] ?? 0;

        if ($quantity < 0) {
            $fail(sprintf('stock negatif pada product %d warehouse %d: %d', $productId, $warehouseId, $quantity));
        }

        $stockRows[] = sprintf('(%d, %d, %d, NOW())', $productId, $warehouseId, $quantity);
    }
}
$w(implode(",\n", $stockRows) . ';');
$w();

// --- ringkasan low stock ---
/** @var array<int, int> $totals */
$totals = [];
foreach ($stock as $key => $quantity) {
    $productId = (int) explode(':', $key)[0];
    $totals[$productId] = ($totals[$productId] ?? 0) + $quantity;
}

$lowStock = [];
for ($productId = 1; $productId <= 30; $productId++) {
    if (($totals[$productId] ?? 0) <= $reorderPoints[$productId - 1]) {
        $lowStock[] = $productId;
    }
}

$orderCount = count($poPlan) + count($soPlan);
$w('-- Ringkasan (dihitung saat generate):');
$w(sprintf('--   product dengan stock di bawah/at reorder point: %d dari 30', count($lowStock)));
$w('--   id-nya: ' . implode(', ', $lowStock));
$w(sprintf('--   total order: %d PO + %d SO = %d', count($poPlan), count($soPlan), $orderCount));
$w(sprintf('--   baris ledger: %d', count($ledger)));

if (file_put_contents(OUTPUT_FILE, implode("\n", $lines) . "\n") === false) {
    $fail('Tidak dapat menulis ' . OUTPUT_FILE);
}

fwrite(STDOUT, sprintf('orders: %d PO + %d SO = %d', count($poPlan), count($soPlan), $orderCount) . PHP_EOL);
fwrite(STDOUT, 'ledger rows: ' . count($ledger) . PHP_EOL);
fwrite(STDOUT, 'stock rows: ' . count($stockRows) . PHP_EOL);
fwrite(STDOUT, sprintf('low-stock products: %d -> [%s]', count($lowStock), implode(', ', $lowStock)) . PHP_EOL);
fwrite(STDOUT, sprintf('min stock: %d (must be >= 0)', $stock === [] ? 0 : min($stock)) . PHP_EOL);
