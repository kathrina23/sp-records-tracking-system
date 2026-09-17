<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/transmittal_completion.php';
function current_user(): ?array { return $_SESSION['user'] ?? null; }
function check(bool $result, string $message): void {
    if (!$result) { throw new RuntimeException($message); }
}
$record = ['status' => 'For Transmittal', 'document_type' => 'Committee Referrals', 'approved_resolution_number' => 'TEST'];
foreach (['admin', 'administrative_support', 'records_officer', 'messengerial_support', 'city_secretary', 'receiving_clerk', 'secretariat', 'division_staff', 'others', 'server_maintenance_staff'] as $role) {
    $_SESSION['user'] = ['id' => 999999, 'role' => $role];
    check(can_view_messengerial() === in_array($role, ['admin', 'administrative_support', 'records_officer', 'messengerial_support'], true), 'Queue access: ' . $role);
    check(can_complete_transmittals($record) === in_array($role, ['admin', 'administrative_support'], true), 'Completion access: ' . $role);
    if ($role === 'messengerial_support') {
        check(!can_create_records() && !can_edit_record($record) && !can_update_record_status($record) && !can_manage_users(), 'Messenger must not gain editing permissions');
    }
}
$_SESSION['user'] = ['id' => 999999, 'role' => 'administrative_support'];
foreach (['Received', 'Approved in the Plenary', 'Forwarded to the Messengerial Services', 'Completed'] as $status) {
    check(!can_complete_transmittals(array_replace($record, ['status' => $status])), 'Reject stage: ' . $status);
}
check(!can_complete_transmittals(array_replace($record, ['approved_resolution_number' => ''])), 'Require plenary approval');
echo "PASS: role, stage, and approval permissions\n";
if (!in_array('--database', $argv, true)) { exit; }
require_once __DIR__ . '/../app/db.php';
$pdo = db();
// Connection-local temporary copies: tests cannot alter official records.
foreach (['records', 'record_recipients', 'record_movements'] as $table) {
    $pdo->exec("CREATE TEMPORARY TABLE messengerial_test_$table LIKE $table");
    $pdo->exec("CREATE TEMPORARY TABLE $table LIKE messengerial_test_$table");
}
$pdo->beginTransaction();
try {
    $pdo->exec("INSERT INTO records (id, control_number, title, document_type, origin, status, received_date, approved_resolution_number) VALUES (1, 'TEST-MESSENGERIAL', 'Test record', 'Committee Referrals', 'Test', 'For Transmittal', CURRENT_DATE, 'TEST')");
    $expectRejected = static function (string $snapshot) use ($pdo): void {
        try { complete_record_transmittals($pdo, 1, $snapshot); }
        catch (DomainException $error) { return; }
        throw new RuntimeException('Expected rejected completion');
    };
    $expectRejected(hash('sha256', ''));
    $pdo->exec("INSERT INTO record_recipients (id, record_id, name, position, address) VALUES (1, 1, 'Test recipient', 'Test position', 'Test address')");
    $expectRejected(hash('sha256', '2'));
    $_SESSION['user']['role'] = 'messengerial_support';
    $expectRejected(hash('sha256', '1'));
    $_SESSION['user']['role'] = 'administrative_support';
    complete_record_transmittals($pdo, 1, hash('sha256', '1'));
    $saved = $pdo->query('SELECT * FROM records WHERE id = 1')->fetch();
    check($saved['status'] === 'Forwarded to the Messengerial Services', 'Forwarded status');
    check($saved['current_location'] === 'Messengerial Services', 'Forwarded location');
    check((int) $saved['updated_by'] === 999999, 'Actor recorded');
    $movement = $pdo->query('SELECT * FROM record_movements WHERE record_id = 1')->fetch();
    check($movement['from_status'] === 'For Transmittal' && $movement['to_status'] === $saved['status'], 'Movement status history');
    check(str_contains($movement['notes'], '1 recipients'), 'Recipient completion recorded');
    $expectRejected(hash('sha256', '1'));
    check((int) $pdo->query('SELECT COUNT(*) FROM record_movements')->fetchColumn() === 1, 'No duplicate handoff');
    $pdo->rollBack();
    check((int) $pdo->query('SELECT COUNT(*) FROM records')->fetchColumn() === 0, 'Rollback is atomic');
    echo "PASS: isolated database handoff, recipient validation, history, duplicate protection, and rollback\n";
} finally {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
}