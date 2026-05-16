ALTER TABLE users
  ADD COLUMN referral_code VARCHAR(32) NULL UNIQUE AFTER email,
  ADD COLUMN referred_by_user_id INT UNSIGNED NULL AFTER referral_code,
  ADD COLUMN referred_by_code VARCHAR(32) NULL AFTER referred_by_user_id,
  ADD COLUMN referral_paid_qualified_at DATETIME NULL AFTER referred_by_code,
  ADD INDEX users_referred_by_user_id_idx (referred_by_user_id),
  ADD CONSTRAINT users_referred_by_user_id_fk
    FOREIGN KEY (referred_by_user_id) REFERENCES users(id)
    ON DELETE SET NULL;
