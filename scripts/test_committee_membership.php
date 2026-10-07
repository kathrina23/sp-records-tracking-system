<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit; }
require_once __DIR__ . '/../app/db.php';
function check_membership(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
}
function membership_request(string $path, ?array $data = null): array {
    global $cookie;
    $curl = curl_init('http://localhost:8000' . $path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $cookie, CURLOPT_COOKIEJAR => $cookie, CURLOPT_TIMEOUT => 15]);
    if ($data !== null) { curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($data)); }
    $body = curl_exec($curl);
    if ($body === false) { throw new RuntimeException(curl_error($curl)); }
    $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    return [$status, $body];
}
function membership_token(string $body): string {
    preg_match('/name="csrf_token" value="([^"]+)"/', $body, $match);
    check_membership(isset($match[1]), 'Missing CSRF token');
    return $match[1];
}
$tag = 'MEMBERSHIP-TEST-' . bin2hex(random_bytes(5));
$cookie = tempnam(sys_get_temp_dir(), 'membership-cookie');
$userId = $committeeId = 0;
$termIds = [];
try {
    $stmt = db()->prepare("INSERT INTO users(name,email,password_hash,role) VALUES(?,?,?,'admin')");
    $stmt->execute([$tag, $tag . '@example.invalid', password_hash($tag, PASSWORD_DEFAULT)]);
    $userId = (int) db()->lastInsertId();
    db()->prepare('INSERT INTO committees(name) VALUES(?)')->execute([$tag]);
    $committeeId = (int) db()->lastInsertId();
    foreach ([2020, 2023] as $year) {
        db()->prepare('INSERT INTO committee_terms(name,start_year,end_year,is_current) VALUES(?,?,?,0)')->execute([$tag . '-' . $year, $year, $year + 3]);
        $termIds[] = (int) db()->lastInsertId();
    }
    [$status, $body] = membership_request('/login.php');
    [$status] = membership_request('/login.php', ['csrf_token' => membership_token($body), 'email' => $tag . '@example.invalid', 'password' => $tag]);
    check_membership($status === 302, 'Login failed');
    $officialInsert = db()->prepare('INSERT INTO city_officials(term_id,name,position,officer_role,sort_order) VALUES(?,?,?,?,?)');
    $officialOrder = [
        [$tag . ' Vice Mayor', 'Vice Mayor', 'Presiding Officer', 99],
        [$tag . ' Pro-Tempore', 'City Councilor', 'Presiding Officer Pro-Tempore', 8],
        [$tag . ' Majority', 'City Councilor', 'Majority Floor Leader', 7],
        [$tag . ' Minority', 'City Councilor', 'Minority Floor Leader', 6],
        [$tag . ' Z Rank One', 'City Councilor', null, 1],
        [$tag . ' A Rank Three', 'City Councilor', 'Assistant Majority Floor Leader', 3],
        [$tag . ' Unranked', 'City Councilor', null, 0],
    ];
    foreach ($officialOrder as $official) { $officialInsert->execute([$termIds[0], ...$official]); }
    [$status, $body] = membership_request('/councilors.php?term_id=' . $termIds[0]);
    check_membership($status === 200 && str_contains($body, '<h1>City Councilors</h1>'), 'Renamed City Councilors page unavailable');
    check_membership(str_contains($body, 'href="/councilors.php" class="active"'), 'Renamed sidebar link not active');
    $lastPosition = -1;
    foreach ($officialOrder as $official) {
        $position = strpos($body, '<td>' . $official[0] . '</td>');
        check_membership($position !== false && $position > $lastPosition, 'Incorrect role and ranking order for ' . $official[0]);
        $lastPosition = $position;
    }
    check_membership(str_contains($body, 'name="district"'), 'District dropdown missing');
    foreach (['District 1', 'District 2', 'Ex Officio'] as $district) {
        $data = ['csrf_token' => membership_token($body), 'term_id' => $termIds[0], 'name' => $tag . ' ' . $district,
            'position' => 'City Councilor', 'district' => $district, 'officer_role' => '', 'sort_order' => 5];
        [$status] = membership_request('/councilors.php', $data);
        check_membership($status === 302, 'Official district save failed');
        $stmt = db()->prepare('SELECT id,district FROM city_officials WHERE term_id=? AND name=?');
        $stmt->execute([$termIds[0], $data['name']]);
        $savedOfficial = $stmt->fetch();
        check_membership($savedOfficial && $savedOfficial['district'] === $district, 'District not saved');
        [$status, $editBody] = membership_request('/councilors.php?edit=' . $savedOfficial['id'] . '&term_id=' . $termIds[0]);
        check_membership(str_contains($editBody, 'value="' . $district . '" selected'), 'District not retained in edit form');
        $data['id'] = $savedOfficial['id'];
        $data['district'] = 'District 2';
        [$status] = membership_request('/councilors.php', $data);
        $stmt->execute([$termIds[0], $data['name']]);
        check_membership($status === 302 && $stmt->fetch()['district'] === 'District 2', 'District update failed');
    }
    $data['id'] = 0;
    $data['name'] = $tag . ' Invalid District';
    $data['district'] = 'District 3';
    membership_request('/councilors.php', $data);
    $stmt->execute([$termIds[0], $data['name']]);
    check_membership(!$stmt->fetch(), 'Invalid district accepted');
    [$status] = membership_request('/officials.php?term_id=' . $termIds[0]);
    check_membership($status === 307, 'Legacy councilor route did not redirect');
    foreach ($termIds as $index => $termId) {
        $path = '/committee_roster.php?committee_id=' . $committeeId . '&term_id=' . $termId;
        [$status, $body] = membership_request($path);
        check_membership($status === 200 && str_contains($body, 'Save Committee Membership'), 'Membership form unavailable');
        [$status] = membership_request($path, ['csrf_token' => membership_token($body), 'committee_id' => $committeeId, 'term_id' => $termId, 'chairperson' => $tag . ' Chair ' . $index, 'vice_chairperson' => $tag . ' Vice ' . $index, 'members' => [$tag . ' Member ' . $index]]);
        check_membership($status === 302, 'Membership save failed');
    }
    [$status, $body] = membership_request('/committees.php');
    check_membership($status === 200 && str_contains($body, 'Add Committee Membership'), 'Membership entry action missing');
    foreach ($termIds as $index => $termId) {
        [$status, $body] = membership_request('/committees.php?term_id=' . $termId);
        check_membership($status === 200 && str_contains($body, '<h1>Standing Committees</h1>'), 'Standing Committees title missing');
        check_membership(str_contains($body, 'aria-label="Membership summary by term"'), 'Term tabs missing');
        check_membership(str_contains($body, $tag . ' Chair ' . $index), 'Term membership missing from grouped list');
        check_membership(!str_contains($body, $tag . ' Chair ' . (1 - $index)), 'Another term summary is shown in the selected tab');
        check_membership(str_contains($body, 'committee_id=' . $committeeId . '&amp;term_id=' . $termId), 'Membership link does not preserve term');
        $stmt = db()->prepare('SELECT name FROM committee_members WHERE committee_id=? AND term_id=? AND position=?');
        $stmt->execute([$committeeId, $termId, 'Chairperson']);
        check_membership($stmt->fetchColumn() === $tag . ' Chair ' . $index, 'Membership save affected another term');
    }
    [$status, $body] = membership_request('/committees.php?term_id=999999999');
    check_membership($status === 200, 'Invalid term did not fall back to an available tab');
    echo "PASS: Membership creation, term tabs, selected summary, term-specific links, and isolation between terms.\n";
} finally {
    if ($committeeId) { db()->prepare('DELETE FROM committees WHERE id=?')->execute([$committeeId]); }
    foreach ($termIds as $termId) { db()->prepare('DELETE FROM committee_terms WHERE id=?')->execute([$termId]); }
    if ($userId) {
        db()->prepare('DELETE FROM audit_logs WHERE user_id=?')->execute([$userId]);
        db()->prepare('DELETE FROM users WHERE id=?')->execute([$userId]);
    }
    unlink($cookie);
}
