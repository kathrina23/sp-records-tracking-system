<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../app/db.php';
foreach (['legislation_drafts', 'legislation_publications'] as $table) {
    db()->exec("ALTER TABLE $table MODIFY record_id INT NULL");
    foreach (['term_id' => 'INT NULL', 'committee_names' => 'TEXT NULL', 'committee_ids' => 'TEXT NULL'] as $column => $definition) {
        if (!db()->query("SHOW COLUMNS FROM $table LIKE '$column'")->fetch()) {
            db()->exec("ALTER TABLE $table ADD COLUMN $column $definition");
        }
    }
    if (!db()->query("SHOW INDEX FROM $table WHERE Key_name='historical_number_term'")->fetch()) {
        db()->exec("ALTER TABLE $table ADD UNIQUE KEY historical_number_term (term_id, kind, number)");
    }
}
if (!db()->query("SHOW COLUMNS FROM legislation_publications LIKE 'source_draft_id'")->fetch()) {
    db()->exec('ALTER TABLE legislation_publications ADD COLUMN source_draft_id INT NULL, ADD UNIQUE KEY publication_source_draft (source_draft_id)');
}
echo "Historical legislation migration applied.\n";
