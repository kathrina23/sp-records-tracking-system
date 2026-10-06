<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/recipient_delivery.php';
require_login();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Use the recipient delivery form.');
}
verify_csrf();
if (!in_array(current_user()['role'] ?? '', ['admin', 'messengerial_support'], true)) {
    http_response_code(403);
    exit('You are not authorized to update deliveries.');
}
ensure_recipient_delivery_schema();
$pdo = db();
$pdo->beginTransaction();
try {
    save_recipient_delivery($pdo, (int) ($_POST['record_id'] ?? 0), (int) ($_POST['recipient_id'] ?? 0), ($_POST['delivered'] ?? '') === '1');
    $pdo->commit();
    flash('Recipient delivery status saved.');
} catch (Throwable $error) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    flash($error instanceof DomainException ? $error->getMessage() : 'Unable to save delivery status. Please try again.', 'error');
}
$tab = ($_POST['tab'] ?? '') === 'forwarded' ? 'forwarded' : 'transmittals';
redirect('/messengerial.php?tab=' . $tab);
