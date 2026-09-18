ALTER TABLE maniforge_migrations
    ADD COLUMN checksum CHAR(64) NULL AFTER version,
    ADD COLUMN dirty TINYINT(1) NOT NULL DEFAULT 0 AFTER checksum,
    ADD COLUMN error_message TEXT NULL AFTER dirty,
    ADD COLUMN started_at TIMESTAMP NULL DEFAULT NULL AFTER error_message,
    ADD COLUMN finished_at TIMESTAMP NULL DEFAULT NULL AFTER started_at;

CREATE TABLE IF NOT EXISTS maniforge_rate_limits (
    bucket_key CHAR(64) PRIMARY KEY,
    window_started_at TIMESTAMP NOT NULL,
    request_count INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_rate_limits_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE maniforge_audit_log
    ADD COLUMN correlation_id CHAR(32) NULL AFTER payload_json,
    ADD COLUMN integrity_hash CHAR(64) NULL AFTER correlation_id,
    ADD INDEX idx_audit_correlation (correlation_id);

ALTER TABLE maniforge_security_events
    ADD COLUMN correlation_id CHAR(32) NULL AFTER payload_json,
    ADD COLUMN integrity_hash CHAR(64) NULL AFTER correlation_id,
    ADD INDEX idx_sec_events_correlation (correlation_id);
