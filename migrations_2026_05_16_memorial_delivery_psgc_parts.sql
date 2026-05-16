ALTER TABLE memorials
  ADD COLUMN delivery_region_code VARCHAR(20) NULL AFTER delivery_address,
  ADD COLUMN delivery_region_name VARCHAR(150) NULL AFTER delivery_region_code,
  ADD COLUMN delivery_province_code VARCHAR(20) NULL AFTER delivery_region_name,
  ADD COLUMN delivery_province_name VARCHAR(150) NULL AFTER delivery_province_code,
  ADD COLUMN delivery_city_code VARCHAR(20) NULL AFTER delivery_province_name,
  ADD COLUMN delivery_city_name VARCHAR(150) NULL AFTER delivery_city_code,
  ADD COLUMN delivery_barangay_code VARCHAR(20) NULL AFTER delivery_city_name,
  ADD COLUMN delivery_barangay_name VARCHAR(150) NULL AFTER delivery_barangay_code,
  ADD COLUMN delivery_exact_address VARCHAR(255) NULL AFTER delivery_barangay_name;
