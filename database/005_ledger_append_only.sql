-- =============================================================================
-- stock_ledger append-only dijaga database, bukan hanya konvensi (tech-debt TD-9).
--
-- Sebelumnya hanya aplikasi yang menahan diri: StockService satu-satunya penulis
-- ledger, dan repository-nya tidak punya method update maupun delete. Satu UPDATE
-- manual lewat SQL client dengan user aplikasi tetap dapat mengubah riwayat stock
-- tanpa jejak. Kedua trigger di bawah menutup celah itu di MySQL sendiri:
--
--   - trg_stock_ledger_no_update: UPDATE SELALU ditolak. Tidak ada pengecualian —
--     koreksi stock adalah baris Adjustment baru, tidak pernah perubahan baris lama.
--   - trg_stock_ledger_no_delete: DELETE ditolak, KECUALI sesi yang sengaja
--     menyetel @ioms_allow_ledger_cleanup = 1. Pengecualian ini hanya dipakai
--     pembersihan fixture integration test (IntegrationTestCase::truncateAll,
--     SalesOrderFixtures::cleanUpSalesOrderFixtures) dan tidak pernah oleh
--     aplikasi. Ia tidak membuka celah baru: siapa pun yang dapat menyetel
--     variabel sesi itu juga dapat men-DROP trigger-nya, tetapi keduanya kini
--     adalah tindakan eksplisit, bukan kecelakaan.
--
-- DROP TABLE (migrate.php --fresh) tidak memicu trigger DELETE, sehingga
-- membangun ulang database tetap berjalan.
--
-- Membuat trigger dengan user non-SUPER saat binary log aktif memerlukan
-- --log-bin-trust-function-creators=1 pada server MySQL (compose.yaml).
--
-- File terpisah karena migration yang sudah diterapkan tidak boleh diubah.
-- =============================================================================

CREATE TRIGGER `trg_stock_ledger_no_update`
BEFORE UPDATE ON `stock_ledger`
FOR EACH ROW
SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'stock_ledger is append-only: rows cannot be updated';

CREATE TRIGGER `trg_stock_ledger_no_delete`
BEFORE DELETE ON `stock_ledger`
FOR EACH ROW
BEGIN
    IF COALESCE(@ioms_allow_ledger_cleanup, 0) <> 1 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'stock_ledger is append-only: rows cannot be deleted';
    END IF;
END;
