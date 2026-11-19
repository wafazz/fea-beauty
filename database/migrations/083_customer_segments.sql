-- Customer segments (module customer_segments): saved rules that group customers by what they buy, and manual tags.
-- rules is JSON with any of: min_orders, min_spent, within_days (the window for orders and spend), inactive_days
-- (no order in that many days, or never ordered), tag. Rules combine with AND.
CREATE TABLE customer_segments (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(120) NOT NULL,
    rules       JSON NOT NULL,
    created_by  INT UNSIGNED NULL,
    created_at  TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_customer_segments_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE customer_tags (
    user_id INT UNSIGNED NOT NULL,
    tag     VARCHAR(30) NOT NULL,
    PRIMARY KEY (user_id, tag),
    KEY idx_customer_tags_tag (tag),
    CONSTRAINT fk_customer_tags_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
