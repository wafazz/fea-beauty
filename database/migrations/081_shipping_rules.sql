-- Shipping rules (module shipping_rules): store pickup points, and how an order is fulfilled.
-- A pickup order keeps the point's address in the ship_* columns and records the point, so the order still reads correctly
-- if the point is later changed or switched off (pickup_name is a copy of the name at the time of the order).
CREATE TABLE pickup_points (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(120) NOT NULL,
    line1         VARCHAR(255) NOT NULL,
    city          VARCHAR(100) NOT NULL,
    state         VARCHAR(60)  NOT NULL,
    postcode      VARCHAR(10)  NOT NULL,
    opening_hours VARCHAR(160) NULL,
    active        TINYINT(1)   NOT NULL DEFAULT 1,
    sort_order    INT UNSIGNED NOT NULL DEFAULT 0,
    created_at    TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_pickup_active (active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE orders
    ADD COLUMN fulfilment      VARCHAR(10)  NOT NULL DEFAULT 'delivery' AFTER payment_method,
    ADD COLUMN pickup_point_id INT UNSIGNED NULL AFTER fulfilment,
    ADD COLUMN pickup_name     VARCHAR(120) NULL AFTER pickup_point_id,
    ADD KEY idx_orders_pickup (pickup_point_id),
    ADD CONSTRAINT fk_orders_pickup FOREIGN KEY (pickup_point_id) REFERENCES pickup_points(id) ON DELETE SET NULL;
