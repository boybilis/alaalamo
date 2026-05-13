ALTER TABLE memorials
  ADD COLUMN resting_lat DECIMAL(10, 7) NULL AFTER resting_place,
  ADD COLUMN resting_lng DECIMAL(10, 7) NULL AFTER resting_lat;
