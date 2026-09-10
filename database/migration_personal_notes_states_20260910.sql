-- Run once on existing deployments before uploading the updated Notes files.
ALTER TABLE division_chief_notes
    MODIFY record_id INT NULL,
    ADD COLUMN completed_at DATETIME NULL,
    ADD COLUMN archived_at DATETIME NULL;
