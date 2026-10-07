<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/helpers.php';
$checks = 0;
foreach (['Committee Referrals', 'Certified Urgent'] as $type) {
    foreach (['admin', 'city_secretary', 'administrative_support', 'lmis_data_entry', 'receiving_clerk', 'secretariat', 'division_staff', 'messengerial_support'] as $role) {
        $_SESSION['user'] = ['id' => 0, 'role' => $role];
        foreach (['Approved in the Plenary', 'For Publication', 'Published'] as $status) {
            $record = ['id' => 1, 'committee_id' => null, 'document_type' => $type, 'status' => $status,
                'plenary_approved_date' => '2026-10-07'];
            $allowed = in_array($role, ['admin', 'city_secretary', 'administrative_support'], true);
            if (can_manage_record_publication($record) !== $allowed) {
                throw new RuntimeException("Publication permission: $role / $type / $status");
            }
            if ($allowed && (!can_view_record($record) || !can_update_record_status($record))) {
                throw new RuntimeException("Publication workflow access: $role / $type / $status");
            }
            $checks++;
        }
        $record['status'] = 'For Plenary Session';
        $record['plenary_approved_date'] = null;
        if (can_manage_record_publication($record)) {
            throw new RuntimeException('Publication allowed before approval');
        }
    }
}
echo "PASS: $checks publication workflow permission combinations.\n";

if (!in_array('--http', $argv, true)) { exit; }
require_once __DIR__ . '/../app/db.php';
function publication_check(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
}
function publication_request(string $path, ?array $data = null, bool $anonymous = false): array {
    global $publicationCookie;
    $curl = curl_init('http://127.0.0.1:8000' . $path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30]);
    if (!$anonymous) {
        curl_setopt_array($curl, [CURLOPT_COOKIEFILE => $publicationCookie, CURLOPT_COOKIEJAR => $publicationCookie]);
    }
    if ($data !== null) { curl_setopt($curl, CURLOPT_POSTFIELDS, $data); }
    $body = curl_exec($curl);
    if ($body === false) { throw new RuntimeException(curl_error($curl)); }
    $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    return [$status, $body];
}
function publication_token(string $html): string {
    preg_match('/name="csrf_token" value="([^"]+)"/', $html, $match);
    publication_check(isset($match[1]), 'CSRF token missing');
    return $match[1];
}
$tag = 'PUBLICATION-TEST-' . bin2hex(random_bytes(6));
$userIds = $recordIds = $cookies = [];
try {
    foreach (['admin', 'city_secretary', 'administrative_support'] as $role) {
        $publicationCookie = tempnam(sys_get_temp_dir(), 'publication-cookie');
        $cookies[] = $publicationCookie;
        $email = $tag . '-' . $role . '@example.invalid';
        db()->prepare('INSERT INTO users(name,email,password_hash,role) VALUES(?,?,?,?)')
            ->execute([$tag, $email, password_hash($tag, PASSWORD_DEFAULT), $role]);
        $userIds[] = (int) db()->lastInsertId();
        [, $html] = publication_request('/login.php');
        [$status] = publication_request('/login.php', ['csrf_token' => publication_token($html), 'email' => $email, 'password' => $tag]);
        publication_check($status === 302, 'Login failed: ' . $role);
        foreach (['Committee Referrals', 'Certified Urgent'] as $type) {
            db()->prepare("INSERT INTO records(control_number,title,document_type,origin,received_date,status,plenary_approved_date,approved_resolution_number) VALUES(?,?,?,'Integration test','2026-10-07','Approved in the Plenary','2026-10-07',?)")
                ->execute([$tag . '-' . count($recordIds), $tag, $type, $tag]);
            $id = (int) db()->lastInsertId();
            $recordIds[] = $id;
            [$status, $html] = publication_request('/record_update.php?id=' . $id);
            publication_check($status === 200 && str_contains($html, 'value="For Publication"') && str_contains($html, 'name="published_on"'), 'Publication form missing: ' . $role . ' / ' . $type);
            $data = ['csrf_token' => publication_token($html), 'record_id' => $id, 'status' => 'For Publication'];
            publication_request('/status_update.php', $data);
            publication_check(db()->query("SELECT status FROM records WHERE id=$id")->fetchColumn() === 'For Publication', 'For Publication save failed');
            [, $html] = publication_request('/public_status.php?record_id=' . $id, null, true);
            publication_check(str_contains($html, '>For Publication</span>'), 'Public pending publication status missing');
            $data['status'] = 'Published';
            foreach (['', str_repeat('x', 256)] as $invalid) {
                $data['published_on'] = $invalid;
                publication_request('/status_update.php', $data);
                publication_check(db()->query("SELECT status FROM records WHERE id=$id")->fetchColumn() === 'For Publication', 'Invalid publication date accepted');
            }
            $data['published_on'] = 'October 7, 2026';
            publication_request('/status_update.php', $data);
            $saved = db()->query("SELECT status,published_on FROM records WHERE id=$id")->fetch();
            publication_check($saved['status'] === 'Published' && $saved['published_on'] === $data['published_on'], 'Published save failed');
            [, $html] = publication_request('/public_status.php?record_id=' . $id, null, true);
            publication_check(str_contains($html, '>Published</span>') && str_contains($html, 'October 7, 2026'), 'Public publication details missing');
            [, $html] = publication_request('/record_view.php?id=' . $id);
            publication_check(str_contains($html, '<strong>Date Published</strong>') && str_contains($html, 'October 7, 2026'), 'Private publication details missing');
            $movement = db()->query("SELECT notes FROM record_movements WHERE record_id=$id ORDER BY id DESC LIMIT 1")->fetchColumn();
            publication_check(str_contains($movement, 'Date Published: October 7, 2026'), 'Publication history missing');
        }
    }
    echo "PASS: HTTP save, required/length validation, public/private display and history for all three roles and both record types.\n";
} finally {
    foreach ($recordIds as $id) {
        db()->prepare("DELETE FROM audit_logs WHERE entity_type='record' AND entity_id=?")->execute([$id]);
        db()->prepare('DELETE FROM records WHERE id=?')->execute([$id]);
    }
    foreach ($userIds as $id) {
        db()->prepare('DELETE FROM audit_logs WHERE user_id=?')->execute([$id]);
        db()->prepare('DELETE FROM users WHERE id=?')->execute([$id]);
    }
    foreach ($cookies as $cookie) { unlink($cookie); }
}
