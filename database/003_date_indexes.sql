-- =============================================================================
-- Index untuk sort tanggal dan filter rentang tanggal (tech-debt TD-6).
--
-- Diukur lebih dulu dengan EXPLAIN terhadap MySQL 8 + data seed, bukan
-- ditebak. Tiga query berikut memindai SELURUH index (type = index) lalu
-- melakukan filesort, karena index yang ada berawal dari kolom lain:
--
--   1. Daftar Sales/Purchase Order tanpa filter status, urut order_date.
--      ix_*_status_date berawal dari `status`, jadi tidak dapat dipakai
--      untuk mengurutkan seluruh order menurut tanggal.
--   2. Report status order: WHERE order_date BETWEEN ... (REPORT-01).
--   3. Report stock movement: WHERE created_at >= ... AND created_at < ...
--      ix_ledger_product_warehouse_date berawal dari product_id.
--
-- Index komposit (status, order_date) yang lama TETAP dipertahankan: ia yang
-- melayani daftar dengan filter status.
--
-- File terpisah dari 001_schema.sql karena migration yang sudah diterapkan
-- tidak boleh diubah — database yang sudah berjalan hanya menerima file baru.
-- =============================================================================

ALTER TABLE `sales_order`
    ADD KEY `ix_sales_order_date` (`order_date`, `id`);

ALTER TABLE `purchase_order`
    ADD KEY `ix_purchase_order_date` (`order_date`, `id`);

ALTER TABLE `stock_ledger`
    ADD KEY `ix_ledger_created_at` (`created_at`, `id`);
