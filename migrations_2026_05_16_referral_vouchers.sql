CREATE TABLE IF NOT EXISTS referral_vouchers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  voucher_code VARCHAR(40) NOT NULL UNIQUE,
  reward_type ENUM('premium_memorial') NOT NULL DEFAULT 'premium_memorial',
  earned_for_referral_count INT UNSIGNED NOT NULL DEFAULT 5,
  emailed_at DATETIME NULL,
  redeemed_memorial_id INT UNSIGNED NULL,
  redeemed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX referral_vouchers_user_id_idx (user_id),
  INDEX referral_vouchers_redeemed_memorial_id_idx (redeemed_memorial_id),
  CONSTRAINT referral_vouchers_user_id_fk
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE,
  CONSTRAINT referral_vouchers_redeemed_memorial_id_fk
    FOREIGN KEY (redeemed_memorial_id) REFERENCES memorials(id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
