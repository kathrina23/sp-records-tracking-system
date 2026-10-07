<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
require_once __DIR__ . '/../app/db.php';
$sql = file_get_contents(__DIR__ . '/../database/migration_elibrary.sql');
foreach (explode(';', $sql) as $statement) {
    if (trim($statement) !== '') {
        db()->exec($statement);
    }
}
echo "E-Library migration applied.\n";
