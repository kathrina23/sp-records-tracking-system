<?php

require_once __DIR__ . '/../app/auth.php';
require_login();

if (!can_backup_system()) {
    http_response_code(403);
    exit('Your account is not allowed to back up the system.');
}

function sql_value(PDO $pdo, mixed $value): string
{
    if ($value === null) {
        return 'NULL';
    }

    return $pdo->quote((string) $value);
}

function generate_database_backup(): string
{
    $pdo = db();
    $database = DB_NAME;
    $lines = [
        '-- ' . APP_NAME . ' backup',
        '-- Generated: ' . date('Y-m-d H:i:s'),
        'CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '``', $database) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;',
        'USE `' . str_replace('`', '``', $database) . '`;',
        'SET FOREIGN_KEY_CHECKS=0;',
        '',
    ];

    $tables = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM);
    foreach ($tables as $tableRow) {
        $table = $tableRow[0];
        $safeTable = str_replace('`', '``', $table);

        $createStmt = $pdo->query('SHOW CREATE TABLE `' . $safeTable . '`')->fetch(PDO::FETCH_ASSOC);
        $createSql = $createStmt['Create Table'] ?? array_values($createStmt)[1];

        $lines[] = 'DROP TABLE IF EXISTS `' . $safeTable . '`;';
        $lines[] = $createSql . ';';
        $lines[] = '';

        $rows = $pdo->query('SELECT * FROM `' . $safeTable . '`')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $columns = array_map(fn ($column) => '`' . str_replace('`', '``', $column) . '`', array_keys($row));
            $values = array_map(fn ($value) => sql_value($pdo, $value), array_values($row));
            $lines[] = 'INSERT INTO `' . $safeTable . '` (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ');';
        }
        $lines[] = '';
    }

    $lines[] = 'SET FOREIGN_KEY_CHECKS=1;';
    return implode("\n", $lines);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    audit_log('backup_download', role_label((string) current_user()['role']) . ' downloaded a database backup.', 'database', null);

    $filename = 'lcd_records_backup_' . date('Ymd_His') . '.sql';
    header('Content-Type: application/sql');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    echo generate_database_backup();
    exit;
}

require __DIR__ . '/../app/partials/header.php';
?>
<div class="page-head">
    <div>
        <h1>Backup</h1>
        <p class="muted">Download a SQL backup of the system database.</p>
    </div>
</div>

<section class="panel">
    <h2>Database Backup</h2>
    <p class="muted">This creates a downloadable SQL file containing the current tables and data.</p>
    <form method="post" class="actions">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <button class="btn" type="submit">Download Backup</button>
    </form>
</section>
<?php require __DIR__ . '/../app/partials/footer.php'; ?>
