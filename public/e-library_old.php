<?php
require_once __DIR__ . '/../app/elibrary.php';
require_elibrary_staff();
$id = (int) ($_GET['edit'] ?? 0);
$draft = [];
if ($id) {
    $stmt = db()->prepare('SELECT * FROM legislation_drafts WHERE id=? AND record_id IS NULL');
    $stmt->execute([$id]);
    $draft = $stmt->fetch();
    if (!$draft) { http_response_code(404); exit('Legislation draft not found.'); }
}
$terms = db()->query('SELECT id,name,start_year,start_month,end_year,end_month FROM committee_terms ORDER BY start_year DESC,id DESC')->fetchAll();
$termOptions = [];
foreach ($terms as $term) {
    $stmt = db()->prepare("SELECT DISTINCT name FROM city_officials WHERE term_id=? AND position='City Councilor' ORDER BY name");
    $stmt->execute([$term['id']]);
    $names = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $stmt = db()->prepare('SELECT DISTINCT c.id,c.name FROM committees c INNER JOIN committee_members m ON m.committee_id=c.id WHERE m.term_id=? ORDER BY c.name');
    $stmt->execute([$term['id']]);
    $termOptions[$term['id']] = ['councilors' => $names, 'committees' => $stmt->fetchAll()];
}
$categories = ['ordinance' => legislation_categories('ordinance'), 'resolution' => legislation_categories('resolution')];
$values = $draft;
$selectedAuthors = $draft ? explode('; ', $draft['author']) : [];
$selectedCoAuthors = $draft && $draft['co_author'] !== '' ? explode('; ', $draft['co_author']) : [];
$selectedCommittees = $draft ? (json_decode($draft['committee_ids'] ?? '[]', true) ?: []) : [];
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $values = [];
    foreach (['kind', 'number', 'title', 'approved_date', 'keywords', 'category', 'folder_code'] as $field) {
        $values[$field] = is_string($_POST[$field] ?? null) ? trim($_POST[$field]) : '';
    }
    $values['term_id'] = (int) ($_POST['term_id'] ?? 0);
    $values['amendment_ids'] = json_encode($_POST['amendment_ids'] ?? []);
    $selectedAuthors = is_array($_POST['authors'] ?? null) ? $_POST['authors'] : [];
    $selectedCoAuthors = is_array($_POST['co_authors'] ?? null) ? $_POST['co_authors'] : [];
    $selectedCommittees = is_array($_POST['committee_ids'] ?? null) ? $_POST['committee_ids'] : [];
    $newFile = null;
    try {
        $term = null;
        foreach ($terms as $option) { if ((int) $option['id'] === $values['term_id']) { $term = $option; break; } }
        if (!$term || !in_array($values['kind'], ['ordinance', 'resolution'], true)) {
            throw new InvalidArgumentException('Choose a term and document type.');
        }
        if (!term_contains_date($term, $values['approved_date'])) {
            throw new InvalidArgumentException('Enter a valid approval date within the selected term.');
        }
        if ($values['number'] === '' || strlen($values['number']) > 255 || $values['title'] === '' || strlen($values['title']) > 20000) {
            throw new InvalidArgumentException('Enter the document number (up to 255 bytes) and title (up to 20,000 bytes).');
        }
        $allowed = array_merge(['N/A'], $termOptions[$values['term_id']]['councilors']);
        foreach ([$selectedAuthors, $selectedCoAuthors] as $selection) {
            foreach ($selection as $name) {
                if (!is_string($name) || !in_array($name, $allowed, true)) { throw new InvalidArgumentException('Choose authors and co-authors from the selected term.'); }
            }
            if (in_array('N/A', $selection, true) && count($selection) > 1) { throw new InvalidArgumentException('Choose N/A alone when no author applies.'); }
        }
        $values['author'] = implode('; ', array_unique($selectedAuthors));
        $values['co_author'] = implode('; ', array_unique($selectedCoAuthors));
        $fields = legislation_fields($values, $values['kind']);
        $values['amendment_ids'] = legislation_amendment_ids($_POST, $id);
        $fields['amendment_ids'] = $values['amendment_ids'];
        $committeeNames = [];
        $committeeIds = [];
        foreach ($selectedCommittees as $committeeId) {
            if (!is_scalar($committeeId)) { throw new InvalidArgumentException('Invalid committee selection.'); }
            $match = null;
            foreach ($termOptions[$values['term_id']]['committees'] as $committee) { if ((string) $committee['id'] === (string) $committeeId) { $match = $committee; break; } }
            if (!$match) { throw new InvalidArgumentException('Choose committees with membership in the selected term.'); }
            $committeeNames[(int) $match['id']] = $match['name'];
            $committeeIds[(int) $match['id']] = (int) $match['id'];
        }
        if ((int) ($_POST['revision'] ?? 0) !== (int) ($draft['revision'] ?? 0)) { throw new InvalidArgumentException('This draft changed. Reload before saving.'); }
        $file = $draft ?: ['original_name' => '', 'stored_name' => '', 'mime_type' => '', 'file_size' => 0];
        if (($_FILES['signed_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) { $newFile = legislation_upload($_FILES['signed_file']); $file = $newFile; }
        $fields += ['record_id' => null, 'term_id' => $values['term_id'], 'kind' => $values['kind'], 'number' => $values['number'], 'title' => $values['title'],
            'approved_date' => $values['approved_date'], 'committee_names' => implode('; ', $committeeNames), 'committee_ids' => json_encode(array_values($committeeIds)), 'updated_by' => current_user()['id']];
        foreach (['original_name', 'stored_name', 'mime_type', 'file_size'] as $field) { $fields[$field] = $file[$field]; }
        db()->beginTransaction();
        if ($draft) {
            $assignments = implode(',', array_map(fn ($field) => "$field=?", array_keys($fields)));
            $save = db()->prepare("UPDATE legislation_drafts SET $assignments,revision=revision+1 WHERE id=? AND revision=?");
            $save->execute([...array_values($fields), $id, $draft['revision']]);
            if ($save->rowCount() !== 1) { throw new InvalidArgumentException('Another staff member changed this draft. Reload before saving.'); }
        } else {
            $save = db()->prepare('INSERT INTO legislation_drafts (' . implode(',', array_keys($fields)) . ') VALUES (' . implode(',', array_fill(0, count($fields), '?')) . ')');
            $save->execute(array_values($fields));
            $id = (int) db()->lastInsertId();
        }
        audit_log('elibrary_historical_save', 'Saved ' . $fields['kind'] . ' ' . $fields['number'] . '.', 'legislation_draft', $id);
        db()->commit();
        redirect('/elibrary_review.php?id=' . $id);
    } catch (Throwable $exception) {
        if (db()->inTransaction()) { db()->rollBack(); }
        if ($newFile) { unlink(dirname(__DIR__) . '/storage/elibrary/' . $newFile['stored_name']); }
        $error = $exception instanceof InvalidArgumentException ? $exception->getMessage() : ($exception instanceof PDOException && $exception->getCode() === '23000' ? 'This document number already exists for this type and term.' : 'Unable to save the legislation.');
        error_log('Legislation: ' . $exception->getMessage());
    }
}
$selectedTermId = (int) ($values['term_id'] ?? 0);
$kind = $values['kind'] ?? '';
$options = $termOptions[$selectedTermId] ?? ['councilors' => [], 'committees' => []];
$renderChoices = static function (string $id, string $name, string $legend, array $choices, array $selected): void {
    ?>
    <fieldset class="historical-choice-group"><legend><?= e($legend) ?></legend>
        <input type="search" class="historical-choice-search" placeholder="Search choices…" aria-label="Search <?= e($legend) ?>" data-choices="<?= e($id) ?>">
        <div class="historical-choices" id="<?= e($id) ?>" data-name="<?= e($name) ?>">
            <?php foreach ($choices as $value => $label): ?><label><input type="checkbox" name="<?= e($name) ?>[]" value="<?= e($value) ?>" <?= in_array((string) $value, array_map('strval', $selected), true) ? 'checked' : '' ?>><span><?= e($label) ?></span></label><?php endforeach; ?>
            <?php if (!$choices): ?><p class="muted">No choices available for this term.</p><?php endif; ?>
        </div>
    </fieldset>
    <?php
};
$history = db()->query('SELECT d.*, p.id publication_id, p.revision published_revision FROM legislation_drafts d LEFT JOIN legislation_publications p ON p.source_draft_id=d.id WHERE d.record_id IS NULL ORDER BY d.updated_at DESC,d.id DESC')->fetchAll();
require __DIR__ . '/../app/partials/header.php';
?>
<section class="panel historical-legislation">
    <header class="legislation-editor-header">
        <div><p class="legislation-editor-eyebrow">E-Library / Legislation</p><h1><?= $draft ? 'Edit Legislation' : 'Ordinances &amp; Resolutions' ?></h1><p class="muted">Complete the document details, then review before publishing.</p></div>
        <a class="btn secondary" href="<?= url('/elibrary_import.php') ?>">Bulk Import from Excel</a>
    </header>
    <?php if ($error): ?><div class="flash error" role="alert"><?= e($error) ?></div><?php endif; ?>
    <form method="post" enctype="multipart/form-data" id="historical-legislation-form" class="legislation-editor-form">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="revision" value="<?= (int) ($draft['revision'] ?? 0) ?>">
        <section class="legislation-editor-section" aria-labelledby="legislation-document-heading">
        <header><h2 id="legislation-document-heading">Document details</h2><p>Identify the legislation and its approval.</p></header>
        <div class="legislation-editor-grid">
        <label>Term (Month and Year)<select name="term_id" id="historical-term" required><option value="">Select term</option><?php foreach ($terms as $term): ?><option value="<?= (int) $term['id'] ?>" <?= $selectedTermId === (int) $term['id'] ? 'selected' : '' ?>><?= e(term_option_label($term)) ?></option><?php endforeach; ?></select></label>
        <label>Document Type<select name="kind" id="historical-kind" required><option value="">Select document type</option><option value="ordinance" <?= $kind === 'ordinance' ? 'selected' : '' ?>>Ordinance</option><option value="resolution" <?= $kind === 'resolution' ? 'selected' : '' ?>>Resolution</option></select></label>
        <label>Document Number<input name="number" value="<?= e($values['number'] ?? '') ?>" maxlength="255" required></label>
        <label>Date Approved<input type="date" name="approved_date" value="<?= e($values['approved_date'] ?? '') ?>" required></label>
        <label class="full">Title / Subject<textarea name="title" rows="3" maxlength="20000" required><?= e($values['title'] ?? '') ?></textarea></label>
        </div></section>
        <section class="legislation-editor-section" aria-labelledby="legislation-classification-heading">
        <header><h2 id="legislation-classification-heading">Classification</h2><p>Add a category and keywords to help people find this document.</p></header>
        <div class="legislation-editor-grid">
        <label>Keywords<input name="keywords" value="<?= e($values['keywords'] ?? '') ?>" maxlength="1000" placeholder="Separate keywords with commas" required></label>
        <label>Category<select name="category" id="historical-category" required><option value="">Select category</option><?php foreach ($categories[$kind] ?? [] as $category): ?><option value="<?= e($category) ?>" <?= ($values['category'] ?? '') === $category ? 'selected' : '' ?>><?= e($category) ?></option><?php endforeach; ?></select></label>
        </div></section>
        <section class="legislation-editor-section" aria-labelledby="legislation-authorship-heading">
        <header><h2 id="legislation-authorship-heading">Authorship</h2><p>Select all applicable names. Choose N/A alone when no author applies.</p></header>
        <div class="legislation-editor-grid">
        <?php $nameChoices = array_combine(array_merge(['N/A'], $options['councilors']), array_merge(['N/A'], $options['councilors'])); ?>
        <?php $renderChoices('historical-authors', 'authors', 'Author(s)', $nameChoices, $selectedAuthors); ?>
        <?php $renderChoices('historical-co-authors', 'co_authors', 'Co-Author(s) (optional)', $nameChoices, $selectedCoAuthors); ?>
        </div></section>
        <section class="legislation-editor-section" aria-labelledby="legislation-record-heading">
        <header><h2 id="legislation-record-heading">Committee &amp; supporting copy</h2><p>These details are optional. You can attach the signed copy later.</p></header>
        <div class="legislation-editor-grid legislation-support-grid">
        <?php $renderChoices('historical-committees', 'committee_ids', 'Committee(s) (optional)', array_column($options['committees'], 'name', 'id'), $selectedCommittees); ?>
        <div class="legislation-support-fields">
        <label>Folder Code (optional)<input name="folder_code" maxlength="255" value="<?= e($values['folder_code'] ?? '') ?>"></label>
        <label class="legislation-copy-field">Final signed copy (optional)<input type="file" name="signed_file" accept="application/pdf,image/jpeg,image/png"><span class="muted">PDF, JPEG or PNG, up to 10 MB.</span><?php if (!empty($draft['stored_name'])): ?><span>Current copy: <a href="<?= url('/legislation_file.php?draft=1&id=') . $id ?>" target="_blank" rel="noopener"><?= e($draft['original_name']) ?></a>. Leave empty to keep it.</span><?php endif; ?></label>
        </div></div></section>
        <section class="legislation-editor-section"><?php render_legislation_amendment_selector($values, $id); ?></section>
        <div class="legislation-editor-footer"><p>Your changes are saved for review before they appear in public search.</p><div class="actions"><a class="btn secondary" href="<?= url('/e-library_old.php#legislation-entries') ?>">Back to Entries</a><button class="btn" type="submit"><?= $draft ? 'Save Details and Review' : 'Post — Review Data' ?></button></div></div>
    </form>
</section>
<section class="panel legislation-entries-panel" id="legislation-entries">
    <header class="legislation-entries-header"><div><h2>Legislation Entries <span class="legislation-entry-count"><?= count($history) ?></span></h2><p>Edit details, attach a signed copy, or review an entry for posting.</p></div></header>
    <div class="table-wrap"><table class="legislation-entries-table"><colgroup><col class="entry-type-col"><col class="entry-number-col"><col><col class="entry-date-col"><col class="entry-status-col"><col class="entry-actions-col"></colgroup><thead><tr><th scope="col">Type</th><th scope="col">Number</th><th scope="col">Title / Subject</th><th scope="col">Approved</th><th scope="col">Status</th><th scope="col">Actions</th></tr></thead><tbody>
    <?php foreach ($history as $item): ?>
        <?php $entryStatus = !$item['publication_id'] ? 'draft' : ((int) $item['published_revision'] === (int) $item['revision'] ? 'posted' : 'pending'); ?>
        <tr>
            <td><span class="legislation-entry-type"><?= e(ucfirst($item['kind'])) ?></span></td>
            <td class="legislation-entry-number"><?= e($item['number']) ?></td>
            <td class="legislation-entry-title"><?= e($item['title']) ?></td>
            <td class="legislation-entry-date"><?= e(display_date($item['approved_date'])) ?></td>
            <td><span class="legislation-entry-status <?= e($entryStatus) ?>"><?= ['draft' => 'Draft', 'posted' => 'Posted', 'pending' => 'Awaiting review'][$entryStatus] ?></span></td>
            <td><div class="legislation-entry-actions">
                <a class="legislation-entry-action primary" href="<?= url('/e-library_old.php?edit=') . (int) $item['id'] . '#historical-legislation-form' ?>">Edit Details</a>
                <a class="legislation-entry-action" href="<?= url('/elibrary_upload_copy.php?id=') . (int) $item['id'] ?>"><?= $item['stored_name'] ? 'Replace PDF' : 'Upload PDF' ?></a>
                <a class="legislation-entry-action review" href="<?= url('/elibrary_review.php?id=') . (int) $item['id'] ?>">Review</a>
            </div></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$history): ?><tr><td colspan="6" class="legislation-entries-empty">No legislation entered yet. Add an entry above or import an Excel file to get started.</td></tr><?php endif; ?>
    </tbody></table></div>
</section>
<script type="application/json" id="historical-legislation-options"><?= json_encode(['terms' => $termOptions, 'categories' => $categories], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script src="<?= url('/assets/historical-legislation.js') ?>"></script>
<?php require __DIR__ . '/../app/partials/footer.php'; ?>
