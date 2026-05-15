ALTER TABLE memorials
  ADD COLUMN payment_status ENUM('pending', 'review', 'paid') NOT NULL DEFAULT 'pending' AFTER status,
  ADD COLUMN payment_requested_at DATETIME NULL AFTER payment_status,
  ADD COLUMN paid_at DATETIME NULL AFTER payment_requested_at,
  ADD COLUMN payment_approval_token CHAR(64) NULL UNIQUE AFTER paid_at;

UPDATE memorials m
INNER JOIN qr_groups qg ON qg.id = m.qr_group_id
SET
  m.payment_status = qg.payment_status,
  m.payment_requested_at = qg.payment_requested_at,
  m.paid_at = qg.paid_at
WHERE qg.payment_status IN ('review', 'paid');
