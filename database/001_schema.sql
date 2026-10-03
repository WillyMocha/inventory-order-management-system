-- =============================================================================
-- Schema Inventory & Order Management System
-- =============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 1;

-- -----------------------------------------------------------------------------
-- user  (src: User)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `user` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`          VARCHAR(150)    NOT NULL,                 -- src: Nama
    `email`         VARCHAR(190)    NOT NULL,                 -- src: email
    `password_hash` VARCHAR(255)    NOT NULL,                 -- src: password
    `role`          ENUM('Admin', 'Sales', 'WarehouseStaff') NOT NULL,
    `is_active`     TINYINT(1)      NOT NULL DEFAULT 1,       -- src: status aktif
    `created_at`    DATETIME        NOT NULL,
    `updated_at`    DATETIME        NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_user_email` (`email`),
    KEY `ix_user_role_active` (`role`, `is_active`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- warehouse  (src: Warehouse)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `warehouse` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`       VARCHAR(150)    NOT NULL,                    -- src: Nama
    `location`   VARCHAR(255)    NOT NULL,                    -- src: lokasi
    `is_active`  TINYINT(1)      NOT NULL DEFAULT 1,          -- src: status aktif
    `created_at` DATETIME        NOT NULL,
    `updated_at` DATETIME        NOT NULL,
    PRIMARY KEY (`id`),
    KEY `ix_warehouse_active` (`is_active`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- category  (src: Category)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `category` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`        VARCHAR(150)    NOT NULL,                   -- src: Nama
    `description` TEXT            NULL,                       -- src: deskripsi
    `created_at`  DATETIME        NOT NULL,
    `updated_at`  DATETIME        NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_category_name` (`name`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- product  (src: Product)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `product` (
    `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `sku`            VARCHAR(64)     NOT NULL,                -- src: SKU (unik)
    `name`           VARCHAR(200)    NOT NULL,                -- src: nama
    `category_id`    BIGINT UNSIGNED NOT NULL,                -- src: kategori
    `unit`           VARCHAR(32)     NOT NULL,                -- src: unit
    `purchase_price` DECIMAL(15, 2)  NOT NULL,                -- src: harga beli
    `selling_price`  DECIMAL(15, 2)  NOT NULL,                -- src: harga jual
    `reorder_point`  INT             NOT NULL,                -- src: reorder point
    `image_path`     VARCHAR(255)    NULL,                    -- src: gambar (opsional)
    `is_active`      TINYINT(1)      NOT NULL DEFAULT 1,      -- src: status aktif
    `created_at`     DATETIME        NOT NULL,
    `updated_at`     DATETIME        NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_product_sku` (`sku`),
    KEY `ix_product_category` (`category_id`),
    KEY `ix_product_active_name` (`is_active`, `name`),
    CONSTRAINT `fk_product_category`
        FOREIGN KEY (`category_id`) REFERENCES `category` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `ck_product_purchase_price` CHECK (`purchase_price` >= 0),
    CONSTRAINT `ck_product_selling_price` CHECK (`selling_price` >= 0),
    CONSTRAINT `ck_product_reorder_point` CHECK (`reorder_point` >= 0)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- product_stock  (src: ProductStock)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `product_stock` (
    `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `product_id`   BIGINT UNSIGNED NOT NULL,                  -- src: Produk
    `warehouse_id` BIGINT UNSIGNED NOT NULL,                  -- src: gudang
    `quantity`     INT             NOT NULL DEFAULT 0,        -- src: quantity
    `updated_at`   DATETIME        NOT NULL,                  -- src: updated_at
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_product_stock_product_warehouse` (`product_id`, `warehouse_id`),
    KEY `ix_product_stock_warehouse` (`warehouse_id`),
    CONSTRAINT `fk_product_stock_product`
        FOREIGN KEY (`product_id`) REFERENCES `product` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_product_stock_warehouse`
        FOREIGN KEY (`warehouse_id`) REFERENCES `warehouse` (`id`) ON DELETE RESTRICT,
    -- Constraint yang dinyatakan eksplisit oleh sumber.
    CONSTRAINT `ck_product_stock_quantity` CHECK (`quantity` >= 0)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- supplier  (src: Supplier)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `supplier` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`       VARCHAR(150)    NOT NULL,                    -- src: Nama
    `contact`    VARCHAR(150)    NOT NULL,                    -- src: kontak
    `address`    TEXT            NOT NULL,                    -- src: alamat
    `is_active`  TINYINT(1)      NOT NULL DEFAULT 1,          -- src: status aktif
    `created_at` DATETIME        NOT NULL,
    `updated_at` DATETIME        NOT NULL,
    PRIMARY KEY (`id`),
    KEY `ix_supplier_active_name` (`is_active`, `name`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- customer  (src: Customer)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `customer` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`       VARCHAR(150)    NOT NULL,                    -- src: Nama
    `contact`    VARCHAR(150)    NOT NULL,                    -- src: kontak
    `address`    TEXT            NOT NULL,                    -- src: alamat
    `is_active`  TINYINT(1)      NOT NULL DEFAULT 1,          -- src: status aktif
    `created_at` DATETIME        NOT NULL,
    `updated_at` DATETIME        NOT NULL,
    PRIMARY KEY (`id`),
    KEY `ix_customer_active_name` (`is_active`, `name`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- purchase_order  (src: PurchaseOrder)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `purchase_order` (
    `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `order_number` VARCHAR(32)     NOT NULL,
    `supplier_id`  BIGINT UNSIGNED NOT NULL,                  -- src: Supplier
    `warehouse_id` BIGINT UNSIGNED NOT NULL,                  -- src: gudang tujuan
    `status`       ENUM('Draft', 'Ordered', 'PartiallyReceived', 'Received', 'Cancelled')
                   NOT NULL DEFAULT 'Draft',                  -- src: status
    `order_date`   DATE            NOT NULL,                  -- src: tanggal order
    `created_by`   BIGINT UNSIGNED NOT NULL,
    `created_at`   DATETIME        NOT NULL,
    `updated_at`   DATETIME        NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_purchase_order_number` (`order_number`),
    KEY `ix_purchase_order_status_date` (`status`, `order_date`),
    KEY `ix_purchase_order_supplier` (`supplier_id`),
    KEY `ix_purchase_order_warehouse` (`warehouse_id`),
    CONSTRAINT `fk_purchase_order_supplier`
        FOREIGN KEY (`supplier_id`) REFERENCES `supplier` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_purchase_order_warehouse`
        FOREIGN KEY (`warehouse_id`) REFERENCES `warehouse` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_purchase_order_created_by`
        FOREIGN KEY (`created_by`) REFERENCES `user` (`id`) ON DELETE RESTRICT
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- purchase_order_item  (src: PurchaseOrder -> Item [1..*])
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `purchase_order_item` (
    `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `purchase_order_id` BIGINT UNSIGNED NOT NULL,
    `product_id`        BIGINT UNSIGNED NOT NULL,             -- src: produk
    `quantity`          INT             NOT NULL,             -- src: qty
    `received_quantity` INT             NOT NULL DEFAULT 0,
    `purchase_price`    DECIMAL(15, 2)  NOT NULL,             -- src: harga beli
    PRIMARY KEY (`id`),
    KEY `ix_po_item_order` (`purchase_order_id`),
    KEY `ix_po_item_product` (`product_id`),
    CONSTRAINT `fk_po_item_order`
        FOREIGN KEY (`purchase_order_id`) REFERENCES `purchase_order` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_po_item_product`
        FOREIGN KEY (`product_id`) REFERENCES `product` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `ck_po_item_quantity` CHECK (`quantity` > 0),
    CONSTRAINT `ck_po_item_purchase_price` CHECK (`purchase_price` >= 0),
    CONSTRAINT `ck_po_item_received` CHECK (`received_quantity` >= 0 AND `received_quantity` <= `quantity`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- sales_order  (src: SalesOrder)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sales_order` (
    `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `order_number` VARCHAR(32)     NOT NULL,
    `customer_id`  BIGINT UNSIGNED NOT NULL,                  -- src: Customer
    `created_by`   BIGINT UNSIGNED NOT NULL,                  -- src: dibuat oleh
    `approved_by`  BIGINT UNSIGNED NULL,                      -- src: disetujui oleh
    `warehouse_id` BIGINT UNSIGNED NOT NULL,                  -- src: gudang asal
    `status`       ENUM('Draft', 'PendingApproval', 'Approved', 'Fulfilled', 'Cancelled')
                   NOT NULL DEFAULT 'Draft',                  -- src: status
    `order_date`   DATE            NOT NULL,
    `approved_at`  DATETIME        NULL,
    `created_at`   DATETIME        NOT NULL,
    `updated_at`   DATETIME        NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_sales_order_number` (`order_number`),
    KEY `ix_sales_order_status_date` (`status`, `order_date`),
    KEY `ix_sales_order_created_by` (`created_by`),
    KEY `ix_sales_order_customer` (`customer_id`),
    KEY `ix_sales_order_warehouse` (`warehouse_id`),
    CONSTRAINT `fk_sales_order_customer`
        FOREIGN KEY (`customer_id`) REFERENCES `customer` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_sales_order_created_by`
        FOREIGN KEY (`created_by`) REFERENCES `user` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_sales_order_approved_by`
        FOREIGN KEY (`approved_by`) REFERENCES `user` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_sales_order_warehouse`
        FOREIGN KEY (`warehouse_id`) REFERENCES `warehouse` (`id`) ON DELETE RESTRICT
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- sales_order_item  (src: SalesOrder -> Item [1..*])
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sales_order_item` (
    `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `sales_order_id` BIGINT UNSIGNED NOT NULL,
    `product_id`     BIGINT UNSIGNED NOT NULL,                -- src: produk
    `quantity`       INT             NOT NULL,                -- src: qty
    `selling_price`  DECIMAL(15, 2)  NOT NULL,                -- src: harga jual
    PRIMARY KEY (`id`),
    KEY `ix_so_item_order` (`sales_order_id`),
    KEY `ix_so_item_product` (`product_id`),
    CONSTRAINT `fk_so_item_order`
        FOREIGN KEY (`sales_order_id`) REFERENCES `sales_order` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_so_item_product`
        FOREIGN KEY (`product_id`) REFERENCES `product` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `ck_so_item_quantity` CHECK (`quantity` > 0),
    CONSTRAINT `ck_so_item_selling_price` CHECK (`selling_price` >= 0)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- stock_ledger  (src: StockLedger)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `stock_ledger` (
    `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `product_id`     BIGINT UNSIGNED NOT NULL,                -- src: Produk
    `warehouse_id`   BIGINT UNSIGNED NOT NULL,                -- src: gudang
    `movement_type`  ENUM('Receipt', 'Issue', 'Adjustment') NOT NULL,
    `quantity`       INT             NOT NULL,                -- src: quantity
    `reference_type` ENUM('PurchaseOrder', 'SalesOrder', 'Manual') NOT NULL,
    `reference_id`   BIGINT UNSIGNED NULL,                    -- src: referensi (PO/SO id)
    `performed_by`   BIGINT UNSIGNED NOT NULL,                -- src: dilakukan oleh
    `created_at`     DATETIME        NOT NULL,                -- src: timestamp
    PRIMARY KEY (`id`),
    KEY `ix_ledger_product_warehouse_date` (`product_id`, `warehouse_id`, `created_at`),
    KEY `ix_ledger_reference` (`reference_type`, `reference_id`),
    KEY `ix_ledger_performed_by` (`performed_by`),
    CONSTRAINT `fk_ledger_product`
        FOREIGN KEY (`product_id`) REFERENCES `product` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_ledger_warehouse`
        FOREIGN KEY (`warehouse_id`) REFERENCES `warehouse` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_ledger_performed_by`
        FOREIGN KEY (`performed_by`) REFERENCES `user` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `ck_ledger_quantity_nonzero` CHECK (`quantity` <> 0),
    -- reference_id boleh NULL hanya ketika reference_type = 'Manual'.
    CONSTRAINT `ck_ledger_reference_id` CHECK (
        (`reference_type` = 'Manual' AND `reference_id` IS NULL)
        OR (`reference_type` <> 'Manual' AND `reference_id` IS NOT NULL)
    )
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- login_attempt  (operational — BUKAN resource sumber)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `login_attempt` (
    `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    -- Dicatat walaupun email-nya tidak terdaftar, agar timing tidak membocorkan
    -- keberadaan akun.
    `email`        VARCHAR(190)    NOT NULL,
    `ip_address`   VARBINARY(16)   NOT NULL,
    `attempted_at` DATETIME        NOT NULL,
    `succeeded`    TINYINT(1)      NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `ix_login_attempt_email_time` (`email`, `attempted_at`),
    KEY `ix_login_attempt_ip_time` (`ip_address`, `attempted_at`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- schema_migration  (operational — BUKAN resource sumber)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `schema_migration` (
    `filename`   VARCHAR(255) NOT NULL,
    `applied_at` DATETIME     NOT NULL,
    PRIMARY KEY (`filename`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
