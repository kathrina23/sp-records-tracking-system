CREATE TABLE IF NOT EXISTS committee_term_assignments (
    committee_id INT NOT NULL,
    term_id INT NOT NULL,
    PRIMARY KEY (committee_id, term_id),
    CONSTRAINT fk_cta_committee FOREIGN KEY (committee_id) REFERENCES committees(id) ON DELETE CASCADE,
    CONSTRAINT fk_cta_term FOREIGN KEY (term_id) REFERENCES committee_terms(id) ON DELETE CASCADE
);

INSERT IGNORE INTO committee_term_assignments (committee_id, term_id)
SELECT DISTINCT committee_id, term_id FROM committee_members;
