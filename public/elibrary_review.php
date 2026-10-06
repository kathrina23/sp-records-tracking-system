<?php
require_once __DIR__ . '/../app/elibrary.php';
require_elibrary_staff();
$id = (int) ($_GET['id'] ?? 0);
$stmt = db()->prepare('SELECT * FROM legislation_drafts WHERE id=?');
$stmt->execute([$id]);
$draft = $stmt->fetch();
if (!$draft) {
    http_response_code(404);
    exit('Draft not found.');
}
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        db()->beginTransaction();
        $stmt = db()->prepare('SELECT * FROM legislation_drafts WHERE id=? FOR UPDATE');
        $stmt->execute([$id]);
        $draft = $stmt->fetch();
        if (!$draft || (int) ($_POST['revision'] ?? 0) !== (int) $draft['revision']
            || ($_SESSION['elibrary_review'][$id] ?? null) !== (int) $draft['revision']) {
            throw new InvalidArgumentException('The draft changed. Review the latest details before posting.');
        }
        $stmt = db()->prepare('SELECT * FROM records WHERE id=? FOR UPDATE');
        $stmt->execute([$draft['record_id']]);
        $record = $stmt->fetch();
        if ($draft['record_id'] !== null && (!$record || legislation_number($record, $draft['kind']) === ''
            || legislation_number($record, $draft['kind']) !== $draft['number']
            || ($record['plenary_approved_date'] ?: null) !== $draft['approved_date'])) {
            throw new InvalidArgumentException('The approved record changed. Edit the draft to refresh its details before posting.');
        }
        legislation_fields($draft);
        if (trim($draft['title']) === '' || strlen($draft['title']) > 20000) {
            throw new InvalidArgumentException('Enter the title (up to 20,000 bytes). Edit the draft before posting.');
        }
        legislation_amendment_ids(['amendment_ids' => json_decode($draft['amendment_ids'] ?? '[]', true)], $id);
        if ($draft['record_id'] === null) {
            $termQuery = db()->prepare('SELECT * FROM committee_terms WHERE id=? FOR UPDATE');
            $termQuery->execute([$draft['term_id']]);
            $term = $termQuery->fetch();
            if (!$term || !term_contains_date($term, (string) $draft['approved_date'])) {
                throw new InvalidArgumentException('The approval date must be within the selected term. Edit the draft before posting.');
            }
            $duplicate = db()->prepare('SELECT id FROM legislation_publications WHERE term_id=? AND kind=? AND number=? AND (source_draft_id IS NULL OR source_draft_id<>?) FOR UPDATE');
            $duplicate->execute([$draft['term_id'], $draft['kind'], $draft['number'], $draft['id']]);
            if ($duplicate->fetch()) { throw new InvalidArgumentException('This document number is already posted for the selected term and type.'); }
        }
        if ($draft['stored_name'] !== '' && !is_file(dirname(__DIR__) . '/storage/elibrary/' . basename($draft['stored_name']))) {
            throw new InvalidArgumentException('The signed file is missing. Edit this draft and upload it again.');
        }
        $fields = $draft;
        if ($draft['record_id'] === null) { $fields['source_draft_id'] = $draft['id']; }
        unset($fields['id'], $fields['updated_at']);
        $fields['updated_by'] = current_user()['id'];
        $columns = array_keys($fields);
        $updates = implode(',', array_map(fn ($column) => "$column=VALUES($column)", $columns));
        $stmt = db()->prepare('INSERT INTO legislation_publications (' . implode(',', $columns) . ') VALUES (' . implode(',', array_fill(0, count($fields), '?')) . ') ON DUPLICATE KEY UPDATE ' . $updates . ', updated_at=CURRENT_TIMESTAMP');
        $stmt->execute(array_values($fields));
        audit_log('elibrary_publish', 'Posted ' . $draft['kind'] . ' ' . $draft['number'] . ' on the public E-Library.', $draft['record_id'] === null ? 'legislation_draft' : 'record', $draft['record_id'] === null ? $id : (int) $draft['record_id']);
        db()->commit();
        unset($_SESSION['elibrary_review'][$id]);
        flash('Legislation posted on the public E-Library.');
        redirect('/legislation.php?search=' . urlencode($draft['number']));
    } catch (Throwable $exception) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        $error = $exception instanceof InvalidArgumentException ? $exception->getMessage() : 'Unable to post legislation. Please try again.';
        error_log('E-Library post: ' . $exception->getMessage());
    }
}
$_SESSION['elibrary_review'][$id] = (int) $draft['revision'];
$historical = $draft['record_id'] === null;
$termName = '';
if ($draft['term_id']) {
    $termStmt = db()->prepare('SELECT name FROM committee_terms WHERE id=?');
    $termStmt->execute([$draft['term_id']]);
    $termName = (string) $termStmt->fetchColumn();
}
require __DIR__ . '/../app/partials/header.php';
?>
<section class="panel">
    <h1>Review Before Posting</h1>
    <p class="muted">Confirm the details and signed file below. Posting makes this entry available in public Search Legislation.</p>
    <?php if ($error): ?><div class="flash error" role="alert"><?= e($error) ?></div><?php endif; ?>
    <dl class="legislation-details">
        <?php if ($historical): ?><dt>Term</dt><dd><?= e($termName) ?></dd><dt>Committees</dt><dd><?= e($draft['committee_names'] ?: 'N/A') ?></dd><?php endif; ?>
        <?php foreach (['kind' => 'Type', 'number' => 'Number', 'title' => 'Title', 'approved_date' => 'Date Approved', 'keywords' => 'Keywords', 'category' => 'Category', 'author' => 'Author', 'co_author' => 'Co-Author', 'folder_code' => 'Folder Code'] as $field => $label): ?>
            <dt><?= e($label) ?></dt><dd><?php if ($field === 'keywords'): ?><?php foreach (legislation_keywords($draft[$field]) as $keyword): ?><span class="legislation-keyword"><?= e($keyword) ?></span><?php endforeach; ?><?php else: ?><?= e($draft[$field] ?: '—') ?><?php endif; ?></dd>
        <?php endforeach; ?>
        <dt>Amendment of</dt><dd><?php render_legislation_amendment_links(json_decode($draft['amendment_ids'] ?? '[]', true) ?: []); ?></dd>
        <dt>Final signed file</dt><dd><?php if ($draft['stored_name'] !== ''): ?><a href="<?= url('/legislation_file.php?draft=1&id=') . $id ?>" target="_blank" rel="noopener"><?= e($draft['original_name']) ?></a><?php else: ?>No copy attached.<?php endif; ?></dd>
    </dl>
    <form method="post" class="actions">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="revision" value="<?= (int) $draft['revision'] ?>">
        <a class="btn secondary" href="<?= $historical ? url('/e-library_old.php?edit=') . $id : url('/elibrary_form.php?') . e(http_build_query(['record_id' => $draft['record_id'], 'kind' => $draft['kind']])) ?>">Edit / Update Data</a>
        <button class="btn" type="submit">Confirm and Post</button>
        <a href="<?= $historical ? url('/e-library_old.php') : url('/dashboard.php?city_tab=approved-plenary') ?>"><?= $historical ? 'Back to Legislation' : 'Back to Plenary' ?></a>
    </form>
</section>
<?php require __DIR__ . '/../app/partials/footer.php'; ?>
