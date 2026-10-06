<?php
declare(strict_types=1);

function ensure_recipient_delivery_schema(): void
{
    foreach (['delivered_at' => 'DATETIME NULL', 'delivered_by' => 'INT NULL'] as $column => $type) {
        $check = db()->query("SHOW COLUMNS FROM record_recipients LIKE '$column'");
        if (!$check->fetch()) {
            db()->exec("ALTER TABLE record_recipients ADD COLUMN $column $type");
        }
    }
}

function can_mark_recipient_delivery(array $record): bool
{
    return in_array(current_user()['role'] ?? '', ['admin', 'messengerial_support'], true)
        && in_array($record['status'] ?? '', ['For Transmittal', 'Forwarded to the Messengerial Services'], true);
}

function save_recipient_delivery(PDO $pdo, int $recordId, int $recipientId, bool $delivered): void
{
    if (!$pdo->inTransaction()) { throw new LogicException('Delivery updates require a transaction.'); }
    $recordStmt = $pdo->prepare('SELECT * FROM records WHERE id = ? FOR UPDATE');
    $recordStmt->execute([$recordId]);
    $record = $recordStmt->fetch();
    if (!$record || !can_mark_recipient_delivery($record)) {
        throw new DomainException('This record is not available for delivery updates.');
    }
    $stmt = $pdo->prepare('SELECT * FROM record_recipients WHERE id = ? AND record_id = ? FOR UPDATE');
    $stmt->execute([$recipientId, $recordId]);
    $recipient = $stmt->fetch();
    if (!$recipient) { throw new DomainException('Recipient not found for this record.'); }
    if (!empty($recipient['delivered_at']) === $delivered) { return; }
    $update = $pdo->prepare('UPDATE record_recipients SET delivered_at = ?, delivered_by = ? WHERE id = ? AND record_id = ?');
    $update->execute([$delivered ? date('Y-m-d H:i:s') : null, $delivered ? (int) current_user()['id'] : null, $recipientId, $recordId]);
    audit_log('recipient_delivery', 'Recipient #' . $recipientId . ($delivered ? ' marked delivered.' : ' delivery mark cleared.'), 'record', $recordId);
}
