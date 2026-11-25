-- Couriers the seller books outside the system (types the tracking number in). source 'api' rows are the existing bookings.
-- Manual rows have no service level, weight or delivery window, so those columns become nullable.
-- The AWB file (PDF/JPG/PNG) lives in storage/awb with a random name and is only sent through an authorised route.
ALTER TABLE shipments
    ADD COLUMN source VARCHAR(10) NOT NULL DEFAULT 'api' AFTER carrier,
    ADD COLUMN tracking_url VARCHAR(500) NULL AFTER tracking_number,
    ADD COLUMN awb_file VARCHAR(80) NULL,
    ADD COLUMN awb_mime VARCHAR(60) NULL,
    ADD COLUMN awb_original_name VARCHAR(190) NULL,
    MODIFY service_level VARCHAR(20) NULL,
    MODIFY weight_kg DECIMAL(6,2) NULL,
    MODIFY delivery_date DATE NULL,
    MODIFY delivery_timeslot VARCHAR(11) NULL;
