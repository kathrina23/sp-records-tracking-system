<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit; }
require_once __DIR__ . '/../app/elibrary.php';
function verify_history(bool $condition, string $message): void { if (!$condition) { throw new RuntimeException($message); } }
function history_request(string $path, ?array $data = null, bool $anonymous = false): array {
    global $cookie;
    $curl = curl_init('http://localhost:8000' . $path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30]);
    if (!$anonymous) { curl_setopt_array($curl, [CURLOPT_COOKIEFILE => $cookie, CURLOPT_COOKIEJAR => $cookie]); }
    if ($data !== null) { curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($data)); }
    $body = curl_exec($curl);
    if ($body === false) { throw new RuntimeException(curl_error($curl)); }
    $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $redirect = curl_getinfo($curl, CURLINFO_REDIRECT_URL);
    curl_close($curl);
    return [$status, $body, $redirect];
}
function history_token(string $body): string {
    preg_match('/name="csrf_token" value="([^"]+)"/', $body, $match);
    verify_history(isset($match[1]), 'Missing CSRF');
    return $match[1];
}
$tag = 'HISTORICAL-QA-' . bin2hex(random_bytes(5));
$cookie = tempnam(sys_get_temp_dir(), 'history-cookie');
$userId = 0;
$termIds = $committeeIds = $draftIds = [];
try {
    db()->prepare("INSERT INTO users(name,email,password_hash,role) VALUES(?,?,?,'lmis_data_entry')")->execute([$tag, $tag . '@example.invalid', password_hash($tag, PASSWORD_DEFAULT)]);
    $userId = (int) db()->lastInsertId();
    foreach ([2009, 2012] as $index => $year) {
        db()->prepare('INSERT INTO committee_terms(name,start_year,end_year,is_current) VALUES(?,?,?,0)')->execute([$tag . ' Term ' . $index, $year, $year + 3]);
        $termIds[] = (int) db()->lastInsertId();
        db()->prepare("INSERT INTO city_officials(term_id,name,position) VALUES(?,?,'City Councilor')")->execute([$termIds[$index], $tag . ' Author ' . $index]);
        db()->prepare('INSERT INTO committees(name) VALUES(?)')->execute([$tag . ' Committee ' . $index]);
        $committeeIds[] = (int) db()->lastInsertId();
        db()->prepare("INSERT INTO committee_members(committee_id,term_id,name,position) VALUES(?,?,?,'Chairperson')")->execute([$committeeIds[$index], $termIds[$index], $tag . ' Author ' . $index]);
    }
    foreach (['ordinance', 'resolution'] as $kind) { db()->prepare('INSERT INTO legislation_categories(kind,name) VALUES(?,?)')->execute([$kind, $tag]); }
    [$status] = history_request('/e-library_old.php', null, true);
    verify_history($status === 302, 'Anonymous historical entry allowed');
    [$status, $html] = history_request('/login.php');
    history_request('/login.php', ['csrf_token' => history_token($html), 'email' => $tag . '@example.invalid', 'password' => $tag]);
    [$status, $html] = history_request('/e-library_old.php');
    verify_history($status === 200 && str_contains($html, 'Ordinances &amp; Resolutions'), 'Historical form or sidebar missing');
    preg_match('/id="historical-legislation-options">(.*?)<\/script>/s', $html, $match);
    $options = json_decode($match[1], true);
    verify_history($options['terms'][$termIds[0]]['councilors'] === [$tag . ' Author 0'], 'Councilors not grouped by term');
    verify_history(count($options['terms'][$termIds[0]]['committees']) === 1 && (int) $options['terms'][$termIds[0]]['committees'][0]['id'] === $committeeIds[0], 'Committees not grouped by term');
    $data = ['csrf_token' => history_token($html), 'revision' => 0, 'term_id' => $termIds[0], 'kind' => 'ordinance', 'number' => $tag,
        'approved_date' => '2010-05-01', 'title' => $tag . ' Older title', 'keywords' => 'health, transport', 'category' => $tag,
        'authors' => [$tag . ' Author 0'], 'co_authors' => ['N/A'], 'committee_ids' => [$committeeIds[0]], 'folder_code' => ''];
    [$status, $body] = history_request('/e-library_old.php', array_replace($data, ['authors' => [$tag . ' Author 1']]));
    verify_history($status === 200 && str_contains($body, 'from the selected term'), 'Cross-term author accepted');
    [$status, $body] = history_request('/e-library_old.php', array_replace($data, ['committee_ids' => [$committeeIds[1]]]));
    verify_history($status === 200 && str_contains($body, 'selected term'), 'Cross-term committee accepted');
    [$status, $body] = history_request('/e-library_old.php', array_replace($data, ['approved_date' => '2026-10-04']));
    verify_history($status === 200 && str_contains($body, 'within the selected term'), 'Invalid approval term accepted');
    foreach (['ordinance', 'resolution'] as $kind) {
        $data['kind'] = $kind;
        if ($kind === 'resolution') {
            $parentId = (int) db()->query('SELECT id FROM legislation_publications WHERE source_draft_id=' . $draftIds[0])->fetchColumn();
            $data['amendment_ids'] = [$parentId];
            [$invalidStatus, $invalidBody] = history_request('/e-library_old.php', array_replace($data, ['amendment_ids' => ['invalid']]));
            verify_history($invalidStatus === 200 && str_contains($invalidBody, 'existing published'), 'Invalid amendment target accepted');
        }
        $data['revision'] = 0;
        [$status, $body, $location] = history_request('/e-library_old.php', $data);
        verify_history($status === 302 && str_contains($location, 'elibrary_review.php'), 'Historical draft failed');
        $stmt = db()->prepare('SELECT * FROM legislation_drafts WHERE term_id=? AND kind=? AND number=?');
        $stmt->execute([$termIds[0], $kind, $tag]);
        $draft = $stmt->fetch();
        $draftIds[] = (int) $draft['id'];
        verify_history($draft['record_id'] === null && $draft['stored_name'] === '', 'Historical entry requires record or file');
        [$status, $html] = history_request('/legislation.php?search=' . urlencode($tag), null, true);
        verify_history(!str_contains($html, $tag . ' Older title') || $kind === 'resolution', 'Draft exposed before publication');
        $review = '/elibrary_review.php?id=' . $draft['id'];
        [$status, $html] = history_request($review);
        verify_history(str_contains($html, $tag . ' Committee 0'), 'Committee missing in review');
        [$status] = history_request($review, ['csrf_token' => history_token($html), 'revision' => 1]);
        verify_history($status === 302, 'Historical publish failed');
        if ($kind === 'resolution') {
            $childId = (int) db()->query('SELECT id FROM legislation_publications WHERE source_draft_id=' . (int) $draft['id'])->fetchColumn();
            [$detailStatus, $detailHtml] = history_request('/legislation_detail.php?id=' . $parentId, null, true);
            verify_history($detailStatus === 200 && str_contains($detailHtml, 'panel-amendments') && str_contains($detailHtml, '/legislation_detail.php?id=' . $childId), 'Parent amendment link missing');
            [$detailStatus, $detailHtml] = history_request('/legislation_detail.php?id=' . $childId, null, true);
            verify_history($detailStatus === 200 && str_contains($detailHtml, '/legislation_detail.php?id=' . $parentId), 'Tagged legislation link missing');
            try { legislation_amendment_ids(['amendment_ids' => [$childId]], (int) $draft['id']); throw new RuntimeException('Self tag accepted'); }
            catch (InvalidArgumentException $expected) {}
            $data['amendment_ids'] = [];
        }
        [$status, $html] = history_request('/legislation.php?' . http_build_query(['search' => $tag, 'term_id' => $termIds[0], 'kind' => $kind]), null, true);
        verify_history($status === 200 && str_contains($html, $tag . ' Older title'), 'Historical term search failed');
        [$status, $pdf] = history_request('/legislation.php?' . http_build_query(['search' => $tag, 'download' => 'pdf']), null, true);
        verify_history($status === 200 && str_starts_with($pdf, '%PDF'), 'Historical report failed');
        $edit = '/e-library_old.php?edit=' . $draft['id'];
        $data['revision'] = 1;
        $data['title'] = $tag . ' Edited title';
        [$status] = history_request($edit, $data);
        verify_history($status === 302, 'Historical edit failed');
        if ($kind === 'resolution') {
            [$detailStatus, $detailHtml] = history_request('/legislation_detail.php?id=' . $parentId, null, true);
            verify_history(str_contains($detailHtml, '/legislation_detail.php?id=' . $childId), 'Unreviewed removal changed public amendment links');
        }
        $check = db()->prepare('SELECT title FROM legislation_publications WHERE source_draft_id=?');
        $check->execute([$draft['id']]);
        verify_history($check->fetchColumn() !== $data['title'], 'Unreviewed edit changed public data');
        [$status, $html] = history_request($review);
        history_request($review, ['csrf_token' => history_token($html), 'revision' => 2]);
        $check = db()->prepare('SELECT COUNT(*) FROM legislation_publications WHERE source_draft_id=?');
        $check->execute([$draft['id']]);
        verify_history((int) $check->fetchColumn() === 1, 'Reposting duplicated historical publication');
        if ($kind === 'resolution') {
            [$detailStatus, $detailHtml] = history_request('/legislation_detail.php?id=' . $parentId, null, true);
            verify_history(!str_contains($detailHtml, '/legislation_detail.php?id=' . $childId), 'Removed tag remains public after repost');
        }
        $data['title'] = $tag . ' Older title';
    }
    [$status, $body] = history_request('/e-library_old.php', array_replace($data, ['revision' => 0]));
    verify_history($status === 200 && str_contains($body, 'already exists'), 'Duplicate document allowed');
    db()->prepare("UPDATE users SET role='records_officer' WHERE id=?")->execute([$userId]);
    history_request('/logout.php');
    [$status, $html] = history_request('/login.php');
    history_request('/login.php', ['csrf_token' => history_token($html), 'email' => $tag . '@example.invalid', 'password' => $tag]);
    [$status] = history_request('/e-library_old.php');
    verify_history($status === 403, 'Non-entry staff can enter historical legislation');
    echo "PASS: Historical entry, term-specific authors and committees, date validation, both types, optional files, draft privacy, review, publishing, term search, PDF report, editing, reposting, duplicate and role protection.\n";
} finally {
    foreach ($draftIds as $draftId) { db()->prepare('DELETE FROM legislation_publications WHERE source_draft_id=?')->execute([$draftId]); }
    foreach ($termIds as $termId) {
        db()->prepare('DELETE FROM legislation_drafts WHERE term_id=?')->execute([$termId]);
        db()->prepare('DELETE FROM committee_terms WHERE id=?')->execute([$termId]);
    }
    foreach ($committeeIds as $committeeId) { db()->prepare('DELETE FROM committees WHERE id=?')->execute([$committeeId]); }
    db()->prepare('DELETE FROM legislation_categories WHERE name=?')->execute([$tag]);
    if ($userId) { db()->prepare('DELETE FROM audit_logs WHERE user_id=?')->execute([$userId]); db()->prepare('DELETE FROM users WHERE id=?')->execute([$userId]); }
    unlink($cookie);
}
