<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../app/db.php';
foreach (['legislation_drafts', 'legislation_publications'] as $table) {
    if (!db()->query("SHOW COLUMNS FROM $table LIKE 'amendment_ids'")->fetch()) {
        db()->exec("ALTER TABLE $table ADD COLUMN amendment_ids TEXT NULL");
    }
}
echo "Legislation amendment tagging migration applied.\n";
