-- NextaPost 2.0: ideas ↔ posts link, feed automation, per-user media source connections.
ALTER TABLE ideas ADD COLUMN post_id BIGINT UNSIGNED NULL;
ALTER TABLE rss_feeds ADD COLUMN auto_mode VARCHAR(10) NOT NULL DEFAULT 'inbox';
ALTER TABLE rss_feeds ADD COLUMN fail_count INT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE rss_items ADD COLUMN dismissed_at DATETIME NULL;

CREATE TABLE IF NOT EXISTS media_connections (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  source VARCHAR(20) NOT NULL,
  account_name VARCHAR(200) NULL,
  credentials_enc TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  UNIQUE (user_id, source),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_rss_items_feed ON rss_items (feed_id, published_at);
