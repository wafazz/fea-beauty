-- Storefront content (module cms_pages): content pages (FAQ, policies) and homepage banners.
-- A page shows only when it is published and its publish time has passed. A banner shows only while it is active
-- and inside its start and end times (blank means no limit).
CREATE TABLE cms_pages (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug            VARCHAR(120) NOT NULL,
    title           VARCHAR(160) NOT NULL,
    body            MEDIUMTEXT NULL,
    status          ENUM('draft','published') NOT NULL DEFAULT 'draft',
    seo_title       VARCHAR(160) NULL,
    seo_description VARCHAR(255) NULL,
    published_at    DATETIME NULL,
    created_at      TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_cms_pages_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE cms_banners (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title       VARCHAR(160) NOT NULL,
    image       VARCHAR(255) NOT NULL,
    link        VARCHAR(255) NULL,
    starts_at   DATETIME NULL,
    ends_at     DATETIME NULL,
    active      TINYINT(1) NOT NULL DEFAULT 1,
    sort_order  INT UNSIGNED NOT NULL DEFAULT 0,
    created_at  TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_cms_banners_show (active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
