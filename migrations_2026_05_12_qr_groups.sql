USE alaalamo;

CREATE TABLE IF NOT EXISTS qr_groups (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  public_token CHAR(64) NOT NULL UNIQUE,
  plan_type ENUM('regular', 'premium') NOT NULL DEFAULT 'premium',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX qr_groups_user_id_idx (user_id),
  CONSTRAINT qr_groups_user_id_fk
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE memorials
  ADD COLUMN IF NOT EXISTS qr_group_id INT UNSIGNED NULL AFTER user_id;

ALTER TABLE memorials
  ADD INDEX IF NOT EXISTS memorials_qr_group_id_idx (qr_group_id);
