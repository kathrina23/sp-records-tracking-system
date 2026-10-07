<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit; }
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/helpers.php';
$pdo = db();
// Connection-local copies preserve official tables and records.
foreach (['users', 'records', 'record_movements', 'record_recipients', 'record_division_receipts',
    'record_attachments', 'committees', 'committee_report_numbers', 'division_chief_notes'] as $table) {
    $pdo->exec("CREATE TEMPORARY TABLE schema_test_$table LIKE $table");
    $pdo->exec("CREATE TEMPORARY TABLE $table LIKE schema_test_$table");
}
function schema_write_counts(PDO $pdo): array {
    $counts = [];
    foreach (['Com_alter_table', 'Com_create_table', 'Com_update'] as $name) {
        $row = $pdo->query('SHOW SESSION STATUS LIKE ' . $pdo->quote($name))->fetch();
        $counts[$name] = (int) $row['Value'];
    }
    return $counts;
}
$before = schema_write_counts($pdo);
ensure_plenary_number_schema();
ensure_committee_reporting_schema();
if (!ensure_division_chief_notes_schema()) {
    throw new RuntimeException('Existing notes schema was not recognized.');
}
ensure_plenary_number_schema();
ensure_committee_reporting_schema();
if (schema_write_counts($pdo) !== $before) {
    throw new RuntimeException('A complete schema still triggers DDL or historical updates.');
}
$pdo->exec('ALTER TABLE records DROP COLUMN plenary_print_title');
if (schema_structure_matches(['records' => ['plenary_print_title']])) {
    throw new RuntimeException('Missing column was accepted.');
}
if (schema_structure_matches(['users' => ['role']], [], ['users' => ['role' => ['unsupported_role']]])) {
    throw new RuntimeException('Missing enum value was accepted.');
}
if (schema_structure_matches(['records' => ['id']], ['records' => ['missing_index']])) {
    throw new RuntimeException('Missing index was accepted.');
}
if (schema_structure_matches(['records' => ['id']], [], [], ['records' => ['id']])) {
    throw new RuntimeException('Incorrect nullability was accepted.');
}
echo "PASS: complete schemas perform no DDL/backfills; repeated checks are skipped; missing columns, enums, indexes and nullability are detected on isolated tables.\n";
