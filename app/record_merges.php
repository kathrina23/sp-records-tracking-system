<?php
declare(strict_types=1);

const MERGE_MOVED_TABLES = ['record_movements', 'record_attachments', 'division_chief_notes', 'record_recipients', 'committee_report_numbers'];

function ensure_record_merge_schema(): void
{
    db()->exec(file_get_contents(__DIR__ . '/../database/migration_record_merges.sql'));
}

function active_record_merges(int $recordId): array
{
    $stmt = db()->prepare('SELECT * FROM record_merges WHERE target_record_id = ? AND restored_at IS NULL ORDER BY id DESC');
    $stmt->execute([$recordId]);
    return $stmt->fetchAll();
}

function has_legacy_record_merge(array $record): bool
{
    // Older merges kept a description but no attachment-to-source mapping.
    $stmt = db()->prepare("SELECT COUNT(*) FROM record_movements WHERE record_id = ? AND notes LIKE '%Merged record:%'");
    $stmt->execute([(int) $record['id']]);
    $count = (int) $stmt->fetchColumn();
    $tracked = db()->prepare('SELECT COUNT(*) FROM record_merges WHERE target_record_id = ?');
    $tracked->execute([(int) $record['id']]);
    return $count > (int) $tracked->fetchColumn();
}

function merged_attachment_ids(array $record): array
{
    $ids = [];
    foreach (active_record_merges((int) $record['id']) as $merge) {
        $snapshot = json_decode($merge['source_snapshot'], true, 512, JSON_THROW_ON_ERROR);
        $ids = array_merge($ids, array_column($snapshot['related']['record_attachments'], 'id'));
    }
    if (has_legacy_record_merge($record)) {
        $stmt = db()->prepare('SELECT id FROM record_attachments WHERE record_id = ?');
        $stmt->execute([(int) $record['id']]);
        $ids = array_merge($ids, $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
    return array_values(array_unique(array_map('intval', $ids)));
}

function capture_record_merge(int $sourceId, int $targetId, array $edits): void
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM records WHERE id IN (?, ?) ORDER BY id FOR UPDATE');
    $stmt->execute([$sourceId, $targetId]);
    $records = $stmt->fetchAll(PDO::FETCH_UNIQUE);
    if ($sourceId === $targetId || !isset($records[$sourceId], $records[$targetId])) {
        throw new RuntimeException('The source or destination record is no longer available.');
    }
    if (active_record_merges($sourceId) || has_legacy_record_merge(['id' => $sourceId])) {
        throw new RuntimeException('Separate this record’s existing merges before merging it into another record.');
    }
    $source = $records[$sourceId];
    foreach (['title', 'origin', 'client_name', 'contact_number', 'client_email', 'received_date', 'remarks'] as $field) {
        if (array_key_exists($field, $edits)) $source[$field] = $edits[$field];
    }
    $related = [];
    foreach (array_merge(MERGE_MOVED_TABLES, ['record_committees', 'record_division_receipts']) as $table) {
        $stmt = $pdo->prepare('SELECT * FROM ' . $table . ' WHERE record_id = ? FOR UPDATE');
        $stmt->execute([$sourceId]);
        $related[$table] = $stmt->fetchAll();
    }
    $stmt = $pdo->prepare('INSERT INTO record_merges (target_record_id, source_snapshot, merged_by) VALUES (?, ?, ?)');
    $stmt->execute([$targetId, json_encode(['record' => $source, 'related' => $related], JSON_THROW_ON_ERROR), current_user()['id']]);
}

function insert_separated_record(array $row): int
{
    unset($row['id']);
    $columns = array_keys($row);
    $stmt = db()->prepare('INSERT INTO records (`' . implode('`, `', $columns) . '`) VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ')');
    $stmt->execute(array_values($row));
    return (int) db()->lastInsertId();
}

// Caller owns the transaction and communication-number lock.
function restore_record_merge(int $mergeId, int $targetId, string $title): int
{
    if (!can_city_secretary_action()) throw new RuntimeException('You cannot unmerge records.');
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM record_merges WHERE id = ? AND target_record_id = ? AND restored_at IS NULL FOR UPDATE');
    $stmt->execute([$mergeId, $targetId]);
    $merge = $stmt->fetch();
    if (!$merge) throw new RuntimeException('This merge has already been restored or is unavailable.');
    $snapshot = json_decode($merge['source_snapshot'], true, 512, JSON_THROW_ON_ERROR);
    $row = $snapshot['record'];
    $originalNumber = $row['control_number'];
    if (control_number_exists($originalNumber)) $row['control_number'] = next_control_number($row['document_type']);
    $row['title'] = $title;
    $row['updated_by'] = current_user()['id'];
    $row['updated_at'] = date('Y-m-d H:i:s');
    $row['remarks'] = trim(preg_replace('/(^|\R)Tagged as update to existing Communication Number\s+[A-Z]-\d{5}-\d{4}\.\s*Pending SP Secretary review\.\s*/i', "\n", (string) $row['remarks']));
    $newId = insert_separated_record($row);
    foreach (MERGE_MOVED_TABLES as $table) {
        $move = $pdo->prepare('UPDATE ' . $table . ' SET record_id = ? WHERE id = ? AND record_id = ?');
        foreach ($snapshot['related'][$table] as $related) {
            // Keep subsequent edits; do not resurrect deleted or independently separated items.
            $move->execute([$newId, $related['id'], $targetId]);
        }
    }
    foreach (['record_committees', 'record_division_receipts'] as $table) {
        foreach ($snapshot['related'][$table] as $related) {
            unset($related['id']);
            $related['record_id'] = $newId;
            $stmt = $pdo->prepare('INSERT INTO ' . $table . ' (`' . implode('`, `', array_keys($related)) . '`) VALUES (' . implode(',', array_fill(0, count($related), '?')) . ')');
            $stmt->execute(array_values($related));
        }
    }
    $stmt = $pdo->prepare('UPDATE record_merges SET restored_record_id = ?, restored_at = NOW() WHERE id = ?');
    $stmt->execute([$newId, $mergeId]);
    record_separation_history($targetId, $newId, 'Unmerged ' . $originalNumber . ' as ' . $row['control_number'] . '.');
    return $newId;
}

function record_separation_history(int $sourceId, int $newId, string $notes): void
{
    $stmt = db()->prepare('INSERT INTO record_movements (record_id, from_status, to_status, from_location, to_location, notes, record_title, updated_by)
        SELECT id, status, status, current_location, current_location, ?, title, ? FROM records WHERE id IN (?, ?)');
    $stmt->execute([$notes, current_user()['id'], $sourceId, $newId]);
}

function separate_merged_attachments(array $record, array $ids, string $title): int
{
    if (!can_city_secretary_action()) throw new RuntimeException('You cannot unmerge attachments.');
    $ids = array_values(array_unique(array_map('intval', $ids)));
    $allowed = merged_attachment_ids($record);
    if (!$ids || array_diff($ids, $allowed)) throw new RuntimeException('Select attachments belonging to this merged record.');
    $fields = ['document_type', 'origin', 'client_name', 'contact_number', 'client_email', 'received_date'];
    $row = array_intersect_key($record, array_flip($fields));
    $row += ['title' => $title, 'control_number' => next_control_number($record['document_type']),
        'status' => $record['document_type'] === 'Certified Urgent' ? 'For Plenary Session' : 'Received',
        'current_location' => $record['document_type'] === 'Certified Urgent' ? 'City Secretary - For Plenary' : 'Committee Assignment',
        'created_by' => current_user()['id'], 'updated_by' => current_user()['id'],
        'remarks' => 'Attachments separated from ' . $record['control_number'] . '. Review the record details.'];
    $newId = insert_separated_record($row);
    $stmt = db()->prepare('UPDATE record_attachments SET record_id = ? WHERE id = ? AND record_id = ?');
    foreach ($ids as $attachmentId) {
        $stmt->execute([$newId, $attachmentId, $record['id']]);
        if ($stmt->rowCount() !== 1) throw new RuntimeException('An attachment has changed. Reload and try again.');
    }
    record_separation_history((int) $record['id'], $newId, 'Separated attachments into new record ' . $row['control_number'] . '.');
    return $newId;
}
