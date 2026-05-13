CREATE TABLE IF NOT EXISTS memorial_messages (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  memorial_id INT UNSIGNED NOT NULL,
  sender_name VARCHAR(120) NOT NULL,
  sender_email VARCHAR(190) NOT NULL,
  message TEXT NOT NULL,
  status ENUM('pending', 'approved') NOT NULL DEFAULT 'pending',
  approved_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX memorial_messages_memorial_id_idx (memorial_id, status),
  CONSTRAINT memorial_messages_memorial_id_fk
    FOREIGN KEY (memorial_id) REFERENCES memorials(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS memorial_message_otps (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  memorial_id INT UNSIGNED NOT NULL,
  sender_name VARCHAR(120) NOT NULL,
  sender_email VARCHAR(190) NOT NULL,
  message TEXT NOT NULL,
  otp_hash VARCHAR(255) NOT NULL,
  expires_at DATETIME NOT NULL,
  consumed_at DATETIME NULL,
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX memorial_message_otps_lookup_idx (memorial_id, sender_email, consumed_at),
  CONSTRAINT memorial_message_otps_memorial_id_fk
    FOREIGN KEY (memorial_id) REFERENCES memorials(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
