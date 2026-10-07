<?php
require_once __DIR__ . '/../app/legislation_import.php';
function check_import(bool $condition, string $message): void { if (!$condition) { throw new RuntimeException($message); } }
$tag = 'IMPORT-QA-' . bin2hex(random_bytes(6));
$cookie = tempnam(sys_get_temp_dir(), 'import-cookie');
$csv = tempnam(sys_get_temp_dir(), 'import-csv');
$pdf = tempnam(sys_get_temp_dir(), 'import-pdf');
$userId = $termId = 0; $stored = [];
function import_request(string $path, ?array $data = null, bool $anonymous = false): array {
    global $cookie;
    $curl = curl_init('http://localhost:8000' . $path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30]);
    if (!$anonymous) { curl_setopt_array($curl, [CURLOPT_COOKIEFILE => $cookie, CURLOPT_COOKIEJAR => $cookie]); }
    if ($data !== null) {
        $multipart = count(array_filter($data, fn ($value) => $value instanceof CURLFile)) > 0;
        curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $multipart ? $data : http_build_query($data)]);
    }
    $body = curl_exec($curl); $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE); curl_close($curl);
    return [$status, (string) $body];
}
function import_token(string $body, string $name = 'csrf_token'): string {
    preg_match('/name="' . $name . '" value="([^"]+)"/', $body, $match);
    check_import(isset($match[1]), 'Missing ' . $name); return $match[1];
}
try {
    db()->prepare("INSERT INTO users(name,email,password_hash,role) VALUES(?,?,?,'lmis_data_entry')")->execute([$tag, $tag . '@example.invalid', password_hash($tag, PASSWORD_DEFAULT)]);
    $userId = (int) db()->lastInsertId();
    db()->prepare('INSERT INTO committee_terms(name,start_year,end_year,is_current) VALUES(?,2025,2028,0)')->execute([$tag]);
    $termId = (int) db()->lastInsertId();
    $headers = ['Type', 'Number', 'Title / Subject', 'Date Approved'];
    $rows = [$headers, ['Ordinance', $tag . '-001', 'Unicode ₱ test', '08/17/2026'], ['Resolution', $tag . '-002', 'Second entry', '46251']];
    [$entries, $errors] = legislation_import_validate($rows, ['id' => $termId, 'start_year' => 2025, 'end_year' => 2028]);
    check_import(count($entries) === 2 && !$errors && $entries[1]['approved_date'] === '2026-08-17', 'Excel date or mixed type validation failed');
    check_import($entries[0]['approved_date'] === '2026-08-17', 'MM/DD/YYYY was not normalized');
    $dateHeaders = ['Type', 'Number', 'Title / Subject', 'Date Approved (MM/DD/YYYY)'];
    [$dateEntries, $dateErrors] = legislation_import_validate([$dateHeaders, ['Ordinance', $tag, 'Date test', '01/02/2026']], ['id' => $termId, 'start_year' => 2025, 'end_year' => 2028]);
    check_import(!$dateErrors && $dateEntries[0]['approved_date'] === '2026-01-02', 'Month/day order or template header failed');
    [$dateEntries, $dateErrors] = legislation_import_validate([$dateHeaders, ['Ordinance', $tag, 'Date test', '02/30/2026']], ['id' => $termId, 'start_year' => 2025, 'end_year' => 2028]);
    check_import(!$dateEntries && count($dateErrors) === 1, 'Invalid calendar date was accepted');
    [$valid, $errors] = legislation_import_validate([$headers, $rows[1], $rows[1]], ['id' => $termId, 'start_year' => 2025, 'end_year' => 2028]);
    check_import(count($errors) === 1 && str_contains($errors[0], 'Row 3'), 'Duplicate row not rejected');
    $monthlyTerm = ['id' => $termId, 'name' => 'Test', 'start_year' => 2025, 'start_month' => 7, 'end_year' => 2028, 'end_month' => 6];
    check_import(term_contains_date($monthlyTerm, '2025-07-01') && term_contains_date($monthlyTerm, '2028-06-30'), 'Term boundary months excluded');
    check_import(!term_contains_date($monthlyTerm, '2025-06-30') && !term_contains_date($monthlyTerm, '2028-07-01'), 'Outside term months accepted');
    check_import(term_period_label($monthlyTerm) === 'July 2025 – June 2028', 'Month/year display failed');
    [$valid, $errors] = legislation_import_validate([$headers, ['Ordinance', $tag, 'Outside term', '2025-06-30']], $monthlyTerm);
    check_import(!$valid && count($errors) === 1, 'Import ignored month boundaries');
    [$status] = import_request('/elibrary_import.php', null, true); check_import($status === 302, 'Anonymous import allowed');
    [, $body] = import_request('/login.php');
    import_request('/login.php', ['csrf_token' => import_token($body), 'email' => $tag . '@example.invalid', 'password' => $tag]);
    [, $body] = import_request('/elibrary_import.php');
    $handle = fopen($csv, 'w'); foreach ([$headers, $rows[1], $rows[1]] as $row) { fputcsv($handle, $row); } fclose($handle);
    [$status, $invalid] = import_request('/elibrary_import.php', ['csrf_token' => import_token($body), 'term_id' => $termId, 'workbook' => new CURLFile($csv, 'text/csv', 'entries.csv')]);
    check_import(str_contains($invalid, 'Row 3:') && !str_contains($invalid, 'preview_token'), 'Invalid upload offered confirmation');
    $handle = fopen($csv, 'w'); foreach ($rows as $row) { fputcsv($handle, $row); } fclose($handle);
    [$status, $body] = import_request('/elibrary_import.php', ['csrf_token' => import_token($body), 'term_id' => $termId, 'workbook' => new CURLFile($csv, 'text/csv', 'entries.csv')]);
    check_import($status === 200 && str_contains($body, 'Preview 2 Entries'), 'Preview failed: ' . strip_tags($body));
    check_import(str_contains($body, '<td>08/17/2026</td>'), 'Preview did not show MM/DD/YYYY');
    $token = import_token($body, 'preview_token'); $csrf = import_token($body);
    $query = db()->prepare('SELECT COUNT(*) FROM legislation_drafts WHERE term_id=?'); $query->execute([$termId]); check_import((int) $query->fetchColumn() === 0, 'Preview saved drafts');
    [$status, $body] = import_request('/elibrary_import.php', ['csrf_token' => $csrf, 'action' => 'confirm', 'preview_token' => $token]);
    check_import($status === 200 && str_contains($body, '2 entries imported as drafts'), 'Confirmation failed');
    $query->execute([$termId]); check_import((int) $query->fetchColumn() === 2, 'Wrong import count');
    import_request('/elibrary_import.php', ['csrf_token' => $csrf, 'action' => 'confirm', 'preview_token' => $token]);
    $query->execute([$termId]); check_import((int) $query->fetchColumn() === 2, 'Replay duplicated drafts');
    $query = db()->prepare('SELECT * FROM legislation_drafts WHERE term_id=? ORDER BY id'); $query->execute([$termId]); $draft = $query->fetch();
    check_import($draft['stored_name'] === '' && $draft['record_id'] === null, 'Import requires PDF or tracked record');
    [, $body] = import_request('/elibrary_review.php?id=' . $draft['id']);
    [, $body] = import_request('/elibrary_review.php?id=' . $draft['id'], ['csrf_token' => import_token($body), 'revision' => 1]);
    check_import(str_contains($body, 'complete keywords'), 'Incomplete import published without metadata');
    [, $body] = import_request('/elibrary.php');
    check_import(!str_contains($body, 'Bulk Import from Excel'), 'Bulk import still appears in E-Library Posting');
    db()->prepare("INSERT INTO legislation_categories(kind,name) VALUES('ordinance',?)")->execute([$tag]);
    [, $body] = import_request('/e-library_old.php?edit=' . $draft['id']);
    check_import(str_contains($body, 'Save Details and Review'), 'Imported entry edit form missing');
    check_import(str_contains($body, $draft['id'] . '#historical-legislation-form'), 'Edit Details button does not target the edit form');
    $editData = ['csrf_token' => import_token($body), 'revision' => 1, 'term_id' => $termId, 'kind' => 'ordinance',
        'number' => $draft['number'], 'title' => 'Updated imported title', 'approved_date' => '2026-08-18',
        'keywords' => 'health, roads', 'category' => $tag, 'authors' => ['N/A'], 'co_authors' => ['N/A'], 'folder_code' => 'FOLDER-001'];
    [$status] = import_request('/e-library_old.php?edit=' . $draft['id'], $editData);
    check_import($status === 302, 'Imported details failed to save without a PDF');
    $query = db()->prepare('SELECT * FROM legislation_drafts WHERE id=?'); $query->execute([$draft['id']]); $edited = $query->fetch();
    check_import($edited['title'] === 'Updated imported title' && $edited['approved_date'] === '2026-08-18'
        && $edited['keywords'] === 'health, roads' && $edited['category'] === $tag && $edited['author'] === 'N/A'
        && $edited['co_author'] === 'N/A' && $edited['folder_code'] === 'FOLDER-001' && $edited['stored_name'] === '', 'Imported metadata edit lost fields');
    $query = db()->prepare('SELECT COUNT(*) FROM legislation_publications WHERE term_id=?'); $query->execute([$termId]); check_import((int) $query->fetchColumn() === 0, 'Import published entries');
    [, $body] = import_request('/elibrary_upload_copy.php?id=' . $draft['id']);
    file_put_contents($pdf, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n");
    [$status, $body] = import_request('/elibrary_upload_copy.php?id=' . $draft['id'], ['csrf_token' => import_token($body), 'revision' => 2, 'signed_file' => new CURLFile($pdf, 'application/pdf', 'signed.pdf')]);
    check_import($status === 200 && str_contains($body, 'PDF saved.'), 'Later PDF upload failed');
    $query = db()->prepare('SELECT stored_name,revision FROM legislation_drafts WHERE id=?'); $query->execute([$draft['id']]); $saved = $query->fetch(); $stored[] = $saved['stored_name'];
    check_import($saved['stored_name'] !== '' && (int) $saved['revision'] === 3, 'PDF not attached');
    [$status, $body] = import_request('/elibrary_upload_copy.php?id=' . $draft['id'], ['csrf_token' => import_token($body), 'revision' => 1, 'signed_file' => new CURLFile($pdf, 'application/pdf', 'signed.pdf')]);
    check_import(str_contains($body, 'entry changed'), 'Stale PDF upload accepted');
    [, $body] = import_request('/e-library_old.php?edit=' . $draft['id']);
    $editData['csrf_token'] = import_token($body); $editData['revision'] = 3; $editData['folder_code'] = 'FOLDER-002';
    [$status] = import_request('/e-library_old.php?edit=' . $draft['id'], $editData);
    $query = db()->prepare('SELECT stored_name,folder_code FROM legislation_drafts WHERE id=?'); $query->execute([$draft['id']]); $afterEdit = $query->fetch();
    check_import($status === 302 && $afterEdit['stored_name'] === $saved['stored_name'] && $afterEdit['folder_code'] === 'FOLDER-002', 'Editing metadata lost the PDF');
    db()->prepare("UPDATE users SET role='records_officer' WHERE id=?")->execute([$userId]);
    import_request('/logout.php');
    [, $body] = import_request('/login.php');
    import_request('/login.php', ['csrf_token' => import_token($body), 'email' => $tag . '@example.invalid', 'password' => $tag]);
    [$status] = import_request('/elibrary_import.php'); check_import($status === 403, 'Unauthorized role allowed');
    [$status] = import_request('/elibrary_upload_copy.php?id=' . $draft['id']); check_import($status === 403, 'Unauthorized PDF upload allowed');
    db()->prepare("UPDATE users SET role='admin' WHERE id=?")->execute([$userId]);
    import_request('/logout.php');
    [, $body] = import_request('/login.php');
    import_request('/login.php', ['csrf_token' => import_token($body), 'email' => $tag . '@example.invalid', 'password' => $tag]);
    [, $body] = import_request('/terms.php?edit=' . $termId);
    check_import(str_contains($body, 'type="month"'), 'Month/year term inputs missing');
    $termPost = ['csrf_token' => import_token($body), 'id' => $termId, 'name' => $tag, 'start_period' => '2025-07', 'end_period' => '2028-06'];
    import_request('/terms.php', $termPost);
    $query = db()->prepare('SELECT * FROM committee_terms WHERE id=?'); $query->execute([$termId]); $savedTerm = $query->fetch();
    check_import((int) $savedTerm['start_month'] === 7 && (int) $savedTerm['end_month'] === 6, 'Term months not persisted');
    import_request('/terms.php', array_replace($termPost, ['end_period' => '2025-06']));
    $query->execute([$termId]); check_import((int) $query->fetch()['end_year'] === 2028, 'Reversed term dates saved');
    echo "PASS: import, duplicates, draft privacy, metadata editing before and after PDF upload, posting controls, term months and revision protection.\n";
} finally {
    if ($termId) {
        $query = db()->prepare('SELECT stored_name FROM legislation_drafts WHERE term_id=?'); $query->execute([$termId]); $stored = array_merge($stored, $query->fetchAll(PDO::FETCH_COLUMN));
        db()->prepare('DELETE FROM legislation_drafts WHERE term_id=?')->execute([$termId]);
        db()->prepare('DELETE FROM committee_terms WHERE id=?')->execute([$termId]);
    }
    if ($userId) { db()->prepare('DELETE FROM audit_logs WHERE user_id=?')->execute([$userId]); db()->prepare('DELETE FROM users WHERE id=?')->execute([$userId]); }
    db()->prepare('DELETE FROM legislation_categories WHERE name=?')->execute([$tag]);
    foreach (array_unique($stored) as $name) { if ($name !== '') { @unlink(dirname(__DIR__) . '/storage/elibrary/' . basename($name)); } }
    foreach ([$cookie, $csv, $pdf] as $path) { unlink($path); }
}
