-- Web push subscriptions (browser notifications for admins when the site is closed). One row per browser/device.
-- endpoint_hash is the SHA-256 of the endpoint URL, so the unique key stays short.
CREATE TABLE push_subscriptions (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id       INT UNSIGNED NOT NULL,
    endpoint_hash CHAR(64) NOT NULL,
    endpoint      VARCHAR(1024) NOT NULL,
    p256dh        VARCHAR(255) NOT NULL,
    auth_secret   VARCHAR(64) NOT NULL,
    user_agent    VARCHAR(255) NULL,
    created_at    TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_push_endpoint (endpoint_hash),
    KEY idx_push_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
