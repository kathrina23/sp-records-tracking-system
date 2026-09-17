<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/transmittal_completion.php';
require_login();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Use the transmittal completion form.');
}
verify_csrf();
if (!in_array(current_user()['role'] ?? '', ['admin', 'administrative_support'], true)) {
    http_response_code(403);
    exit('Only LMIS & Records Staff or the Administrator can complete transmittals.');
}
$id = (int) ($_POST['record_id'] ?? 0);
if (($_POST['all_printed'] ?? '') !== '1') {
    flash('Please confirm that all recipient transmittals have been printed.', 'error');
    redirect('/record_recipients.php?record_id=' . $id);
}
$pdo = db();
$pdo->beginTransaction();
try {
    complete_record_transmittals($pdo, $id, (string) ($_POST['recipient_snapshot'] ?? ''));
    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if (!$error instanceof DomainException) {
        error_log('Transmittal completion failed: ' . $error->getMessage());
    }
    flash($error instanceof DomainException ? $error->getMessage() : 'Unable to complete transmittals. Please try again.', 'error');
    redirect('/record_recipients.php?record_id=' . $id);
}
audit_log('transmittals_completed', 'All recipient transmittals confirmed printed; forwarded to Messengerial Services.', 'record', $id);
flash('Transmittals marked complete. The record has been forwarded to Messengerial Services.');
redirect('/messengerial.php');