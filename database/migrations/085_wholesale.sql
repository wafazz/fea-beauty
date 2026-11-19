-- Wholesale accounts (module wholesale_accounts): business buyers who may order on credit terms (pay later).
-- A credit order uses the payment method credit_terms and has a due date. The unpaid credit orders of an account are its
-- balance; the balance may not go over the credit limit. Recording a payment marks the order paid, which clears it.
CREATE TABLE b2b_accounts (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id      INT UNSIGNED NOT NULL,
    company      VARCHAR(160) NOT NULL,
    reg_no       VARCHAR(60) NULL,
    credit_limit DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    terms_days   INT UNSIGNED NOT NULL DEFAULT 30,
    status       ENUM('pending','approved','suspended') NOT NULL DEFAULT 'pending',
    created_at   TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_b2b_user (user_id),
    CONSTRAINT fk_b2b_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE orders
    ADD COLUMN due_date DATE NULL,
    ADD KEY idx_orders_due_date (due_date);
