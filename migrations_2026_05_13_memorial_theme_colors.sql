ALTER TABLE memorials
  ADD COLUMN theme_primary CHAR(7) NOT NULL DEFAULT '#214c63' AFTER short_description,
  ADD COLUMN theme_secondary CHAR(7) NOT NULL DEFAULT '#eadcc8' AFTER theme_primary,
  ADD COLUMN theme_tertiary CHAR(7) NOT NULL DEFAULT '#fbfaf7' AFTER theme_secondary;
