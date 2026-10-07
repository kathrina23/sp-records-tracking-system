ALTER TABLE legislation_drafts MODIFY record_id INT NULL,
    ADD COLUMN term_id INT NULL, ADD COLUMN committee_names TEXT NULL, ADD COLUMN committee_ids TEXT NULL,
    ADD UNIQUE KEY historical_number_term (term_id, kind, number);
ALTER TABLE legislation_publications MODIFY record_id INT NULL,
    ADD COLUMN term_id INT NULL, ADD COLUMN committee_names TEXT NULL, ADD COLUMN committee_ids TEXT NULL,
    ADD UNIQUE KEY historical_number_term (term_id, kind, number),
    ADD COLUMN source_draft_id INT NULL, ADD UNIQUE KEY publication_source_draft (source_draft_id);
