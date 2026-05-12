CREATE DATABASE IF NOT EXISTS alaalamo
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE alaalamo;

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  last_name VARCHAR(100) NOT NULL,
  given_name VARCHAR(100) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  email_verified_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_otps (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  purpose ENUM('registration', 'login') NOT NULL,
  otp_hash VARCHAR(255) NOT NULL,
  expires_at DATETIME NOT NULL,
  consumed_at DATETIME NULL,
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX email_otps_user_purpose_idx (user_id, purpose, consumed_at),
  CONSTRAINT email_otps_user_id_fk
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

CREATE TABLE IF NOT EXISTS memorials (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  qr_group_id INT UNSIGNED NULL,
  public_token CHAR(64) NOT NULL UNIQUE,
  loved_one_name VARCHAR(190) NOT NULL,
  birth_date DATE NULL,
  death_date DATE NULL,
  resting_place VARCHAR(255) NULL,
  memorial_quote TEXT NULL,
  short_description TEXT NULL,
  autobiography_text LONGTEXT NULL,
  autobiography_generated_at DATETIME NULL,
  status ENUM('draft', 'published') NOT NULL DEFAULT 'draft',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX memorials_user_id_idx (user_id),
  INDEX memorials_qr_group_id_idx (qr_group_id),
  CONSTRAINT memorials_user_id_fk
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE,
  CONSTRAINT memorials_qr_group_id_fk
    FOREIGN KEY (qr_group_id) REFERENCES qr_groups(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS memorial_images (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  memorial_id INT UNSIGNED NOT NULL,
  image_path VARCHAR(255) NOT NULL,
  caption VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX memorial_images_memorial_id_idx (memorial_id),
  CONSTRAINT memorial_images_memorial_id_fk
    FOREIGN KEY (memorial_id) REFERENCES memorials(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS milestones (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  memorial_id INT UNSIGNED NOT NULL,
  title VARCHAR(190) NOT NULL,
  milestone_date VARCHAR(80) NULL,
  description TEXT NULL,
  ai_narration_text TEXT NULL,
  narration_audio_path VARCHAR(255) NULL,
  narration_generated_at DATETIME NULL,
  sort_order TINYINT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX milestones_memorial_id_idx (memorial_id),
  CONSTRAINT milestones_memorial_id_fk
    FOREIGN KEY (memorial_id) REFERENCES memorials(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS milestone_images (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  milestone_id INT UNSIGNED NOT NULL,
  image_path VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX milestone_images_milestone_id_idx (milestone_id),
  CONSTRAINT milestone_images_milestone_id_fk
    FOREIGN KEY (milestone_id) REFERENCES milestones(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
