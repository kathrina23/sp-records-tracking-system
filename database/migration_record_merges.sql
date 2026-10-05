CREATE TABLE IF NOT EXISTS record_merges (
    id INT AUTO_INCREMENT PRIMARY KEY,
    target_record_id INT NOT NULL,
    source_snapshot LONGTEXT NOT NULL,
    merged_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    restored_record_id INT NULL,
    restored_at DATETIME NULL,
    KEY idx_merge_target (target_record_id, restored_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
