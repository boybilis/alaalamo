ALTER TABLE qr_groups
  ADD COLUMN payment_status ENUM('pending', 'review', 'paid') NOT NULL DEFAULT 'pending' AFTER plan_type,
  ADD COLUMN payment_requested_at DATETIME NULL AFTER payment_status,
  ADD COLUMN paid_at DATETIME NULL AFTER payment_requested_at,
  ADD COLUMN payment_approval_token CHAR(64) NULL UNIQUE AFTER paid_at;

UPDATE qr_groups
SET payment_status = 'paid', paid_at = NOW()
WHERE payment_status = 'pending';
