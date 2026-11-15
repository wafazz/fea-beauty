-- Support tickets (Docs/support-tickets-plan.md, phase 1). Categories and labels are admin-managed.
-- Each existing per-order thread becomes one ticket, so no message is lost.

CREATE TABLE support_categories (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(80)  NOT NULL,
    slug        VARCHAR(80)  NOT NULL UNIQUE,
    position    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    active      TINYINT(1)   NOT NULL DEFAULT 1,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO support_categories (name, slug, position) VALUES
    ('Order', 'order', 1),
    ('Payment', 'payment', 2),
    ('Shipping', 'shipping', 3),
    ('Return or refund', 'return-refund', 4),
    ('Product', 'product', 5),
    ('Subscription', 'subscription', 6),
    ('Digital product', 'digital-product', 7),
    ('Account', 'account', 8),
    ('Other', 'other', 9);

CREATE TABLE support_labels (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(40)  NOT NULL UNIQUE,
    colour      CHAR(7)      NOT NULL DEFAULT '#64748b',
    active      TINYINT(1)   NOT NULL DEFAULT 1,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE support_tickets (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    -- Set right after the row is created (TK-000123). NULL only between the insert and that update.
    ticket_no           VARCHAR(20)  NULL UNIQUE,
    user_id             INT UNSIGNED NULL,
    guest_email         VARCHAR(190) NULL,
    category_id         INT UNSIGNED NOT NULL,
    subject             VARCHAR(160) NOT NULL,
    urgency             ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
    status              ENUM('open','waiting_customer','waiting_staff','resolved','closed') NOT NULL DEFAULT 'open',
    order_id            INT UNSIGNED NULL,
    subscription_id     INT UNSIGNED NULL,
    assignee_id         INT UNSIGNED NULL,
    customer_read_at    DATETIME     NULL,
    staff_read_at       DATETIME     NULL,
    last_message_at     DATETIME     NOT NULL,
    resolved_at         DATETIME     NULL,
    created_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_tickets_user (user_id, status),
    KEY idx_tickets_queue (status, urgency, last_message_at),
    KEY idx_tickets_order (order_id),
    KEY idx_tickets_subscription (subscription_id),
    KEY idx_tickets_assignee (assignee_id),
    CONSTRAINT fk_tickets_category FOREIGN KEY (category_id) REFERENCES support_categories (id),
    CONSTRAINT fk_tickets_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_tickets_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE SET NULL,
    CONSTRAINT fk_tickets_subscription FOREIGN KEY (subscription_id) REFERENCES subscriptions (id) ON DELETE SET NULL,
    CONSTRAINT fk_tickets_assignee FOREIGN KEY (assignee_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE support_ticket_labels (
    ticket_id   INT UNSIGNED NOT NULL,
    label_id    INT UNSIGNED NOT NULL,
    PRIMARY KEY (ticket_id, label_id),
    KEY idx_ticket_labels_label (label_id),
    CONSTRAINT fk_ticket_labels_ticket FOREIGN KEY (ticket_id) REFERENCES support_tickets (id) ON DELETE CASCADE,
    CONSTRAINT fk_ticket_labels_label FOREIGN KEY (label_id) REFERENCES support_labels (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Messages belong to a ticket. Internal notes are staff-only (visibility = internal).
-- order_id becomes optional: a ticket can exist without an order.
ALTER TABLE support_messages
    MODIFY order_id INT UNSIGNED NULL,
    ADD COLUMN ticket_id INT UNSIGNED NULL AFTER id,
    ADD COLUMN visibility ENUM('public','internal') NOT NULL DEFAULT 'public' AFTER sender,
    ADD KEY idx_support_ticket (ticket_id, id),
    ADD CONSTRAINT fk_support_ticket FOREIGN KEY (ticket_id) REFERENCES support_tickets (id) ON DELETE CASCADE;

CREATE TABLE support_attachments (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ticket_id       INT UNSIGNED NOT NULL,
    message_id      INT UNSIGNED NOT NULL,
    stored_name     CHAR(64)     NOT NULL UNIQUE,
    original_name   VARCHAR(190) NOT NULL,
    mime            VARCHAR(100) NOT NULL,
    size            INT UNSIGNED NOT NULL,
    created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_attachments_message (message_id),
    CONSTRAINT fk_attachments_ticket FOREIGN KEY (ticket_id) REFERENCES support_tickets (id) ON DELETE CASCADE,
    CONSTRAINT fk_attachments_message FOREIGN KEY (message_id) REFERENCES support_messages (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Backfill: one ticket per order that already has messages. Nothing else in the table is touched.
-- Status follows the last message: the customer wrote last -> waiting on staff, otherwise waiting on the customer.
INSERT INTO support_tickets (category_id, subject, urgency, status, order_id, user_id, guest_email, last_message_at, created_at)
SELECT
    (SELECT id FROM support_categories WHERE slug = 'order'),
    CONCAT('Order ', o.order_no),
    'normal',
    CASE WHEN lm.sender = 'customer' THEN 'waiting_staff' ELSE 'waiting_customer' END,
    o.id,
    o.user_id,
    CASE WHEN o.user_id IS NULL THEN o.customer_email ELSE NULL END,
    lm.created_at,
    fm.created_at
FROM orders o
JOIN (SELECT order_id, MIN(id) AS first_id, MAX(id) AS last_id FROM support_messages GROUP BY order_id) g ON g.order_id = o.id
JOIN support_messages fm ON fm.id = g.first_id
JOIN support_messages lm ON lm.id = g.last_id;

UPDATE support_tickets SET ticket_no = CONCAT('TK-', LPAD(id, 6, '0')) WHERE ticket_no IS NULL;

UPDATE support_messages m
JOIN support_tickets t ON t.order_id = m.order_id
SET m.ticket_id = t.id
WHERE m.ticket_id IS NULL;
