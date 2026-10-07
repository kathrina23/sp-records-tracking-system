<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit; }
require_once __DIR__ . '/../app/elibrary.php';

function check(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
}
function request(string $path, ?array $data = null, bool $anonymous = false): array {
    global $cookie;
    $curl = curl_init('http://localhost:8000' . $path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30]);
    if (!$anonymous) {
        curl_setopt_array($curl, [CURLOPT_COOKIEFILE => $cookie, CURLOPT_COOKIEJAR => $cookie]);
    }
    if ($data !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
    }
    $body = curl_exec($curl);
    if ($body === false) { throw new RuntimeException(curl_error($curl)); }
    $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $redirect = curl_getinfo($curl, CURLINFO_REDIRECT_URL);
    curl_close($curl);
    return [$status, $body, $redirect];
}
function token(string $body): string {
    preg_match('/name="csrf_token" value="([^"]+)"/', $body, $match);
    check(isset($match[1]), 'CSRF token missing');
    return $match[1];
}
$tag = 'ELIBRARY-TEST-' . bin2hex(random_bytes(6));
$cookie = tempnam(sys_get_temp_dir(), 'elib-cookie');
$upload = tempnam(sys_get_temp_dir(), 'elib-file');
file_put_contents($upload, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF\n");
$userId = $recordId = 0;
$storedFiles = [];
try {
    check(legislation_keywords(' health, transport , , public services, health ') === ['health', 'transport', 'public services'], 'Comma-separated keywords were not parsed separately');
    $categoryInsert = db()->prepare('INSERT INTO legislation_categories(kind,name) VALUES(?,?)');
    foreach (['ordinance', 'resolution'] as $categoryKind) {
        foreach ([' Public Services', ' Updated Category'] as $suffix) {
            $categoryInsert->execute([$categoryKind, $tag . $suffix]);
        }
    }
    $stmt = db()->prepare("INSERT INTO users(name,email,password_hash,role,division_name) VALUES(?,?,?,'lmis_data_entry','Administrative Support Division')");
    $stmt->execute([$tag, $tag . '@example.invalid', password_hash($tag, PASSWORD_DEFAULT)]);
    $userId = (int) db()->lastInsertId();
    $stmt = db()->prepare("INSERT INTO records(control_number,title,document_type,origin,received_date,status,approved_ordinance_number,approved_resolution_number,plenary_approved_date) VALUES(?,?,'Committee Referrals','Integration test','2026-10-03','Approved in the Plenary',?,?,'2026-10-03')");
    $stmt->execute([$tag, $tag . ' Legislation', $tag . '-O', $tag . '-R']);
    $recordId = (int) db()->lastInsertId();
    [$status, $html] = request('/login.php');
    [$status] = request('/login.php', ['csrf_token' => token($html), 'email' => $tag . '@example.invalid', 'password' => $tag]);
    check($status === 302, 'Staff login failed');
    [$status, $html] = request('/dashboard.php');
    check($status === 200 && str_contains($html, $tag) && str_contains($html, 'Prepare Posting'), 'Staff Plenary dashboard failed');
    check(!str_contains($html, 'New Update Status') && !str_contains($html, 'Add Recipients'), 'Incorrect staff actions');
    check(str_contains($html, 'href="/terms.php"'), 'Terms tab missing for LMIS Data Entry Staff');
    [$status, $termsHtml] = request('/terms.php');
    check($status === 200 && str_contains($termsHtml, 'Term Directory') && !str_contains($termsHtml, 'Save Term'), 'LMIS terms viewing failed');
    [$status] = request('/terms.php', ['action' => 'save']);
    check($status === 403, 'LMIS staff can change terms');
    foreach (['ordinance', 'resolution'] as $kind) {
        $formPath = '/elibrary_form.php?record_id=' . $recordId . '&kind=' . $kind;
        [$status, $html] = request($formPath);
        check($status === 200, 'Posting form failed');
        check(str_contains($html, '<textarea name="title"') && str_contains($html, $tag . ' Legislation'), 'Editable title is missing or not initialized from the record');
        check(str_contains($html, '<select name="category"') && str_contains($html, 'list="councilor-names"'), 'Category dropdown or councilor suggestions missing');
        [$categoryStatus] = request('/elibrary_categories.php');
        check($categoryStatus === 200, 'LMIS Data Entry Staff cannot access E-Library Data Entry');
        $fields = ['csrf_token' => token($html), 'revision' => '0', 'title' => $tag . ' Legislation for E-Library', 'keywords' => $tag . ', environment, transport',
            'category' => $tag . ' Public Services', 'author' => 'Test Author', 'co_author' => '', 'folder_code' => ''];
        if ($kind === 'resolution') {
            $parentId = (int) db()->query("SELECT id FROM legislation_publications WHERE record_id=$recordId AND kind='ordinance'")->fetchColumn();
            $fields['amendment_ids[0]'] = (string) $parentId;
        }
        [$invalidStatus, $invalidBody] = request($formPath, array_replace($fields, ['category' => $tag . ' Invalid']));
        check($invalidStatus === 200 && str_contains($invalidBody, 'Please select a saved category'), 'Unsaved category accepted');
        foreach (['   ', str_repeat('x', 20001), ['invalid']] as $invalidTitle) {
            $invalidFields = array_replace($fields, ['title' => $invalidTitle]);
            if (is_array($invalidTitle)) {
                unset($invalidFields['title']);
                $invalidFields['title[0]'] = 'invalid';
            }
            [$invalidStatus, $invalidBody] = request($formPath, $invalidFields);
            check($invalidStatus === 200 && str_contains($invalidBody, 'Enter the title'), 'Invalid title accepted');
        }
        [$status, $body] = request($formPath, $fields);
        check($status === 302, 'Draft without a signed copy was rejected');
        $noFileDraft = db()->query("SELECT * FROM legislation_drafts WHERE record_id=$recordId AND kind='$kind'")->fetch();
        check($noFileDraft['title'] === $fields['title'], 'Edited title was not saved');
        $noFileReview = '/elibrary_review.php?id=' . $noFileDraft['id'];
        [$status, $noFileHtml] = request($noFileReview);
        check($status === 200 && str_contains($noFileHtml, 'No copy attached.'), 'Review without a copy failed');
        [$status] = request($noFileReview, ['csrf_token' => token($noFileHtml), 'revision' => '1']);
        check($status === 302, 'Posting without a copy failed');
        $noFilePublication = db()->query("SELECT id FROM legislation_publications WHERE record_id=$recordId AND kind='$kind'")->fetchColumn();
        [$status] = request('/legislation_file.php?id=' . $noFilePublication, null, true);
        check($status === 404, 'Empty attachment returned a file');
        [$status, $detailsHtml] = request('/legislation_detail.php?id=' . $noFilePublication, null, true);
        check($status === 200 && str_contains($detailsHtml, 'No copy attached.') && str_contains($detailsHtml, 'role="tablist"'), 'Public detail window without a copy failed');
        [$status, $noFileHtml] = request('/legislation.php?search=' . urlencode($tag . '-' . ($kind === 'ordinance' ? 'O' : 'R')), null, true);
        check($status === 200 && str_contains($noFileHtml, 'No copy attached.') && !str_contains($noFileHtml, 'legislation_file.php?id='), 'Public result exposes a missing copy link');
        foreach (['legislation_publications', 'legislation_drafts'] as $table) {
            db()->prepare("DELETE FROM $table WHERE record_id=? AND kind=?")->execute([$recordId, $kind]);
        }
        file_put_contents($upload, '<?php echo "unsafe";');
        [$status, $body] = request($formPath, $fields + ['signed_file' => new CURLFile($upload, 'application/pdf', 'signed.pdf')]);
        check($status === 200 && str_contains($body, 'Only PDF, JPEG, and PNG files are allowed'), 'Spoofed PDF was accepted');
        file_put_contents($upload, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF\n");
        [$status, $body, $location] = request($formPath, $fields + ['signed_file' => new CURLFile($upload, 'application/pdf', 'signed.pdf')]);
        check($status === 302 && str_contains($location, 'elibrary_review.php'), 'Draft upload failed: ' . strip_tags($body));
        $draft = db()->query("SELECT * FROM legislation_drafts WHERE record_id=$recordId AND kind='$kind'")->fetch();
        $storedFiles[] = $draft['stored_name'];
        [$status, $html] = request('/legislation.php?search=' . urlencode($tag), null, true);
        check(!str_contains($html, $tag . ' Legislation') || $kind === 'resolution', 'Draft leaked to public search');
        [$status] = request('/legislation_file.php?draft=1&id=' . $draft['id'], null, true);
        check($status === 302, 'Anonymous draft file access allowed');
        [$status] = request('/legislation_file.php?id=' . $draft['id'], null, true);
        check($status === 404 || $kind === 'resolution', 'Draft exposed through public file endpoint');
        $reviewPath = '/elibrary_review.php?id=' . $draft['id'];
        [$status, $html] = request($reviewPath);
        check($status === 200 && str_contains($html, 'Edit / Update Data'), 'Draft review failed');
        [$status, $body] = request($reviewPath, ['csrf_token' => token($html), 'revision' => '0']);
        check(str_contains($body, 'The draft changed'), 'Stale revision was published');
        [$status, $body] = request($reviewPath, ['csrf_token' => token($html), 'revision' => '1']);
        check($status === 302, 'Confirmed post failed: ' . strip_tags($body));
        [$status, $html] = request('/legislation.php?search=' . urlencode($tag . ' transport'), null, true);
        check($status === 200 && str_contains($html, $tag . ' Legislation'), 'Public keyword search failed');
        $publication = db()->query("SELECT * FROM legislation_publications WHERE record_id=$recordId AND kind='$kind'")->fetch();
        if ($kind === 'resolution') {
            check(json_decode($publication['amendment_ids'], true) === [$parentId], 'Tracked amendment tag was not published');
            [$detailStatus, $detailHtml] = request('/legislation_detail.php?id=' . $parentId, null, true);
            check($detailStatus === 200 && str_contains($detailHtml, '/legislation_detail.php?id=' . $publication['id']), 'Tracked amendment missing from parent tab');
            try { legislation_amendment_ids(['amendment_ids' => [$publication['id']]], (int) $draft['id']); throw new RuntimeException('Tracked self tag accepted'); }
            catch (InvalidArgumentException $expected) {}
        }
        [$status, $body] = request('/legislation_file.php?id=' . $publication['id'], null, true);
        check($status === 200 && str_starts_with($body, '%PDF'), 'Published signed file unavailable');
        [$status, $detailsHtml] = request('/legislation_detail.php?id=' . $publication['id'], null, true);
        check($status === 200 && str_contains($detailsHtml, 'View PDF') && str_contains($detailsHtml, 'panel-information'), 'Public detail window with a copy failed');
        [$status, $html] = request($formPath);
        $fields['csrf_token'] = token($html);
        $fields['revision'] = '1';
        $fields['category'] = $tag . ' Updated Category';
        check(str_contains($html, $fields['title']), 'Saved title was not retained when reopening the form');
        $fields['title'] = $tag . ' Legislation revised for E-Library';
        [$status] = request($formPath, $fields);
        check($status === 302, 'Edit without replacing signed file failed');
        $category = db()->query("SELECT category FROM legislation_publications WHERE record_id=$recordId AND kind='$kind'")->fetchColumn();
        check($category === $tag . ' Public Services', 'Unreviewed edit altered public publication');
        $publishedTitle = db()->query("SELECT title FROM legislation_publications WHERE record_id=$recordId AND kind='$kind'")->fetchColumn();
        check($publishedTitle === $tag . ' Legislation for E-Library', 'Unreviewed title changed the public publication');
        [$status, $html] = request($reviewPath);
        [$status, $body] = request($reviewPath, ['csrf_token' => token($html), 'revision' => '2']);
        check($status === 302, 'Reposting edited draft failed: ' . strip_tags($body));
        $publishedTitle = db()->query("SELECT title FROM legislation_publications WHERE record_id=$recordId AND kind='$kind'")->fetchColumn();
        check($publishedTitle === $fields['title'], 'Reposted title was not updated');
        check(db()->query("SELECT title FROM records WHERE id=$recordId")->fetchColumn() === $tag . ' Legislation', 'E-Library title edit changed the tracked record');
    }
    [$status, $html] = request('/legislation.php?search=' . urlencode($tag . ' Updated Category'), null, true);
    check($status === 200 && substr_count($html, $tag . ' Legislation') === 2, 'Updated ordinance and resolution not searchable');
    [$status, $html] = request('/legislation.php?' . http_build_query(['search' => $tag, 'kind' => 'resolution', 'category' => $tag . ' Updated Category', 'author' => 'Test Author']), null, true);
    check($status === 200 && substr_count($html, $tag . ' Legislation') === 1, 'Combined type, category, and author filters failed');
    [$status, $html] = request('/legislation.php?' . http_build_query(['search' => $tag, 'author' => 'No Matching Author']), null, true);
    check($status === 200 && !str_contains($html, $tag . ' Legislation'), 'Author filter ignored');
    [$status, $report] = request('/legislation.php?' . http_build_query(['search' => $tag, 'kind' => 'resolution', 'download' => 'pdf']), null, true);
    check($status === 200 && str_starts_with($report, '%PDF-'), 'Filtered PDF report failed');
    [$status, $html] = request('/legislation.php?' . http_build_query(['search' => $tag, 'co_author' => 'No Matching Co-Author']), null, true);
    check($status === 200 && !str_contains($html, $tag . ' Legislation'), 'Co-author filter ignored');
    [$filterSql, $filterParams] = legislation_search_query($tag, 1, ['term_id' => 999999999]);
    $filterStmt = db()->prepare($filterSql);
    $filterStmt->execute($filterParams);
    check(!$filterStmt->fetch(), 'Term filter ignored');
    [$status, $html] = request('/elibrary_form.php?record_id=' . $recordId . '&kind=invalid');
    check($status === 400, 'Invalid legislation kind accepted');
    [$status] = request('/elibrary_form.php?record_id=' . $recordId, ['csrf_token' => 'invalid']);
    check($status === 419, 'CSRF protection failed');
    db()->prepare("UPDATE records SET status='Received', plenary_approved_date=NULL, approved_ordinance_number=NULL, approved_resolution_number=NULL WHERE id=?")->execute([$recordId]);
    [$status] = request('/elibrary_form.php?record_id=' . $recordId);
    check($status === 404, 'Unapproved record accepted');
    db()->prepare("UPDATE users SET role='admin' WHERE id=?")->execute([$userId]);
    request('/logout.php');
    [$status, $html] = request('/login.php');
    request('/login.php', ['csrf_token' => token($html), 'email' => $tag . '@example.invalid', 'password' => $tag]);
    [$status, $html] = request('/elibrary_categories.php');
    check($status === 200 && str_contains($html, 'E-Library Data Entry'), 'Data entry page unavailable to administrator');
    $categoryData = ['csrf_token' => token($html), 'kind' => 'ordinance', 'name' => $tag . ' Managed'];
    [$status] = request('/elibrary_categories.php', $categoryData);
    check($status === 302 && in_array($tag . ' Managed', legislation_categories('ordinance'), true), 'Category creation failed');
    check(!in_array($tag . ' Managed', legislation_categories('resolution'), true), 'Category leaked across legislation types');
    [$status, $body] = request('/elibrary_categories.php', $categoryData);
    check($status === 200 && str_contains($body, 'already exists'), 'Duplicate category accepted');
    $categoryLookup = db()->prepare('SELECT id FROM legislation_categories WHERE kind=? AND name=?');
    $categoryLookup->execute(['ordinance', $tag . ' Managed']);
    $managedCategoryId = (int) $categoryLookup->fetchColumn();
    [$status, $body] = request('/elibrary_categories.php?edit=' . $managedCategoryId);
    check($status === 200 && str_contains($body, 'Save Category') && str_contains($body, $tag . ' Managed'), 'Category edit form failed');
    $updateCategory = ['csrf_token' => token($body), 'action' => 'update', 'category_id' => $managedCategoryId, 'name' => $tag . ' Public Services'];
    [$status, $body] = request('/elibrary_categories.php', $updateCategory);
    check($status === 200 && str_contains($body, 'already exists'), 'Duplicate category rename accepted');
    [$status] = request('/elibrary_categories.php', array_replace($updateCategory, ['csrf_token' => 'invalid']));
    check($status === 419, 'Category edit missing CSRF protection');
    [$status] = request('/elibrary_categories.php', array_replace($updateCategory, ['name' => $tag . ' Renamed', 'kind' => 'resolution']));
    check($status === 302 && in_array($tag . ' Renamed', legislation_categories('ordinance'), true), 'Category rename failed');
    check(!in_array($tag . ' Renamed', legislation_categories('resolution'), true), 'Category edit changed its legislation type');
    $deleteCategory = ['csrf_token' => $categoryData['csrf_token'], 'action' => 'delete', 'category_id' => $managedCategoryId];
    [$status] = request('/elibrary_categories.php', array_replace($deleteCategory, ['csrf_token' => 'invalid']));
    check($status === 419, 'Category deletion missing CSRF protection');
    [$status] = request('/elibrary_categories.php', $deleteCategory);
    check($status === 302 && !in_array($tag . ' Renamed', legislation_categories('ordinance'), true), 'Category deletion failed');
    [$status] = request('/elibrary_categories.php', $deleteCategory);
    check($status === 404, 'Missing category deletion accepted');
    foreach (['city_secretary', 'records_officer', 'secretariat'] as $accessRole) {
        db()->prepare('UPDATE users SET role=? WHERE id=?')->execute([$accessRole, $userId]);
        request('/logout.php');
        [$status, $html] = request('/login.php');
        request('/login.php', ['csrf_token' => token($html), 'email' => $tag . '@example.invalid', 'password' => $tag]);
        [$status, $html] = request('/elibrary_categories.php');
        check($status === ($accessRole === 'secretariat' ? 403 : 200), 'Incorrect data entry access for ' . $accessRole);
        if ($accessRole === 'secretariat') {
            [$status] = request('/elibrary_categories.php', $deleteCategory);
            check($status === 403, 'Unauthorized category deletion accepted');
            [$status] = request('/elibrary_categories.php', $updateCategory);
            check($status === 403, 'Unauthorized category edit accepted');
        }
        [$status, $html] = request('/dashboard.php');
        check(str_contains($html, 'href="/elibrary_categories.php"') === ($accessRole !== 'secretariat'), 'Incorrect sidebar visibility for ' . $accessRole);
    }
    db()->prepare("UPDATE users SET role='administrative_support' WHERE id=?")->execute([$userId]);
    request('/logout.php');
    [$status, $html] = request('/login.php');
    request('/login.php', ['csrf_token' => token($html), 'email' => $tag . '@example.invalid', 'password' => $tag]);
    [$status] = request('/elibrary_form.php?record_id=' . $recordId);
    check($status === 403, 'LMIS Records Staff can post with the wrong role');
    echo "PASS: Staff dashboard, both legislation types, PDF upload, draft privacy, review, stale revision, posting, public keyword search, signed download, edit isolation, reposting, CSRF, and role restrictions.\n";
} finally {
    db()->prepare('DELETE FROM legislation_categories WHERE name IN (?,?,?,?)')->execute([$tag . ' Public Services', $tag . ' Updated Category', $tag . ' Managed', $tag . ' Renamed']);
    if ($recordId) {
        foreach (['legislation_drafts', 'legislation_publications'] as $table) {
            db()->prepare("DELETE FROM $table WHERE record_id=?")->execute([$recordId]);
        }
        db()->prepare('DELETE FROM records WHERE id=?')->execute([$recordId]);
    }
    if ($userId) {
        db()->prepare('DELETE FROM audit_logs WHERE user_id=?')->execute([$userId]);
        db()->prepare('DELETE FROM users WHERE id=?')->execute([$userId]);
    }
    foreach ($storedFiles as $stored) {
        $path = dirname(__DIR__) . '/storage/elibrary/' . basename($stored);
        if (is_file($path)) { unlink($path); }
    }
    unlink($upload);
    unlink($cookie);
}
