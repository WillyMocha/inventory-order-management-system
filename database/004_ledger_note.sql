-- =============================================================================
-- Alasan koreksi stock pada stock_ledger (spec 003-stock-adjustment).
--
-- Koreksi manual (stock opname, barang rusak atau hilang) ditulis sebagai
-- movement_type 'Adjustment' dengan reference_type 'Manual'. Kedua nilai itu
-- sudah ada sejak 001_schema.sql mengikuti brief, tetapi belum pernah dipakai.
--
-- Kolom `note` adalah SATU-SATUNYA penyimpangan dari atribut StockLedger pada
-- brief, disetujui pemilik repository (spec A-002, data-model D-1): setiap
-- koreksi wajib membawa alasannya agar angka stock tetap dapat
-- dipertanggungjawabkan. Receipt dan Issue tidak membawa alasan.
--
-- Aturan itu dijaga CHECK constraint, bukan hanya konvensi aplikasi
-- (research R-001):
--   - ck_ledger_note_adjustment  : note wajib ada TEPAT untuk Adjustment;
--   - ck_ledger_adjustment_manual: Adjustment selalu ber-reference Manual, dan
--                                  Manual hanya dipakai Adjustment.
--
-- Seluruh baris yang sudah ada adalah Receipt/Issue dengan note NULL, sehingga
-- kedua CHECK lolos pada database yang sedang berjalan.
--
-- File terpisah dari 001_schema.sql karena migration yang sudah diterapkan
-- tidak boleh diubah — database yang sudah berjalan hanya menerima file baru.
-- =============================================================================

ALTER TABLE `stock_ledger`
    ADD COLUMN `note` VARCHAR(255) NULL AFTER `reference_id`,
    ADD CONSTRAINT `ck_ledger_note_adjustment`
        CHECK ((`movement_type` = 'Adjustment') = (`note` IS NOT NULL)),
    ADD CONSTRAINT `ck_ledger_adjustment_manual`
        CHECK ((`movement_type` = 'Adjustment') = (`reference_type` = 'Manual'));
