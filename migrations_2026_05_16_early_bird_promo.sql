ALTER TABLE memorials
  ADD COLUMN early_bird_upgraded_at DATETIME NULL AFTER payment_approval_token,
  ADD COLUMN early_bird_notice_shown_at DATETIME NULL AFTER early_bird_upgraded_at;
