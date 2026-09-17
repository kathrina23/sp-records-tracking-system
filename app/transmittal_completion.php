<?php
declare(strict_types=1);

// The caller owns the transaction so the status and history commit together.
function complete_record_transmittals(PDO $pdo, int $recordId, string $recipientSnapshot): void
{
    if (!$pdo->inTransaction()) {
        throw new LogicException('Transmittal completion requires a transaction.');
    }
    $stmt = $pdo->prepare('SELECT * FROM records WHERE id = ? FOR UPDATE');
    $stmt->execute([$recordId]);
    $record = $stmt->fetch();
    if (!$record || !can_complete_transmittals($record)) {
        throw new DomainException('This record is no longer waiting for transmittal completion, or you are not authorized to complete it.');
    }
    $stmt = $pdo->prepare('SELECT id FROM record_recipients WHERE record_id = ? ORDER BY id FOR UPDATE');
    $stmt->execute([$recordId]);
    $recipientIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (!$recipientIds) {
        throw new DomainException('Save and print at least one recipient transmittal before completing this step.');
    }
    if (!hash_equals(hash('sha256', implode(',', $recipientIds)), $recipientSnapshot)) {
        throw new DomainException('The recipient list changed. Review and print all current transmittals before completing this step.');
    }
    $newStatus = 'Forwarded to the Messengerial Services';
    $location = 'Messengerial Services';
    $actor = (int) current_user()['id'];
    $update = $pdo->prepare('UPDATE records SET status = ?, current_location = ?, updated_by = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
    $update->execute([$newStatus, $location, $actor, $recordId]);
    $movement = $pdo->prepare('INSERT INTO record_movements (record_id, from_status, to_status, from_location, to_location, notes, record_title, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $movement->execute([
        $recordId, $record['status'], $newStatus, $record['current_location'], $location,
        'All transmittals for ' . count($recipientIds) . ' recipients confirmed printed. Transmittal preparation marked complete and forwarded to Messengerial Services.',
        $record['title'], $actor,
    ]);
}