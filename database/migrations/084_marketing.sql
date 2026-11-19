-- Marketing flows (module marketing_flows): automatic emails. Three flows, each switched off until an admin turns it on:
--   welcome         after a member confirms their email address
--   review_request  after an order is completed (a member's order only)
--   win_back        to the members of a customer segment, at most once per repeat_days
-- marketing_sends records each email once: (flow, customer, ref) is unique, so a repeated trigger never sends twice.
CREATE TABLE marketing_flows (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    flow_key     VARCHAR(40) NOT NULL,
    name         VARCHAR(120) NOT NULL,
    delay_hours  INT UNSIGNED NOT NULL DEFAULT 0,
    subject      VARCHAR(190) NOT NULL,
    body         TEXT NOT NULL,
    enabled      TINYINT(1) NOT NULL DEFAULT 0,
    segment_id   INT UNSIGNED NULL,
    repeat_days  INT UNSIGNED NULL,
    updated_at   TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_marketing_flow_key (flow_key),
    CONSTRAINT fk_marketing_flow_segment FOREIGN KEY (segment_id) REFERENCES customer_segments(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE marketing_sends (
    id       BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    flow_id  INT UNSIGNED NOT NULL,
    user_id  INT UNSIGNED NOT NULL,
    ref      VARCHAR(60) NOT NULL,
    due_at   DATETIME NOT NULL,
    sent_at  DATETIME NULL,
    status   ENUM('pending','sent','skipped','failed') NOT NULL DEFAULT 'pending',
    note     VARCHAR(190) NULL,
    UNIQUE KEY uq_marketing_send (flow_id, user_id, ref),
    KEY idx_marketing_due (status, due_at),
    CONSTRAINT fk_marketing_send_flow FOREIGN KEY (flow_id) REFERENCES marketing_flows(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_send_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO marketing_flows (flow_key, name, delay_hours, subject, body, enabled, segment_id, repeat_days) VALUES
('welcome', 'Welcome email', 1,
 'Welcome to {shop}, {name}!',
 'Hi {name},\n\nThanks for joining {shop}. Your email address is confirmed, so you can save favourites, check out faster and track your orders.\n\nSee you soon!',
 0, NULL, NULL),
('review_request', 'Review request after an order', 72,
 'How was your order {order_no}?',
 'Hi {name},\n\nThanks for shopping with {shop}. If you have a minute, tell us what you thought of order {order_no}: {review_url}\n\nThank you!',
 0, NULL, NULL),
('win_back', 'Win-back to a customer segment', 0,
 'We miss you at {shop}',
 'Hi {name},\n\nIt has been a while. Come and see what is new at {shop}: {shop_url}',
 0, NULL, 90);
