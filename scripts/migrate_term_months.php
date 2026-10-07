<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../app/db.php';
foreach (['start_month' => 'start_year', 'end_month' => 'end_year'] as $column => $after) {
    if (!db()->query("SHOW COLUMNS FROM committee_terms LIKE '$column'")->fetch()) {
        db()->exec("ALTER TABLE committee_terms ADD COLUMN $column TINYINT UNSIGNED NULL AFTER $after");
    }
}
echo "Term month migration applied. Existing months remain unset.\n";
