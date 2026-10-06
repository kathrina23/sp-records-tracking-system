<?php
require __DIR__ . '/../app/auth.php';
require __DIR__ . '/../app/recipient_delivery.php';
function check_delivery(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
}
foreach (['admin', 'messengerial_support', 'administrative_support', 'records_officer', 'secretariat'] as $role) {
    $_SESSION['user'] = ['id' => 999999, 'role' => $role];
    foreach (['For Transmittal', 'Forwarded to the Messengerial Services', 'Received', 'Completed'] as $status) {
        check_delivery(can_mark_recipient_delivery(['status' => $status]) ===
            (in_array($role, ['admin', 'messengerial_support'], true) && in_array($status, ['For Transmittal', 'Forwarded to the Messengerial Services'], true)), 'Delivery role/status permission');
    }
}
$_SESSION['user'] = ['id' => 999999, 'role' => 'messengerial_support'];
check_delivery(can_view_record(['status' => 'For Transmittal']), 'Messenger can view transmittal record');
$pdo = db();
// Temporary tables shadow official data only on this connection.
foreach (['records', 'record_recipients', 'audit_logs'] as $table) {
    $pdo->exec("CREATE TEMPORARY TABLE delivery_test_$table LIKE $table");
    $pdo->exec("CREATE TEMPORARY TABLE $table LIKE delivery_test_$table");
}
ensure_recipient_delivery_schema();
$pdo->beginTransaction();
try {
    $pdo->exec("INSERT INTO records (id, control_number, title, document_type, origin, status, received_date) VALUES (1, 'TEST', 'Delivery test', 'Committee Referrals', 'Test', 'For Transmittal', CURRENT_DATE)");
    $pdo->exec("INSERT INTO record_recipients (id, record_id, name) VALUES (1, 1, 'First'), (2, 1, 'Second')");
    save_recipient_delivery($pdo, 1, 1, true);
    $saved = $pdo->query('SELECT * FROM record_recipients WHERE id = 1')->fetch();
    check_delivery(!empty($saved['delivered_at']) && (int) $saved['delivered_by'] === 999999, 'Delivery timestamp and actor saved');
    check_delivery(!$pdo->query('SELECT delivered_at FROM record_recipients WHERE id = 2')->fetchColumn(), 'Other recipient unchanged');
    save_recipient_delivery($pdo, 1, 1, true);
    check_delivery((int) $pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn() === 1, 'Repeated confirmation is idempotent');
    try { save_recipient_delivery($pdo, 2, 1, true); throw new RuntimeException('Wrong record accepted'); }
    catch (DomainException $expected) {}
    save_recipient_delivery($pdo, 1, 1, false);
    check_delivery(!$pdo->query('SELECT delivered_at FROM record_recipients WHERE id = 1')->fetchColumn(), 'Delivery correction saved');
    $pdo->exec("UPDATE records SET status = 'Completed' WHERE id = 1");
    try { save_recipient_delivery($pdo, 1, 1, true); throw new RuntimeException('Closed record accepted'); }
    catch (DomainException $expected) {}
    echo "PASS: delivery permissions, recipient isolation, timestamp, actor, idempotency, correction, and stage validation.\n";
} finally {
    $pdo->rollBack();
}
