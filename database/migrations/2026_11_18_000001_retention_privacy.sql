-- v1.0.1: storage clean-up (retention) settings per workspace + privacy fields.
ALTER TABLE workspaces ADD COLUMN media_retention_days INT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE workspaces ADD COLUMN post_retention_days INT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE workspaces ADD COLUMN library_retention_days INT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE posts ADD COLUMN media_removed_at DATETIME NULL;
ALTER TABLE users ADD COLUMN deleted_at DATETIME NULL;
