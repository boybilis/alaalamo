ALTER TABLE memorials
  ADD COLUMN plan_type ENUM('regular', 'premium') NOT NULL DEFAULT 'regular' AFTER qr_group_id;

UPDATE memorials m
INNER JOIN qr_groups qg ON qg.id = m.qr_group_id
SET m.plan_type = qg.plan_type;
