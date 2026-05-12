USE alaalamo;

ALTER TABLE memorials
  ADD COLUMN autobiography_text LONGTEXT NULL AFTER short_description,
  ADD COLUMN autobiography_generated_at DATETIME NULL AFTER autobiography_text;

ALTER TABLE milestones
  ADD COLUMN ai_narration_text TEXT NULL AFTER description,
  ADD COLUMN narration_audio_path VARCHAR(255) NULL AFTER ai_narration_text,
  ADD COLUMN narration_generated_at DATETIME NULL AFTER narration_audio_path;
