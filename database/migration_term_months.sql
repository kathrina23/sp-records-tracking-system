ALTER TABLE committee_terms
    ADD COLUMN start_month TINYINT UNSIGNED NULL AFTER start_year,
    ADD COLUMN end_month TINYINT UNSIGNED NULL AFTER end_year;
