<?php
declare(strict_types=1);

function recommendation_repair_snapshot(PDO $pdo, int $recordId, bool $lock = false): array
{
    $suffix = $lock && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    $stmt = $pdo->prepare('SELECT * FROM records WHERE id = ?' . $suffix);
    $stmt->execute([$recordId]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$record || $record['document_type'] !== 'Committee Referrals') {
        throw new RuntimeException('Committee referral not found.');
    }
    $snapshot = ['record' => $record];
    foreach (['record_committees', 'record_movements'] as $table) {
        $stmt = $pdo->prepare('SELECT * FROM ' . $table . ' WHERE record_id = ? ORDER BY id' . $suffix);
        $stmt->execute([$recordId]);
        $snapshot[$table] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    $stmt = $pdo->prepare("SELECT * FROM audit_logs WHERE entity_type = 'record' AND entity_id = ? ORDER BY id" . $suffix);
    $stmt->execute([$recordId]);
    $snapshot['audit_logs'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return $snapshot;
}

function recommendation_repair_version(array $snapshot): string
{
    return hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));
}

function recommendation_repair_candidates(array $snapshot, array $committees): array
{
    $result = [];
    foreach ($snapshot['record_committees'] as $row) {
        $committeeId = (int) $row['committee_id'];
        if ((int) $row['sequence_no'] <= 0 || $committeeId === (int) $snapshot['record']['committee_id']) {
            continue;
        }
        $name = $committees[$committeeId] ?? null;
        if ($name === null) continue;
        $matchingAudits = array_values(array_filter($snapshot['audit_logs'], static fn ($audit) =>
            $audit['action'] === 'recommended_approval_committee_added'
            && str_starts_with($audit['description'], 'Added recommended committee ' . $name . ' to Communication Number ')
        ));
        $matchingMovements = array_values(array_filter($snapshot['record_movements'], static fn ($movement) =>
            $movement['to_status'] === 'Recommending Approval'
            && preg_match('/(?:^|\R)Recommended Committee: ' . preg_quote($name, '/') . ' - \d{4}-\d{2}-\d{2}(?:\R|$)/', (string) $movement['notes'])
        ));
        if ($matchingAudits && $matchingMovements) {
            $result[$committeeId] = ['name' => $name, 'row' => $row, 'recommendations' => $matchingMovements];
        }
    }
    return $result;
}

function recommendation_automatic_repair_candidates(array $snapshot, array $committees): array
{
    $candidates = recommendation_repair_candidates($snapshot, $committees);
    foreach ($candidates as $id => $candidate) {
        $firstRecommendationId = min(array_column($candidate['recommendations'], 'id'));
        foreach ($snapshot['record_movements'] as $movement) {
            if ((int) $movement['id'] >= $firstRecommendationId) continue;
            $notes = (string) ($movement['notes'] ?? '');
            // Preserve committees explicitly assigned before the recommendation,
            // including older joint entries which recorded only the group size.
            $originalCount = preg_match('/referral to (\d+) committees/i', $notes, $match) ? (int) $match[1] : 0;
            $originalAssignment = preg_match('/(?:^|\R)(?:Assigned )?Committees?:\s*([^\r\n]+)/i', $notes, $match)
                ? $match[1] : '';
            if (preg_match('/Reviewed and assigned to (.+?) under Division Chief /', $notes, $match)) {
                $originalAssignment .= '; ' . $match[1];
            }
            if (preg_match('/referral to \d+ committees: (.+?)(?:\. |\.$|$)/i', $notes, $match)) {
                $originalAssignment .= '; ' . $match[1];
            }
            $wasNamed = preg_match('/(?:^|[;,]\s*)' . preg_quote($candidate['name'], '/') . '(?:\s*[;,]|$)/', trim($originalAssignment, '; ')) === 1;
            if (($originalCount > 0 && (int) $candidate['row']['sequence_no'] <= $originalCount)
                || $wasNamed) {
                unset($candidates[$id]);
                break;
            }
        }
    }
    return $candidates;
}

function repair_recommendation_history(PDO $pdo, int $recordId, array $committeeIds, string $version, ?int $userId, string $backupDirectory): string
{
    $committeeIds = array_values(array_unique(array_map('intval', $committeeIds)));
    if (!$committeeIds) throw new RuntimeException('Select at least one incorrectly added committee.');
    $pdo->beginTransaction();
    try {
        $snapshot = recommendation_repair_snapshot($pdo, $recordId, true);
        if (!hash_equals(recommendation_repair_version($snapshot), $version)) {
            throw new RuntimeException('The record changed since the preview. Reload and review it again.');
        }
        $committees = $pdo->query('SELECT id, name FROM committees ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);
        $candidates = recommendation_repair_candidates($snapshot, $committees);
        foreach ($committeeIds as $committeeId) {
            if (!isset($candidates[$committeeId])) {
                throw new RuntimeException('A selected committee is not an eligible recommendation-only correction.');
            }
        }
        if (!is_dir($backupDirectory) && !@mkdir($backupDirectory, 0700, true) && !is_dir($backupDirectory)) {
            throw new RuntimeException('Cannot create the correction backup directory. Nothing was changed.');
        }
        $filename = 'record-' . $recordId . '-' . date('Ymd-His') . '-' . bin2hex(random_bytes(6)) . '.json';
        $backup = json_encode([
            'purpose' => 'Before recommendation history correction', 'created_at' => date(DATE_ATOM),
            'corrected_by' => $userId, 'route_only_committee_ids' => $committeeIds, 'before' => $snapshot,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $handle = @fopen($backupDirectory . DIRECTORY_SEPARATOR . $filename, 'xb');
        if (!$handle) throw new RuntimeException('Cannot save a correction backup. Nothing was changed.');
        try {
            $written = fwrite($handle, $backup);
            if ($written !== strlen($backup) || !fflush($handle)) {
                throw new RuntimeException('The correction backup could not be completed. Nothing was changed.');
            }
        } finally {
            fclose($handle);
        }
        $update = $pdo->prepare('UPDATE record_committees SET sequence_no = 0 WHERE record_id = ? AND committee_id = ? AND sequence_no > 0');
        foreach ($committeeIds as $committeeId) {
            $update->execute([$recordId, $committeeId]);
            if ($update->rowCount() !== 1) throw new RuntimeException('Committee assignment changed during correction.');
        }
        $description = 'Corrected recommendation-only committees to workflow routes: '
            . implode('; ', array_map(static fn ($id) => $candidates[$id]['name'], $committeeIds))
            . '. Original tracking entries, dates, authors, notes and current status preserved. Backup: ' . $filename;
        $audit = $pdo->prepare("INSERT INTO audit_logs (user_id, action, entity_type, entity_id, description) VALUES (?, 'recommendation_history_repair', 'record', ?, ?)");
        $audit->execute([$userId, $recordId, $description]);
        $pdo->commit();
        return $filename;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

// Run on authenticated page loads. Reclassified rows cease to be candidates,
// so repeated requests neither repeat corrections nor duplicate audit entries.
function automatically_repair_recommendation_history(PDO $pdo, string $backupDirectory): array
{
    $ids = $pdo->query("SELECT DISTINCT r.id FROM records r
        INNER JOIN audit_logs a ON a.entity_type = 'record' AND a.entity_id = r.id
        WHERE r.document_type = 'Committee Referrals' AND a.action = 'recommended_approval_committee_added'
        AND EXISTS (SELECT 1 FROM record_committees rc WHERE rc.record_id = r.id
            AND rc.sequence_no > 0 AND rc.committee_id <> r.committee_id)
        ORDER BY r.id")->fetchAll(PDO::FETCH_COLUMN);
    $committees = $pdo->query('SELECT id, name FROM committees ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);
    $corrected = [];
    foreach ($ids as $recordId) {
        $snapshot = recommendation_repair_snapshot($pdo, (int) $recordId);
        $candidates = recommendation_automatic_repair_candidates($snapshot, $committees);
        if (!$candidates) continue;
        repair_recommendation_history($pdo, (int) $recordId, array_keys($candidates),
            recommendation_repair_version($snapshot), null, $backupDirectory);
        $corrected[] = (int) $recordId;
    }
    return $corrected;
}
