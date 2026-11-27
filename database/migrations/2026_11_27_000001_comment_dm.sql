-- 2.1.0: Comment-to-DM flows (optional module, off until a super admin turns it on in System → General).
-- A flow watches comments on one Instagram account or Facebook Page; a matching comment gets an optional public reply,
-- then a private DM, an optional follow check, and the link.

CREATE TABLE IF NOT EXISTS dm_flows (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid CHAR(36) NOT NULL,
  workspace_id BIGINT UNSIGNED NOT NULL,
  channel_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  name VARCHAR(120) NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  post_scope VARCHAR(10) NOT NULL DEFAULT 'all',
  media_ids TEXT NULL,
  match_mode VARCHAR(10) NOT NULL DEFAULT 'keywords',
  keywords TEXT NULL,
  exclude_words TEXT NULL,
  public_replies TEXT NULL,
  ask_first TINYINT(1) NOT NULL DEFAULT 1,
  opening_text VARCHAR(1000) NULL,
  opening_button VARCHAR(40) NULL,
  require_follow TINYINT(1) NOT NULL DEFAULT 0,
  follow_text VARCHAR(1000) NULL,
  follow_profile_button VARCHAR(40) NULL,
  follow_confirm_button VARCHAR(40) NULL,
  link_text VARCHAR(1000) NOT NULL,
  link_button VARCHAR(40) NULL,
  link_url VARCHAR(700) NULL,
  once_per_user TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NULL,
  UNIQUE (uuid),
  FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
  FOREIGN KEY (channel_id) REFERENCES channels(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dm_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid CHAR(36) NOT NULL,
  workspace_id BIGINT UNSIGNED NOT NULL,
  flow_id BIGINT UNSIGNED NOT NULL,
  channel_id BIGINT UNSIGNED NOT NULL,
  comment_id VARCHAR(100) NOT NULL,
  media_id VARCHAR(100) NULL,
  commenter_id VARCHAR(100) NOT NULL,
  commenter_name VARCHAR(190) NULL,
  comment_text VARCHAR(1000) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'queued',
  public_replied TINYINT(1) NOT NULL DEFAULT 0,
  dm_sent_at DATETIME NULL,
  follows TINYINT(1) NULL,
  link_sent_at DATETIME NULL,
  clicked_at DATETIME NULL,
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  next_at DATETIME NULL,
  last_error VARCHAR(500) NULL,
  comment_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NULL,
  UNIQUE (uuid),
  UNIQUE (flow_id, comment_id),
  FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
  FOREIGN KEY (flow_id) REFERENCES dm_flows(id) ON DELETE CASCADE,
  FOREIGN KEY (channel_id) REFERENCES channels(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_dm_flows_channel ON dm_flows (channel_id, status);
CREATE INDEX idx_dm_flows_ws ON dm_flows (workspace_id);
CREATE INDEX idx_dm_events_due ON dm_events (status, next_at);
CREATE INDEX idx_dm_events_sent ON dm_events (channel_id, dm_sent_at);
CREATE INDEX idx_dm_events_person ON dm_events (flow_id, commenter_id);
