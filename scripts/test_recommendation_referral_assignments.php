<?php
// Run against the local server. Temporary records and users are removed afterward.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
require __DIR__ . '/../app/db.php';
require __DIR__ . '/../app/helpers.php';
function check_routing(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
function routing_request(string $path, ?array $data = null): array {
    global $cookie;
    $curl = curl_init('http://127.0.0.1:8000' . $path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $cookie,
        CURLOPT_COOKIEJAR => $cookie, CURLOPT_TIMEOUT => 20]);
    if ($data !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($data));
    $body = curl_exec($curl);
    if ($body === false) throw new RuntimeException(curl_error($curl));
    $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    return [$status, $body];
}
function routing_token(string $body): string {
    check_routing((bool) preg_match('/name="csrf_token" value="([^"]+)"/', $body, $match), 'Missing CSRF token');
    return $match[1];
}
$pdo = db();
$tag = 'ROUTING-TEST-' . bin2hex(random_bytes(6));
$cookie = tempnam(sys_get_temp_dir(), 'routing-test-');
$userId = 0;
$recordIds = [];
try {
    $choices = array_values(array_filter(current_term_committees(), fn ($c) => division_chief_for_committee((int) $c['id']) !== null));
    $laws = array_values(array_filter($choices, fn ($c) => strtolower(trim($c['name'])) === 'laws and rules'));
    $original = array_values(array_filter($choices, fn ($c) => strtolower(trim($c['name'])) !== 'laws and rules'));
    check_routing(count($original) >= 4 && count($laws) === 1, 'Need Laws and Rules and four reviewed committee choices');
    $lawsId = (int) $laws[0]['id'];
    $originalIds = array_map('intval', array_column(array_slice($original, 0, 4), 'id'));
    $stmt = $pdo->prepare("INSERT INTO users(name,email,password_hash,role) VALUES(?,?,?,'admin')");
    $stmt->execute([$tag, $tag . '@example.invalid', password_hash($tag, PASSWORD_DEFAULT)]);
    $userId = (int) $pdo->lastInsertId();
    [$status, $body] = routing_request('/login.php');
    check_routing($status === 200, 'Local server unavailable');
    [$status] = routing_request('/login.php', ['csrf_token' => routing_token($body), 'email' => $tag . '@example.invalid', 'password' => $tag]);
    check_routing($status === 302, 'Test login failed');

    foreach ([1, 2, 3, 4] as $count) {
        $reviewed = array_slice($originalIds, 0, $count);
        $stmt = $pdo->prepare("INSERT INTO records(control_number,title,document_type,origin,committee_id,status,received_date,current_location,created_by,updated_by) VALUES(?,?,'Committee Referrals','Test',?,'Others','2026-10-12','Test',?,?)");
        $stmt->execute([$tag . '-' . $count, $tag, $reviewed[0], $userId, $userId]);
        $recordId = (int) $pdo->lastInsertId();
        $recordIds[] = $recordId;
        save_record_committees($recordId, $reviewed);
        $pdo->prepare("INSERT INTO record_movements(record_id,from_status,to_status,notes,updated_by) VALUES(?,'Received','Pending to the Committee','',?)")->execute([$recordId, $userId]);
        [$status, $body] = routing_request('/record_update.php?id=' . $recordId);
        check_routing($status === 200, 'Update form unavailable');
        $token = routing_token($body);
        for ($attempt = 0; $attempt < 2; $attempt++) {
            [$status] = routing_request('/status_update.php', ['csrf_token' => $token, 'record_id' => $recordId,
                'status' => 'Recommending Approval', 'recommended_committee_id' => $lawsId,
                'recommending_approval_date' => '2026-10-12', 'notes' => 'Recommendation test']);
            check_routing($status === 302, 'Recommendation HTTP request failed');
        }
        $saved = array_map('intval', array_column(record_committee_rows($recordId), 'committee_id'));
        check_routing($saved === $reviewed, 'Recommendation changed reviewed assignments');
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM record_committees WHERE record_id=? AND committee_id=? AND sequence_no=0');
        $stmt->execute([$recordId, $lawsId]);
        check_routing((int) $stmt->fetchColumn() === 1, 'Recipient route missing or duplicated');
        save_record_committees($recordId, $reviewed);
        $stmt->execute([$recordId, $lawsId]);
        check_routing((int) $stmt->fetchColumn() === 1, 'Editing reviewed assignments discarded the recipient route');
        [$status, $print] = routing_request('/committee_referral_print.php?id=' . $recordId);
        check_routing($status === 200, 'Referral print failed');
        check_routing(str_contains($print, 'class="referral-group-note"') === ($count > 1), 'Routing changed joint print label');
        if ($count > 1) {
            $labels = [2 => 'Joint', 3 => 'Tripartite', 4 => 'Quadripartite'];
            check_routing(str_contains($print, $labels[$count] . ' Committee 1 of ' . $count), 'Incorrect counted group label on printout');
        }
        [$status, $history] = routing_request('/record_view.php?id=' . $recordId);
        $expected = implode('; ', array_column(array_slice($original, 0, $count), 'name'));
        check_routing($status === 200 && str_contains($history, e('Submitted to the Committee: ' . $expected) . '</p>'), 'Original submission history changed');
        check_routing(str_contains($history, e('Recommending Approval: Recommended to the Committee on Laws and Rules') . '</p>'), 'Destination committee missing from separate recommendation update');
        check_routing(str_contains($history, '<strong>Referral Group</strong>') === ($count > 1), 'Incorrect referral group visibility');
        if ($count > 1) {
            check_routing(str_contains($history, $labels[$count] . ' (' . $count . ' committees)'), 'Incorrect record referral group');
        }
        echo "PASS: $count reviewed committees remain unchanged after repeated Laws and Rules recommendations; print and original tracking history match.\n";

        [$status] = routing_request('/status_update.php', ['csrf_token' => $token, 'record_id' => $recordId,
            'status' => 'Referred Back to Committee', 'referred_back_committee_id' => $lawsId, 'notes' => 'Return routing test']);
        check_routing($status === 302, 'Return route request failed');
        check_routing(array_map('intval', array_column(record_committee_rows($recordId), 'committee_id')) === $reviewed, 'Return routing changed reviewed assignments');
        echo "PASS: referring back to another committee does not create or expand a joint referral.\n";

        $stmt = $pdo->prepare('SELECT user_id FROM committee_secretariats WHERE committee_id=? LIMIT 1');
        $stmt->execute([$lawsId]);
        $lawsUserId = (int) $stmt->fetchColumn();
        check_routing($lawsUserId > 0, 'Need a Laws and Rules Secretariat for recipient access check');
        $_SESSION['user'] = ['id' => $lawsUserId, 'role' => 'secretariat'];
        $record = ['id' => $recordId, 'committee_id' => $reviewed[0], 'status' => 'Recommending Approval', 'document_type' => 'Committee Referrals'];
        check_routing(can_access_record_committees($record) && can_view_record($record) && can_update_record_status($record), 'Routed recipient lost access');
        echo "PASS: Laws and Rules retains view and update access through the workflow route.\n";
    }
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    foreach ($recordIds as $recordId) {
        $pdo->prepare('DELETE FROM audit_logs WHERE entity_type=? AND entity_id=?')->execute(['record', $recordId]);
        $pdo->prepare('DELETE FROM records WHERE id=? AND created_by=?')->execute([$recordId, $userId]);
    }
    if ($userId) {
        $pdo->prepare('DELETE FROM audit_logs WHERE user_id=?')->execute([$userId]);
        $pdo->prepare('DELETE FROM users WHERE id=? AND email=?')->execute([$userId, $tag . '@example.invalid']);
    }
    if (is_file($cookie)) {
        $cookieData = file_get_contents($cookie);
        if (preg_match('/\tPHPSESSID\t([a-zA-Z0-9,-]+)(?:\R|$)/', $cookieData, $match)) {
            $session = __DIR__ . '/../storage/sessions/sess_' . $match[1];
            if (is_file($session)) unlink($session);
        }
        unlink($cookie);
    }
    echo "CLEANUP: temporary test records, user, audits and session removed.\n";
}
