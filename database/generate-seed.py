"""Generator database/002_seed.sql.

Menghitung product_stock DARI stock_ledger sehingga invariant NFR-002
(SUM(ledger.quantity) == product_stock.quantity) dijamin sejak seed.
"""
import random

random.seed(20260910)

HASH = '$2y$12$fzyqGMIurudUFOEQ8veiJOf6oy7UroymDYZSNirOaJK43ua6.uN06'
OUT = []


def w(s=''):
    OUT.append(s)


def q(s):
    return "'" + str(s).replace("\\", "\\\\").replace("'", "''") + "'"


w("-- =============================================================================")
w("-- Seed data demo.")
w("--")
w("-- Memenuhi NFR-011 / brief §7.1: 1 Admin, 2 Sales, 2 Warehouse Staff,")
w("-- 2 warehouse, 30 product dengan reorder point bervariasi (7 di antaranya")
w("-- berada pada atau di bawah reorder point), dan 32 order gabungan PO/SO")
w("-- dengan seluruh status terwakili, termasuk PendingApproval dan Cancelled.")
w("--")
w("-- PENTING: seluruh baris product_stock DIHITUNG dari stock_ledger, bukan diisi")
w("-- angka sembarang. Invariant NFR-002 berlaku sejak seed:")
w("--   SUM(stock_ledger.quantity) per (product, warehouse) = product_stock.quantity")
w("--")
w("-- Password seluruh akun demo: Password123!")
w("-- File ini di-generate oleh script; perbarui generator-nya, bukan file ini.")
w("-- =============================================================================")
w()

# ---------------------------------------------------------------- users
users = [
    (1, 'Rina Kusuma', 'admin@ioms.test', 'Admin'),
    (2, 'Bagus Prakoso', 'sales1@ioms.test', 'Sales'),
    (3, 'Dewi Anggraini', 'sales2@ioms.test', 'Sales'),
    (4, 'Tono Wijaya', 'warehouse1@ioms.test', 'WarehouseStaff'),
    (5, 'Sari Melati', 'warehouse2@ioms.test', 'WarehouseStaff'),
]
w("-- User: 1 Admin, 2 Sales, 2 Warehouse Staff (§7.1)")
w("INSERT INTO `user` (id, name, email, password_hash, role, is_active, created_at, updated_at) VALUES")
w(",\n".join(
    f"({i}, {q(n)}, {q(e)}, {q(HASH)}, {q(r)}, 1, NOW(), NOW())" for i, n, e, r in users) + ";")
w()

# ----------------------------------------------------------- warehouses
whs = [
    (1, 'Gudang Pusat Jakarta', 'Jl. Raya Bekasi KM 21, Jakarta Timur'),
    (2, 'Gudang Surabaya', 'Jl. Rungkut Industri III No. 12, Surabaya'),
]
w("-- Warehouse: 2 lokasi agar stock multi-lokasi dapat didemokan (WH-01)")
w("INSERT INTO warehouse (id, name, location, is_active, created_at, updated_at) VALUES")
w(",\n".join(f"({i}, {q(n)}, {q(l)}, 1, NOW(), NOW())" for i, n, l in whs) + ";")
w()

# ----------------------------------------------------------- categories
cats = ['Networking', 'Power & UPS', 'Storage', 'Peripherals', 'Cabling',
        'Server Parts', 'Security', 'Office Supplies']
w("-- Category")
w("INSERT INTO category (id, name, description, created_at, updated_at) VALUES")
w(",\n".join(
    f"({i + 1}, {q(c)}, {q('Kategori ' + c)}, NOW(), NOW())" for i, c in enumerate(cats)) + ";")
w()

# ------------------------------------------------------------- products
names = [
    ('Kabel UTP Cat6 305m', 'roll', 1150000, 1450000),
    ('Switch 24-Port Gigabit', 'pcs', 2150000, 2750000),
    ('Router Wireless AC1200', 'pcs', 780000, 995000),
    ('Access Point Ceiling AC1750', 'pcs', 1250000, 1600000),
    ('Konektor RJ45 Cat6 (100pcs)', 'box', 145000, 199000),
    ('UPS 1200VA Line Interactive', 'pcs', 1450000, 1850000),
    ('UPS 3000VA Online', 'pcs', 6900000, 8500000),
    ('Baterai UPS 12V 9Ah', 'pcs', 320000, 425000),
    ('Stabilizer 5000VA', 'pcs', 1750000, 2200000),
    ('SSD NVMe 1TB', 'pcs', 1050000, 1350000),
    ('SSD SATA 512GB', 'pcs', 620000, 799000),
    ('HDD Enterprise 4TB', 'pcs', 2100000, 2650000),
    ('RAM DDR4 16GB ECC', 'pcs', 1350000, 1700000),
    ('RAM DDR4 32GB ECC', 'pcs', 2600000, 3250000),
    ('Keyboard Mechanical TKL', 'pcs', 450000, 595000),
    ('Mouse Wireless Ergonomic', 'pcs', 185000, 249000),
    ('Monitor 24 inch IPS FHD', 'pcs', 1550000, 1950000),
    ('Monitor 27 inch IPS QHD', 'pcs', 2850000, 3550000),
    ('Docking Station USB-C', 'pcs', 890000, 1150000),
    ('Webcam 1080p', 'pcs', 395000, 520000),
    ('Headset Call Center', 'pcs', 275000, 365000),
    ('Kabel HDMI 2.0 3m', 'pcs', 85000, 125000),
    ('Kabel Power IEC C13 1.8m', 'pcs', 35000, 55000),
    ('Cable Tray 2m', 'pcs', 265000, 340000),
    ('Patch Panel 24-Port', 'pcs', 485000, 625000),
    ('Rack Server 20U', 'unit', 3900000, 4850000),
    ('Fan Rack 4-Way', 'pcs', 420000, 545000),
    ('CCTV Dome 5MP', 'pcs', 650000, 850000),
    ('NVR 8-Channel', 'pcs', 2250000, 2850000),
    ('Label Printer Thermal', 'pcs', 1150000, 1495000),
]
cat_of = [1, 1, 1, 1, 5, 2, 2, 2, 2, 3, 3, 3, 6, 6, 4, 4, 4, 4, 4, 4, 4, 5, 5, 5, 5, 6, 6, 7, 7, 8]
reorders = [10, 4, 6, 5, 20, 5, 2, 12, 3, 8, 10, 4, 6, 3, 15, 25, 6, 4, 8, 12, 18, 30, 40, 10, 8,
            2, 6, 10, 3, 4]

w("-- Product: 30 item, reorder point bervariasi (PRD-01)")
w("INSERT INTO product (id, sku, name, category_id, unit, purchase_price, selling_price,")
w("                     reorder_point, image_path, is_active, created_at, updated_at) VALUES")
prows = []
for i, (nm, unit, pp, sp) in enumerate(names):
    pid = i + 1
    prows.append(
        f"({pid}, {q('SKU-%06d' % pid)}, {q(nm)}, {cat_of[i]}, {q(unit)}, "
        f"{pp}.00, {sp}.00, {reorders[i]}, NULL, 1, NOW(), NOW())")
w(",\n".join(prows) + ";")
w()

# -------------------------------------------------- suppliers / customers
sup = ['PT Sinar Jaya Elektronik', 'CV Mitra Teknologi', 'PT Global Network Solusi',
       'PT Andalan Komputindo', 'CV Berkah Digital', 'PT Nusantara Data',
       'PT Cipta Sarana Teknik', 'CV Prima Elektrindo', 'PT Maju Bersama Niaga',
       'CV Sumber Rejeki IT', 'PT Intan Sukses Mandiri', 'PT Bina Karya Elektro',
       'CV Tunas Harapan', 'PT Delta Mitra Utama', 'CV Karya Abadi Teknik']
cus = ['PT Bank Wijaya Nusantara', 'RS Harapan Sehat', 'Universitas Cendekia',
       'PT Logistik Andal', 'Pemkot Surabaya - Diskominfo', 'PT Asuransi Bhakti',
       'Hotel Grand Melati', 'PT Manufaktur Presisi', 'Yayasan Pendidikan Tunas',
       'PT Ritel Sejahtera', 'Klinik Medika Prima', 'PT Konstruksi Bangun',
       'CV Percetakan Cahaya', 'PT Agro Lestari', 'Koperasi Karya Mandiri']

w("-- Supplier dan Customer: dua entity terpisah (data-model.md), 15 masing-masing")
w("INSERT INTO supplier (id, name, contact, address, is_active, created_at, updated_at) VALUES")
w(",\n".join(
    f"({i + 1}, {q(n)}, {q('02%d-5%03d-%04d' % (1 + i % 6, 100 + i * 7, 1000 + i * 137))}, "
    f"{q('Jl. Industri No. %d, Indonesia' % (10 + i * 3))}, 1, NOW(), NOW())"
    for i, n in enumerate(sup)) + ";")
w()
w("INSERT INTO customer (id, name, contact, address, is_active, created_at, updated_at) VALUES")
w(",\n".join(
    f"({i + 1}, {q(n)}, {q('02%d-7%03d-%04d' % (1 + i % 6, 200 + i * 5, 2000 + i * 91))}, "
    f"{q('Jl. Merdeka No. %d, Indonesia' % (5 + i * 4))}, 1, NOW(), NOW())"
    for i, n in enumerate(cus)) + ";")
w()

# ------------------------------------------------------------- ORDERS
# stock[(product, warehouse)] dan ledger dibangun bersamaan.
stock = {}
ledger = []          # (product, warehouse, type, qty, ref_type, ref_id, user, day_offset)
po_rows, po_items = [], []
so_rows, so_items = [], []

purchase_price = {i + 1: names[i][2] for i in range(30)}
selling_price = {i + 1: names[i][3] for i in range(30)}


def add_ledger(pid, wid, mtype, qty, ref_type, ref_id, user, day):
    """qty positif untuk Receipt, negatif untuk Issue."""
    ledger.append((pid, wid, mtype, qty, ref_type, ref_id, user, day))
    stock[(pid, wid)] = stock.get((pid, wid), 0) + qty


def date_expr(day_offset):
    return f"DATE_SUB(CURDATE(), INTERVAL {day_offset} DAY)"


def datetime_expr(day_offset):
    return f"DATE_SUB(NOW(), INTERVAL {day_offset} DAY)"


# --- Target stock akhir -----------------------------------------------
# Tujuh product sengaja dibuat berada pada atau di bawah reorder point agar
# dashboard low-stock, filter stock status, dan script check-low-stock benar-
# benar ada isinya (NFR-011, DASH-01, JOB-01).
LOW_STOCK_PRODUCTS = {2: 3, 7: 1, 9: 2, 14: 2, 18: 4, 26: 1, 29: 3}

target_total = {}
for pid in range(1, 31):
    if pid in LOW_STOCK_PRODUCTS:
        target_total[pid] = LOW_STOCK_PRODUCTS[pid]
    else:
        target_total[pid] = reorders[pid - 1] * 3 + 12

# Pembagian antar warehouse. Product low-stock ditaruh seluruhnya di Jakarta
# sehingga baris Surabaya-nya tetap ada dengan quantity 0 - WH-01 meminta
# setiap product punya baris stock per warehouse.
target = {}
for pid in range(1, 31):
    total = target_total[pid]
    if pid in LOW_STOCK_PRODUCTS:
        target[(pid, 1)] = total
        target[(pid, 2)] = 0
    else:
        target[(pid, 1)] = total - total // 3
        target[(pid, 2)] = total // 3

# --- Purchase Order tambahan (partial / belum diterima) ----------------
# Product low-stock sengaja TIDAK muncul di sini agar targetnya tidak
# terlampaui.
po_plan = [
    ('PartiallyReceived', 1, 21, [(3, 20, 8), (17, 15, 5)]),
    ('PartiallyReceived', 2, 14, [(12, 16, 6), (30, 10, 3)]),
    ('Ordered', 1, 9, [(7, 6, 0), (26, 4, 0)]),
    ('Ordered', 2, 6, [(14, 12, 0), (18, 10, 0)]),
    ('Draft', 1, 3, [(3, 10, 0), (4, 8, 0)]),
    ('Draft', 2, 2, [(28, 15, 0)]),
    ('Cancelled', 1, 34, [(9, 6, 0)]),
    ('Cancelled', 2, 28, [(21, 25, 0), (16, 30, 0)]),
]

# --- Sales Order -------------------------------------------------------
# Hanya Fulfilled yang menghasilkan ledger Issue. Draft, PendingApproval,
# Approved, dan Cancelled tidak menyentuh stock sama sekali.
so_plan = [
    ('Fulfilled', 1, 2, 1, 40, [(1, 8), (5, 12), (22, 15)]),
    ('Fulfilled', 1, 3, 1, 36, [(4, 4), (25, 6)]),
    ('Fulfilled', 2, 2, 5, 30, [(10, 6), (11, 8), (16, 20)]),
    ('Fulfilled', 2, 3, 1, 25, [(15, 12), (17, 5)]),
    ('Fulfilled', 1, 2, 4, 19, [(6, 5), (8, 10)]),
    ('Fulfilled', 2, 3, 5, 16, [(19, 7), (21, 14)]),
    ('Fulfilled', 1, 2, 1, 12, [(23, 35), (24, 8)]),
    ('Approved', 1, 3, None, 7, [(3, 4), (4, 3)]),
    ('Approved', 2, 2, None, 5, [(12, 3)]),
    ('PendingApproval', 1, 2, None, 4, [(7, 2), (9, 2)]),
    ('PendingApproval', 2, 3, None, 3, [(13, 4), (14, 2)]),
    ('PendingApproval', 1, 3, None, 2, [(26, 1)]),
    ('Draft', 1, 2, None, 1, [(27, 3), (30, 2)]),
    ('Draft', 2, 3, None, 1, [(28, 5)]),
    ('Draft', 1, 2, None, 0, [(20, 6)]),
    ('Cancelled', 2, 3, None, 22, [(18, 3)]),
    ('Cancelled', 1, 2, None, 45, [(29, 2), (26, 1)]),
]

# Hitung mundur: berapa yang harus diterima di awal agar stock akhir tepat
# sama dengan target, setelah memperhitungkan issue dan partial receipt.
issues_by_pair = {}
for status, wid, _creator, _approver, _day, items in so_plan:
    if status != 'Fulfilled':
        continue
    for pid, qty in items:
        issues_by_pair[(pid, wid)] = issues_by_pair.get((pid, wid), 0) + qty

partial_by_pair = {}
for _status, wid, _day, items in po_plan:
    for pid, _qty, received in items:
        if received > 0:
            partial_by_pair[(pid, wid)] = partial_by_pair.get((pid, wid), 0) + received

opening = {}
for pid in range(1, 31):
    for wid in (1, 2):
        needed = (target[(pid, wid)]
                  + issues_by_pair.get((pid, wid), 0)
                  - partial_by_pair.get((pid, wid), 0))
        if needed > 0:
            opening[(pid, wid)] = needed

# Opening stock dipecah menjadi beberapa PO "Received" bertanggal lama, agar
# terlihat seperti riwayat pembelian yang wajar, bukan satu dump raksasa.
opening_pos = []
for wid in (1, 2):
    pairs = [(pid, qty) for (pid, w), qty in sorted(opening.items()) if w == wid]
    chunk_size = 8
    for chunk_index in range(0, len(pairs), chunk_size):
        chunk = pairs[chunk_index:chunk_index + chunk_size]
        day = 120 - (len(opening_pos) * 6)
        opening_pos.append(
            ('Received', wid, day, [(pid, qty, qty) for pid, qty in chunk]))

# Opening PO didahulukan agar penomoran mengikuti urutan waktu.
po_plan = opening_pos + po_plan

# --- Emit Purchase Order + ledger Receipt -----------------------------
po_item_id = 0
for idx, (status, wid, day, items) in enumerate(po_plan, start=1):
    po_id = idx
    supplier_id = ((idx - 1) % 15) + 1
    creator = 1 if idx % 2 else 4
    po_rows.append(
        f"({po_id}, {q('PO-2026-%04d' % po_id)}, {supplier_id}, {wid}, {q(status)}, "
        f"{date_expr(day)}, {creator}, {datetime_expr(day)}, {datetime_expr(day)})")

    for pid, qty, received in items:
        po_item_id += 1
        po_items.append(
            f"({po_item_id}, {po_id}, {pid}, {qty}, {received}, {purchase_price[pid]}.00)")

        if received > 0:
            add_ledger(pid, wid, 'Receipt', received, 'PurchaseOrder', po_id, creator, max(0, day - 1))

# --- Emit Sales Order + ledger Issue ----------------------------------
so_item_id = 0
for idx, (status, wid, creator, approver, day, items) in enumerate(so_plan, start=1):
    so_id = idx
    customer_id = ((idx - 1) % 15) + 1
    approved_by = 'NULL' if approver is None else str(approver)
    approved_at = 'NULL' if approver is None else datetime_expr(day)

    # approver tidak boleh sama dengan creator - segregation of duties
    # berlaku juga pada data demo (§1.2, FR-018).
    assert approver is None or approver != creator, f"SO {so_id}: approver == creator"

    so_rows.append(
        f"({so_id}, {q('SO-2026-%04d' % so_id)}, {customer_id}, {creator}, {approved_by}, {wid}, "
        f"{q(status)}, {date_expr(day)}, {approved_at}, {datetime_expr(day)}, {datetime_expr(day)})")

    for pid, qty in items:
        so_item_id += 1
        so_items.append(f"({so_item_id}, {so_id}, {pid}, {qty}, {selling_price[pid]}.00)")

        if status == 'Fulfilled':
            issuer = 4 if so_id % 2 else 5
            add_ledger(pid, wid, 'Issue', -qty, 'SalesOrder', so_id, issuer, max(0, day - 1))

w(f"-- Purchase Order: {len(po_plan)} order, seluruh status terwakili (PO-01)")
w("INSERT INTO purchase_order (id, order_number, supplier_id, warehouse_id, status,")
w("                            order_date, created_by, created_at, updated_at) VALUES")
w(",\n".join(po_rows) + ";")
w()
w("INSERT INTO purchase_order_item (id, purchase_order_id, product_id, quantity,")
w("                                 received_quantity, purchase_price) VALUES")
w(",\n".join(po_items) + ";")
w()

w(f"-- Sales Order: {len(so_plan)} order, termasuk PendingApproval dan Cancelled (SO-01, §7.1)")
w("-- approved_by SELALU berbeda dari created_by - aturan segregation of duties")
w("-- berlaku juga pada data demo (§1.2, FR-018).")
w("INSERT INTO sales_order (id, order_number, customer_id, created_by, approved_by, warehouse_id,")
w("                         status, order_date, approved_at, created_at, updated_at) VALUES")
w(",\n".join(so_rows) + ";")
w()
w("INSERT INTO sales_order_item (id, sales_order_id, product_id, quantity, selling_price) VALUES")
w(",\n".join(so_items) + ";")
w()

# ------------------------------------------------------------- ledger
w(f"-- Stock ledger: {len(ledger)} pergerakan. Append-only.")
w("-- Positif untuk Receipt, negatif untuk Issue.")
w("INSERT INTO stock_ledger (product_id, warehouse_id, movement_type, quantity,")
w("                          reference_type, reference_id, performed_by, created_at) VALUES")
lrows = []
for pid, wid, mtype, qty, rtype, rid, user, day in ledger:
    lrows.append(f"({pid}, {wid}, {q(mtype)}, {qty}, {q(rtype)}, {rid}, {user}, {datetime_expr(day)})")
w(",\n".join(lrows) + ";")
w()

# -------------------------------------------------------- product_stock
w("-- Product stock: DIHITUNG dari stock_ledger di atas, bukan angka lepas.")
w("-- Setiap baris di sini sama dengan SUM(ledger.quantity) untuk pasangan")
w("-- (product, warehouse) tersebut - invariant NFR-002 berlaku sejak seed.")
w("INSERT INTO product_stock (product_id, warehouse_id, quantity, updated_at) VALUES")
srows = []
for pid in range(1, 31):
    for wid in (1, 2):
        qty = stock.get((pid, wid), 0)
        assert qty >= 0, f"stock negatif pada product {pid} warehouse {wid}: {qty}"
        srows.append(f"({pid}, {wid}, {qty}, NOW())")
w(",\n".join(srows) + ";")
w()

# --- ringkasan low stock ---
totals = {}
for (pid, wid), qty in stock.items():
    totals[pid] = totals.get(pid, 0) + qty

low = [pid for pid in range(1, 31) if totals.get(pid, 0) <= reorders[pid - 1]]
w("-- Ringkasan (dihitung saat generate):")
w(f"--   product dengan stock di bawah/at reorder point: {len(low)} dari 30")
w(f"--   id-nya: {', '.join(str(p) for p in low)}")
w(f"--   total order: {len(po_plan)} PO + {len(so_plan)} SO = {len(po_plan) + len(so_plan)}")
w(f"--   baris ledger: {len(ledger)}")

with open('database/002_seed.sql', 'w', encoding='utf-8', newline='\n') as fh:
    fh.write("\n".join(OUT) + "\n")

print("orders:", len(po_plan), "PO +", len(so_plan), "SO =", len(po_plan) + len(so_plan))
print("ledger rows:", len(ledger))
print("stock rows:", len(srows))
print("low-stock products:", len(low), "->", low)
print("min stock:", min(stock.values()) if stock else "n/a", "(must be >= 0)")
