ALTER TABLE memorial_images
  ADD COLUMN image_type ENUM('profile', 'gallery') NOT NULL DEFAULT 'gallery' AFTER memorial_id,
  ADD INDEX memorial_images_type_idx (memorial_id, image_type);
