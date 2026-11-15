-- Support tickets (phase 2): guests follow their ticket with a secret link from the email.
-- Only a hash of the token is stored (same approach as order track links and digital grants).
ALTER TABLE support_tickets
    ADD COLUMN access_hash CHAR(64) NULL AFTER ticket_no,
    ADD KEY idx_tickets_access (ticket_no, access_hash);
