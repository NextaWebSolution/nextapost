-- 2.0.1: make room for everything the app accepts. MySQL strict mode refuses (and the save fails) when a value is longer than its column.
-- SQLite doesn't limit text length, so these MODIFY statements are skipped there.
ALTER TABLE workspaces MODIFY ai_model VARCHAR(120) NULL;
ALTER TABLE workspaces MODIFY brand_voice TEXT NULL;
-- Post text: up to 65,000 characters. TEXT holds 65,535 bytes, which Hindi, Bengali or emoji text fills far sooner.
ALTER TABLE posts MODIFY body MEDIUMTEXT NULL;
ALTER TABLE post_targets MODIFY body_override MEDIUMTEXT NULL;
ALTER TABLE ideas MODIFY body MEDIUMTEXT NULL;
ALTER TABLE post_templates MODIFY body MEDIUMTEXT NOT NULL;
