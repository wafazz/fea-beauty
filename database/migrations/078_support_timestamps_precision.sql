-- Support tickets (phase 4): unread and "waiting" marks compare times, so they need sub-second precision.
-- Without it, a read and a reply in the same second look identical and the badge can stick or be missed.
ALTER TABLE support_tickets
    MODIFY last_message_at DATETIME(3) NOT NULL,
    MODIFY customer_read_at DATETIME(3) NULL,
    MODIFY staff_read_at DATETIME(3) NULL,
    MODIFY resolved_at DATETIME(3) NULL;

ALTER TABLE support_messages
    MODIFY created_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3);
