-- v1.1.0: document uploads (PDF/PPTX/DOCX) and different media per channel.
ALTER TABLE media ADD COLUMN pages INT UNSIGNED NULL;
ALTER TABLE post_targets ADD COLUMN media_custom TINYINT NOT NULL DEFAULT 0;
CREATE TABLE IF NOT EXISTS target_media (
  target_id BIGINT UNSIGNED NOT NULL,
  media_id BIGINT UNSIGNED NOT NULL,
  sort_order INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (target_id, media_id),
  FOREIGN KEY (target_id) REFERENCES post_targets(id) ON DELETE CASCADE,
  FOREIGN KEY (media_id) REFERENCES media(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
