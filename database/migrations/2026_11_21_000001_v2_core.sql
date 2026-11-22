-- NextaPost 2.0: agents (API keys, OAuth, webhooks), queues, labels, signatures, notes,
-- templates, ideas, feeds, repurpose, metrics, AI settings and languages.

-- Channels: posting schedule and queue
ALTER TABLE channels ADD COLUMN timezone VARCHAR(64) NULL;
ALTER TABLE channels ADD COLUMN posting_goal INT UNSIGNED NULL;
ALTER TABLE channels ADD COLUMN posting_schedule TEXT NULL;

-- Posts: recurrence, queue mode
ALTER TABLE posts ADD COLUMN recurrence TEXT NULL;
ALTER TABLE posts ADD COLUMN recurrence_parent_id BIGINT UNSIGNED NULL;
ALTER TABLE posts ADD COLUMN schedule_mode VARCHAR(10) NOT NULL DEFAULT 'custom';
ALTER TABLE posts ADD COLUMN idea_id BIGINT UNSIGNED NULL;

-- Targets: per-network content type and options (poll, first comment, board, privacy...)
ALTER TABLE post_targets ADD COLUMN content_type VARCHAR(30) NULL;
ALTER TABLE post_targets ADD COLUMN options_json TEXT NULL;

-- Workspaces: AI and language
ALTER TABLE workspaces ADD COLUMN ai_provider VARCHAR(20) NULL;
ALTER TABLE workspaces ADD COLUMN ai_key_enc TEXT NULL;
ALTER TABLE workspaces ADD COLUMN ai_model VARCHAR(100) NULL;
ALTER TABLE workspaces ADD COLUMN ai_base_url VARCHAR(300) NULL;
ALTER TABLE workspaces ADD COLUMN brand_voice VARCHAR(1000) NULL;

-- Users: language and notifications
ALTER TABLE users ADD COLUMN locale VARCHAR(10) NULL;
ALTER TABLE users ADD COLUMN notify_json TEXT NULL;

-- Media: where it came from
ALTER TABLE media ADD COLUMN source VARCHAR(20) NOT NULL DEFAULT 'upload';
ALTER TABLE media ADD COLUMN attribution VARCHAR(500) NULL;

CREATE TABLE IF NOT EXISTS api_keys (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid CHAR(36) NOT NULL,
  workspace_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(100) NOT NULL,
  prefix VARCHAR(16) NOT NULL,
  token_hash CHAR(64) NOT NULL,
  role VARCHAR(20) NOT NULL DEFAULT 'editor',
  last_used_at DATETIME NULL,
  expires_at DATETIME NULL,
  revoked_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (uuid),
  UNIQUE (token_hash),
  FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS oauth_clients (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id VARCHAR(700) NOT NULL,
  client_id_hash CHAR(64) NOT NULL,
  name VARCHAR(200) NOT NULL,
  redirect_uris TEXT NOT NULL,
  logo_uri VARCHAR(700) NULL,
  client_uri VARCHAR(700) NULL,
  is_metadata_doc TINYINT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_used_at DATETIME NULL,
  UNIQUE (client_id_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS oauth_codes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code_hash CHAR(64) NOT NULL,
  client_pk BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  workspace_id BIGINT UNSIGNED NOT NULL,
  redirect_uri VARCHAR(700) NOT NULL,
  code_challenge VARCHAR(128) NOT NULL,
  scope VARCHAR(200) NOT NULL DEFAULT 'nextapost',
  resource VARCHAR(500) NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  UNIQUE (code_hash),
  FOREIGN KEY (client_pk) REFERENCES oauth_clients(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS oauth_tokens (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_pk BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  workspace_id BIGINT UNSIGNED NOT NULL,
  access_hash CHAR(64) NOT NULL,
  refresh_hash CHAR(64) NULL,
  scope VARCHAR(200) NOT NULL DEFAULT 'nextapost',
  access_expires_at DATETIME NOT NULL,
  refresh_expires_at DATETIME NULL,
  revoked_at DATETIME NULL,
  last_used_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (access_hash),
  UNIQUE (refresh_hash),
  FOREIGN KEY (client_pk) REFERENCES oauth_clients(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS webhooks (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid CHAR(36) NOT NULL,
  workspace_id BIGINT UNSIGNED NOT NULL,
  url VARCHAR(700) NOT NULL,
  events TEXT NOT NULL,
  secret_enc TEXT NOT NULL,
  status VARCHAR(10) NOT NULL DEFAULT 'enabled',
  failures INT UNSIGNED NOT NULL DEFAULT 0,
  last_sent_at DATETIME NULL,
  paused_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (uuid),
  FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS webhook_deliveries (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid CHAR(36) NOT NULL,
  webhook_id BIGINT UNSIGNED NOT NULL,
  event VARCHAR(50) NOT NULL,
  payload TEXT NOT NULL,
  status VARCHAR(10) NOT NULL DEFAULT 'pending',
  response_code INT NULL,
  response_body VARCHAR(1000) NULL,
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  next_attempt_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  delivered_at DATETIME NULL,
  UNIQUE (uuid),
  FOREIGN KEY (webhook_id) REFERENCES webhooks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS labels (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid CHAR(36) NOT NULL,
  workspace_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(60) NOT NULL,
  color VARCHAR(9) NOT NULL DEFAULT '#7C3AED',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (uuid),
  UNIQUE (workspace_id, name),
  FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS post_labels (
  post_id BIGINT UNSIGNED NOT NULL,
  label_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (post_id, label_id),
  FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
  FOREIGN KEY (label_id) REFERENCES labels(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS signatures (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid CHAR(36) NOT NULL,
  workspace_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(80) NOT NULL,
  content TEXT NOT NULL,
  is_default TINYINT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  UNIQUE (uuid),
  FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS post_templates (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid CHAR(36) NOT NULL,
  workspace_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  title VARCHAR(120) NOT NULL,
  body TEXT NOT NULL,
  visibility VARCHAR(10) NOT NULL DEFAULT 'workspace',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  UNIQUE (uuid),
  FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS post_notes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid CHAR(36) NOT NULL,
  post_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  body TEXT NOT NULL,
  mentions TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  edited_at DATETIME NULL,
  UNIQUE (uuid),
  FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS idea_stages (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid CHAR(36) NOT NULL,
  workspace_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(60) NOT NULL,
  position INT UNSIGNED NOT NULL DEFAULT 0,
  UNIQUE (uuid),
  FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ideas (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid CHAR(36) NOT NULL,
  workspace_id BIGINT UNSIGNED NOT NULL,
  stage_id BIGINT UNSIGNED NULL,
  user_id BIGINT UNSIGNED NULL,
  title VARCHAR(200) NULL,
  body TEXT NULL,
  media TEXT NULL,
  link VARCHAR(700) NULL,
  position INT UNSIGNED NOT NULL DEFAULT 0,
  source VARCHAR(20) NOT NULL DEFAULT 'web',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  UNIQUE (uuid),
  FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
  FOREIGN KEY (stage_id) REFERENCES idea_stages(id) ON DELETE SET NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS idea_labels (
  idea_id BIGINT UNSIGNED NOT NULL,
  label_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (idea_id, label_id),
  FOREIGN KEY (idea_id) REFERENCES ideas(id) ON DELETE CASCADE,
  FOREIGN KEY (label_id) REFERENCES labels(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rss_feeds (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid CHAR(36) NOT NULL,
  workspace_id BIGINT UNSIGNED NOT NULL,
  url VARCHAR(700) NOT NULL,
  title VARCHAR(200) NULL,
  format VARCHAR(10) NULL,
  etag VARCHAR(200) NULL,
  last_modified VARCHAR(100) NULL,
  last_fetched_at DATETIME NULL,
  next_fetch_at DATETIME NULL,
  last_error VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (uuid),
  FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rss_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid CHAR(36) NOT NULL,
  feed_id BIGINT UNSIGNED NOT NULL,
  guid_hash CHAR(64) NOT NULL,
  title VARCHAR(300) NULL,
  link VARCHAR(700) NULL,
  summary TEXT NULL,
  image_url VARCHAR(700) NULL,
  published_at DATETIME NULL,
  idea_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (uuid),
  UNIQUE (feed_id, guid_hash),
  FOREIGN KEY (feed_id) REFERENCES rss_feeds(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS repurposes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid CHAR(36) NOT NULL,
  workspace_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  source_channel_id BIGINT UNSIGNED NULL,
  source_format VARCHAR(10) NOT NULL DEFAULT 'reel',
  destinations TEXT NOT NULL,
  mode VARCHAR(10) NOT NULL DEFAULT 'draft',
  status VARCHAR(10) NOT NULL DEFAULT 'active',
  paused_reason VARCHAR(40) NULL,
  activated_at DATETIME NULL,
  last_polled_at DATETIME NULL,
  next_poll_at DATETIME NULL,
  last_error VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (uuid),
  FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
  FOREIGN KEY (source_channel_id) REFERENCES channels(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS repurpose_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  repurpose_id BIGINT UNSIGNED NOT NULL,
  source_id VARCHAR(190) NOT NULL,
  status VARCHAR(12) NOT NULL DEFAULT 'pending',
  reason VARCHAR(40) NULL,
  post_id BIGINT UNSIGNED NULL,
  caption TEXT NULL,
  media_url VARCHAR(1500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (repurpose_id, source_id),
  FOREIGN KEY (repurpose_id) REFERENCES repurposes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS post_metrics (
  target_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  likes INT UNSIGNED NULL,
  comments INT UNSIGNED NULL,
  shares INT UNSIGNED NULL,
  views BIGINT UNSIGNED NULL,
  impressions BIGINT UNSIGNED NULL,
  fetched_at DATETIME NOT NULL,
  FOREIGN KEY (target_id) REFERENCES post_targets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_webhook_deliveries_due ON webhook_deliveries (status, next_attempt_at);
CREATE INDEX idx_ideas_board ON ideas (workspace_id, stage_id, position);
CREATE INDEX idx_repurposes_due ON repurposes (status, next_poll_at);
CREATE INDEX idx_rss_feeds_due ON rss_feeds (next_fetch_at);
CREATE INDEX idx_posts_recurrence ON posts (recurrence_parent_id);
CREATE INDEX idx_post_notes_post ON post_notes (post_id);
