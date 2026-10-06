<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit; }
require_once __DIR__ . '/../app/db.php';
$pdo = db();
$column = $pdo->query("SHOW COLUMNS FROM records LIKE 'status'")->fetch();
$enum = $column['Type'];
foreach (['Published', 'For Publication'] as $status) {
    if (!str_contains($enum, "'" . $status . "'")) {
        $enum = str_replace("'Approved in the Plenary'", "'Approved in the Plenary','" . $status . "'", $enum);
    }
}
if ($enum !== $column['Type']) {
    $pdo->exec("ALTER TABLE records MODIFY status $enum NOT NULL DEFAULT 'Received'");
}
if (!$pdo->query("SHOW COLUMNS FROM records LIKE 'published_on'")->fetch()) {
    $pdo->exec('ALTER TABLE records ADD COLUMN published_on VARCHAR(255) NULL AFTER plenary_approved_date');
}
echo "Publication statuses and Date Published field are ready.\n";
