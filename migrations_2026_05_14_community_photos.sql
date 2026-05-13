CREATE TABLE IF NOT EXISTS memorial_community_photos (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  memorial_id INT UNSIGNED NOT NULL,
  sender_name VARCHAR(120) NOT NULL,
  sender_email VARCHAR(190) NOT NULL,
  caption VARCHAR(255) NULL,
  temp_image_path VARCHAR(255) NULL,
  image_url VARCHAR(1000) NULL,
  cloudinary_public_id VARCHAR(255) NULL,
  status ENUM('pending', 'approved') NOT NULL DEFAULT 'pending',
  approved_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX memorial_community_photos_memorial_id_idx (memorial_id, status),
  UNIQUE KEY memorial_community_photos_email_unique (memorial_id, sender_email),
  CONSTRAINT memorial_community_photos_memorial_id_fk
    FOREIGN KEY (memorial_id) REFERENCES memorials(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS memorial_photo_otps (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  memorial_id INT UNSIGNED NOT NULL,
  sender_name VARCHAR(120) NOT NULL,
  sender_email VARCHAR(190) NOT NULL,
  caption VARCHAR(255) NULL,
  temp_image_paths TEXT NOT NULL,
  otp_hash VARCHAR(255) NOT NULL,
  expires_at DATETIME NOT NULL,
  consumed_at DATETIME NULL,
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX memorial_photo_otps_lookup_idx (memorial_id, sender_email, consumed_at),
  CONSTRAINT memorial_photo_otps_memorial_id_fk
    FOREIGN KEY (memorial_id) REFERENCES memorials(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
