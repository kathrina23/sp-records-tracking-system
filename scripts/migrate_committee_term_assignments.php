<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit; }
require_once __DIR__ . '/../app/db.php';
db()->exec(file_get_contents(__DIR__ . '/../database/migration_committee_term_assignments.sql'));
echo "Committee term assignments migrated. Existing roster terms retained.\n";
