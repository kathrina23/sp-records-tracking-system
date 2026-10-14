<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/recommendation_history_repair.php';
function repair_check(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
function repair_expect_failure(callable $action, string $message): void {
    try { $action(); } catch (RuntimeException $error) { return; }
    throw new RuntimeException($message);
}
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("CREATE TABLE records (id INTEGER PRIMARY KEY, document_type TEXT, committee_id INTEGER, status TEXT, title TEXT);
CREATE TABLE committees (id INTEGER PRIMARY KEY, name TEXT);
CREATE TABLE record_committees (id INTEGER PRIMARY KEY, record_id INTEGER, committee_id INTEGER, sequence_no INTEGER);
CREATE TABLE record_movements (id INTEGER PRIMARY KEY, record_id INTEGER, to_status TEXT, notes TEXT, created_at TEXT, updated_by INTEGER);
CREATE TABLE audit_logs (id INTEGER PRIMARY KEY, user_id INTEGER, action TEXT, entity_type TEXT, entity_id INTEGER, description TEXT);
INSERT INTO records VALUES (1,'Committee Referrals',1,'Scheduled for Plenary','Original title');
INSERT INTO committees VALUES (1,'Health'),(2,'Finance'),(3,'Laws and Rules'),(4,'Existing route');
INSERT INTO record_committees VALUES (1,1,1,1),(2,1,2,2),(3,1,3,3),(4,1,4,0);
INSERT INTO record_movements VALUES (1,1,'Pending to the Committee','Joint referral to 2 committees: Health, Finance.','2026-08-01',7);
INSERT INTO record_movements VALUES (2,1,'Recommending Approval','Recommended Committee: Laws and Rules - 2026-09-07','2026-09-07',8);
INSERT INTO record_movements VALUES (3,1,'Recommending Approval','Recommended Committee: Health - 2026-09-07','2026-09-07',8);
INSERT INTO audit_logs VALUES (1,8,'recommended_approval_committee_added','record',1,'Added recommended committee Laws and Rules to Communication Number L-00001-2026.');
INSERT INTO audit_logs VALUES (2,8,'recommended_approval_committee_added','record',1,'Added recommended committee Health to Communication Number L-00001-2026.');");
$backupDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'recommendation-repair-test-' . bin2hex(random_bytes(6));
$files = [];
try {
    $before = recommendation_repair_snapshot($pdo, 1);
    $names = $pdo->query('SELECT id,name FROM committees')->fetchAll(PDO::FETCH_KEY_PAIR);
    repair_check(array_keys(recommendation_repair_candidates($before, $names)) === [3], 'Lead, genuine joint member or existing route incorrectly eligible');
    $version = recommendation_repair_version($before);
    repair_expect_failure(fn () => repair_recommendation_history($pdo, 1, [], $version, 9, $backupDirectory), 'Empty selection accepted');
    repair_expect_failure(fn () => repair_recommendation_history($pdo, 1, [1], $version, 9, $backupDirectory), 'Lead removal accepted');
    repair_expect_failure(fn () => repair_recommendation_history($pdo, 1, [2], $version, 9, $backupDirectory), 'Unproven joint member removal accepted');
    repair_expect_failure(fn () => repair_recommendation_history($pdo, 1, [3], 'stale', 9, $backupDirectory), 'Stale preview accepted');
    repair_expect_failure(fn () => repair_recommendation_history($pdo, 1, [3], $version, 9, __FILE__), 'Failed backup accepted');
    repair_check(recommendation_repair_version(recommendation_repair_snapshot($pdo, 1)) === $version, 'Failed correction modified the record');
    $filename = repair_recommendation_history($pdo, 1, [3,3], $version, 9, $backupDirectory);
    $files[] = $backupDirectory . DIRECTORY_SEPARATOR . $filename;
    $after = recommendation_repair_snapshot($pdo, 1);
    repair_check($before['record'] === $after['record'], 'Record status or details changed');
    repair_check($before['record_movements'] === $after['record_movements'], 'History dates, authors or notes changed');
    repair_check(array_column($after['record_committees'], 'sequence_no') === [1,2,0,0], 'Genuine joint assignment or route changed');
    repair_check(count($after['audit_logs']) === count($before['audit_logs']) + 1, 'Correction audit missing');
    $backup = json_decode(file_get_contents($files[0]), true, 512, JSON_THROW_ON_ERROR);
    repair_check($backup['before'] === $before, 'Backup does not preserve complete prior state');
    repair_check(recommendation_repair_candidates($after, $names) === [], 'Corrected record remains a candidate');
    repair_expect_failure(fn () => repair_recommendation_history($pdo, 1, [3], recommendation_repair_version($after), 9, $backupDirectory), 'Repeat correction accepted');
    // Failure after the assignment update must roll it back as well.
    $pdo->exec('UPDATE record_committees SET sequence_no=3 WHERE committee_id=3');
    $pdo->exec("CREATE TRIGGER reject_repair_audit BEFORE INSERT ON audit_logs BEGIN SELECT RAISE(ABORT, 'audit unavailable'); END;");
    $rollbackBefore = recommendation_repair_snapshot($pdo, 1);
    repair_expect_failure(fn () => repair_recommendation_history($pdo, 1, [3], recommendation_repair_version($rollbackBefore), 9, $backupDirectory), 'Audit failure accepted');
    repair_check(recommendation_repair_snapshot($pdo, 1) === $rollbackBefore, 'Audit failure did not roll back correction');
    $pdo->exec('DROP TRIGGER reject_repair_audit');
    $pdo->exec("INSERT INTO record_movements VALUES (4,1,'Recommending Approval','Recommended Committee: Finance - 2026-09-07','2026-09-07',8);");
    $pdo->exec("INSERT INTO audit_logs(user_id,action,entity_type,entity_id,description) VALUES (8,'recommended_approval_committee_added','record',1,'Added recommended committee Finance to Communication Number L-00001-2026.');");
    $autoBefore = recommendation_repair_snapshot($pdo, 1);
    repair_check(array_keys(recommendation_automatic_repair_candidates($autoBefore, $names)) === [3], 'Automatic repair would change the genuine original joint assignment');
    repair_check(automatically_repair_recommendation_history($pdo, $backupDirectory) === [1], 'Automatic repair did not correct the affected record');
    $autoAfter = recommendation_repair_snapshot($pdo, 1);
    repair_check($autoAfter['record_movements'] === $autoBefore['record_movements'] && $autoAfter['record'] === $autoBefore['record'], 'Automatic repair changed history or record status');
    repair_check(array_column($autoAfter['record_committees'], 'sequence_no') === [1,2,0,0], 'Automatic repair changed genuine reviewed members');
    repair_check(automatically_repair_recommendation_history($pdo, $backupDirectory) === [], 'Automatic repair repeated an already corrected record');
    repair_check(recommendation_repair_snapshot($pdo, 1) === $autoAfter, 'Repeated page load duplicated corrections');
    echo "PASS: Confirmed corrections, genuine joint preservation, history preservation, backups, stale previews, forged selections, repeat prevention and transactional rollback.\n";
} finally {
    foreach (glob($backupDirectory . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) unlink($file);
    if (is_dir($backupDirectory)) @rmdir($backupDirectory);
}

if (!in_array('--http', $argv, true)) exit;
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/helpers.php';
function repair_http_request(string $path, ?array $data = null): array {
    global $repairCookie;
    $curl = curl_init('http://127.0.0.1:8000' . $path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $repairCookie,
        CURLOPT_COOKIEJAR => $repairCookie, CURLOPT_TIMEOUT => 20]);
    if ($data !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($data));
    $body = curl_exec($curl);
    if ($body === false) throw new RuntimeException(curl_error($curl));
    $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    return [$status, $body];
}
function repair_http_token(string $html): string {
    repair_check((bool) preg_match('/name="csrf_token" value="([^"]+)"/', $html, $match), 'Missing CSRF token');
    return $match[1];
}
$pdo = db();
$repairCookie = tempnam(sys_get_temp_dir(), 'repair-http-');
$tag = 'HISTORY-REPAIR-TEST-' . bin2hex(random_bytes(6));
$recordId = $userId = 0;
$savedBackup = '';
try {
    $committees = current_term_committees();
    repair_check(count($committees) >= 3, 'Need three committees for repair test');
    $originalIds = array_map('intval', array_column(array_slice($committees, 0, 2), 'id'));
    $routeId = (int) $committees[2]['id'];
    $routeName = $committees[2]['name'];
    $pdo->prepare("INSERT INTO users(name,email,password_hash,role) VALUES (?,?,?,'admin')")
        ->execute([$tag, $tag . '@example.invalid', password_hash($tag, PASSWORD_DEFAULT)]);
    $userId = (int) $pdo->lastInsertId();
    [$status, $body] = repair_http_request('/login.php');
    repair_check($status === 200, 'Local server unavailable');
    [$status] = repair_http_request('/login.php', ['csrf_token' => repair_http_token($body), 'email' => $tag . '@example.invalid', 'password' => $tag]);
    repair_check($status === 302, 'Test login failed');
    $pdo->prepare("INSERT INTO records(control_number,title,document_type,origin,committee_id,status,received_date,current_location,created_by) VALUES (?,?,'Committee Referrals','Test',?,'Scheduled for Plenary','2026-10-12','Test',?)")
        ->execute([$tag, $tag, $originalIds[0], $userId]);
    $recordId = (int) $pdo->lastInsertId();
    save_record_committees($recordId, [...$originalIds, $routeId]);
    $pdo->prepare("INSERT INTO record_movements(record_id,from_status,to_status,to_location,notes,updated_by) VALUES (?,'Received','Pending to the Committee','Test',?,?)")
        ->execute([$recordId, 'Joint referral to 2 committees: ' . implode(', ', array_column(array_slice($committees, 0, 2), 'name')) . '.', $userId]);
    $pdo->prepare("INSERT INTO record_movements(record_id,from_status,to_status,to_location,notes,updated_by) VALUES (?,'Others','Recommending Approval','Test',?,?)")
        ->execute([$recordId, 'Recommended Committee: ' . $routeName . ' - 2026-10-12', $userId]);
    $pdo->prepare("INSERT INTO audit_logs(user_id,action,entity_type,entity_id,description) VALUES (?,'recommended_approval_committee_added','record',?,?)")
        ->execute([$userId, $recordId, 'Added recommended committee ' . $routeName . ' to Communication Number ' . $tag . '.']);
    $before = recommendation_repair_snapshot($pdo, $recordId);
    [$status, $body] = repair_http_request('/recommendation_history_repair.php?id=' . $recordId);
    repair_check($status === 200 && str_contains($body, 'Recommendation History Corrections'), 'Automatic correction report unavailable');
    $after = recommendation_repair_snapshot($pdo, $recordId);
    repair_check(array_map('intval', array_column(record_committee_rows($recordId), 'committee_id')) === $originalIds, 'Genuine joint assignment changed');
    repair_check($after['record_movements'] === $before['record_movements'] && $after['record'] === $before['record'], 'Original history or status changed');
    $audit = end($after['audit_logs']);
    preg_match('/Backup: (record-[\w.-]+\.json)$/', $audit['description'], $match);
    repair_check(isset($match[1]), 'Backup reference missing');
    $savedBackup = __DIR__ . '/../storage/recommendation-history-backups/' . $match[1];
    repair_check(is_file($savedBackup), 'Correction backup missing');
    [$status, $body] = repair_http_request('/record_view.php?id=' . $recordId);
    repair_check($status === 200 && str_contains($body, 'Joint (2 committees)') && !str_contains($body, 'Tripartite (3 committees)'), 'Referral group display not repaired');
    repair_check(str_contains($body, 'Recommending Approval: Recommended to the Committee on ' . e($routeName)), 'Recommendation history lost');
    $originalNames = implode(', ', array_column(array_slice($committees, 0, 2), 'name'));
    repair_check(str_contains($body, e('Submitted to the Committee: ' . $originalNames) . '</p>'), 'Original submission history still includes the recommendation recipient');
    // The history must retain the recorded assignment even after the current
    // assignment is cleared by a subsequent edit.
    $pdo->prepare('UPDATE records SET committee_id=NULL WHERE id=?')->execute([$recordId]);
    $pdo->prepare('DELETE FROM record_committees WHERE record_id=?')->execute([$recordId]);
    [$status, $clearedHistory] = repair_http_request('/record_view.php?id=' . $recordId);
    repair_check($status === 200 && str_contains($clearedHistory, e('Submitted to the Committee: ' . $originalNames) . '</p>'), 'Cleared current assignment destroyed historical committee display');
    repair_check(!str_contains($clearedHistory, 'Submitted to the Committee: Unassigned'), 'Historical committee mislabeled Unassigned');
    // Restore fixture state for subsequent idempotence checks.
    $pdo->prepare('UPDATE records SET committee_id=? WHERE id=?')->execute([$originalIds[0], $recordId]);
    foreach ($after['record_committees'] as $row) {
        $pdo->prepare('INSERT INTO record_committees (id,record_id,committee_id,sequence_no,created_at) VALUES (?,?,?,?,?)')
            ->execute([$row['id'], $row['record_id'], $row['committee_id'], $row['sequence_no'], $row['created_at']]);
    }
    // Restoring a fixture updates its record timestamp; refresh the comparison.
    $after = recommendation_repair_snapshot($pdo, $recordId);
    [$status, $body] = repair_http_request('/recommendation_history_repair.php');
    repair_check($status === 200 && str_contains($body, e($tag)), 'Corrected record missing from report');
    [$status, $body] = repair_http_request('/recommendation_history_repair.php?id=' . $recordId);
    repair_check($status === 200 && recommendation_repair_snapshot($pdo, $recordId) === $after, 'Repeated page load changed the corrected record');
    repair_http_request('/logout.php');
    $pdo->prepare("UPDATE users SET role='secretariat' WHERE id=?")->execute([$userId]);
    [$status, $body] = repair_http_request('/login.php');
    repair_http_request('/login.php', ['csrf_token' => repair_http_token($body), 'email' => $tag . '@example.invalid', 'password' => $tag]);
    [$status] = repair_http_request('/recommendation_history_repair.php?id=' . $recordId);
    repair_check($status === 403, 'Unauthorized role can access repair');
    echo "PASS: Automatic correction on page load, Joint display, submission history, preserved original entries, backup, idempotence and report role restriction.\n";
} finally {
    if ($recordId) {
        $pdo->prepare("DELETE FROM audit_logs WHERE entity_type='record' AND entity_id=?")->execute([$recordId]);
        $pdo->prepare('DELETE FROM records WHERE id=? AND created_by=?')->execute([$recordId, $userId]);
    }
    if ($userId) {
        $pdo->prepare('DELETE FROM audit_logs WHERE user_id=?')->execute([$userId]);
        $pdo->prepare('DELETE FROM users WHERE id=? AND email=?')->execute([$userId, $tag . '@example.invalid']);
    }
    if ($savedBackup !== '' && is_file($savedBackup)) unlink($savedBackup);
    if (is_file($repairCookie)) {
        $data = file_get_contents($repairCookie);
        if (preg_match('/\tPHPSESSID\t([a-zA-Z0-9,-]+)(?:\R|$)/', $data, $match)) {
            $session = __DIR__ . '/../storage/sessions/sess_' . $match[1];
            if (is_file($session)) unlink($session);
        }
        unlink($repairCookie);
    }
    echo "CLEANUP: Test records, user, audits and backup removed.\n";
}
