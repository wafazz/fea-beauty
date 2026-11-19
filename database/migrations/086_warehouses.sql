-- Multiple warehouses (module multi_warehouse). Stock totals stay in products.stock and product_variants.stock.
-- The default warehouse holds whatever is not in another warehouse, so existing stock needs no copying: it is all in
-- the default warehouse. Other warehouses keep an explicit count in warehouse_stock (variant_id 0 = no variations).
-- An order records the warehouse it took stock from (orders.warehouse_id), so a cancel or return puts it back there.
CREATE TABLE warehouses (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(120) NOT NULL,
    state       VARCHAR(60) NULL,
    postcode    VARCHAR(10) NULL,
    is_default  TINYINT(1) NOT NULL DEFAULT 0,
    active      TINYINT(1) NOT NULL DEFAULT 1,
    sort_order  INT UNSIGNED NOT NULL DEFAULT 0,
    created_at  TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE warehouse_stock (
    warehouse_id INT UNSIGNED NOT NULL,
    product_id   INT UNSIGNED NOT NULL,
    variant_id   INT UNSIGNED NOT NULL DEFAULT 0,
    qty          INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (warehouse_id, product_id, variant_id),
    KEY idx_warehouse_stock_product (product_id, variant_id),
    CONSTRAINT fk_warehouse_stock_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouses(id) ON DELETE CASCADE,
    CONSTRAINT fk_warehouse_stock_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE orders
    ADD COLUMN warehouse_id INT UNSIGNED NULL,
    ADD KEY idx_orders_warehouse (warehouse_id);

INSERT INTO warehouses (name, is_default, active, sort_order) VALUES ('Main warehouse', 1, 1, 0);
