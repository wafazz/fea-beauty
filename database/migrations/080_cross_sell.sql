-- Cross-sell suggestions (module cross_sell).
-- product_related: products an admin pins to a product, in order (sort_order). Pinned items come first.
-- product_copurchase: products bought together in recent orders, worked out by bin/copurchase.php (daily).
CREATE TABLE product_related (
    product_id INT UNSIGNED NOT NULL,
    related_id INT UNSIGNED NOT NULL,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (product_id, related_id),
    KEY idx_related_other (related_id),
    CONSTRAINT fk_related_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    CONSTRAINT fk_related_other FOREIGN KEY (related_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE product_copurchase (
    product_id      INT UNSIGNED NOT NULL,
    related_id      INT UNSIGNED NOT NULL,
    orders_together INT UNSIGNED NOT NULL,
    computed_at     DATETIME NOT NULL,
    PRIMARY KEY (product_id, related_id),
    CONSTRAINT fk_copurchase_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    CONSTRAINT fk_copurchase_other FOREIGN KEY (related_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
