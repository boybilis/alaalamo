USE alaalamo;

ALTER TABLE memorials
  ADD COLUMN memorial_quote TEXT NULL AFTER resting_place;
