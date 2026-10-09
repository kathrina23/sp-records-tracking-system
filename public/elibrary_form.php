<?php
require_once __DIR__ . '/../app/elibrary.php';
require_elibrary_staff();
$recordId = (int) ($_GET['record_id'] ?? 0);
$stmt = db()->prepare('SELECT * FROM records WHERE id=?');
$stmt->execute([$recordId]);
$record = $stmt->fetch();
if (!$record || !can_view_record($record)) {
    http_response_code(404);
    exit('Approved legislation not found.');
}
$kinds = array_values(array_filter(['ordinance', 'resolution'], fn ($kind) => legislation_number($record, $kind) !== ''));
if (!$kinds) {
    http_response_code(409);
    exit('An approved ordinance or resolution number must be assigned before posting.');
}
$kind = (string) ($_GET['kind'] ?? $kinds[0]);
if (!in_array($kind, $kinds, true)) {
    http_response_code(400);
    exit('Invalid legislation type.');
}
$stmt = db()->prepare('SELECT * FROM legislation_drafts WHERE record_id=? AND kind=?');
$stmt->execute([$recordId, $kind]);
$draft = $stmt->fetch() ?: [];
$values = $draft;
$values['title'] = $draft['title'] ?? $record['title'];
$categories = legislation_categories($kind);
$councilors = db()->query("SELECT DISTINCT name FROM city_officials WHERE position='City Councilor' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $newFile = null;
    foreach (['title', 'keywords', 'category', 'author', 'co_author', 'folder_code'] as $field) {
        $values[$field] = is_string($_POST[$field] ?? null) ? $_POST[$field] : '';
    }
    $values['amendment_ids'] = json_encode($_POST['amendment_ids'] ?? []);
    try {
        $title = trim($values['title']);
        if ($title === '' || strlen($title) > 20000) {
            throw new InvalidArgumentException('Enter the title (up to 20,000 bytes).');
        }
        $values = legislation_fields($_POST, $kind);
        $values['title'] = $title;
        $values['amendment_ids'] = legislation_amendment_ids($_POST, (int) ($draft['id'] ?? 0));
        $revision = (int) ($_POST['revision'] ?? 0);
        if ($revision !== (int) ($draft['revision'] ?? 0)) {
            throw new InvalidArgumentException('This draft changed. Reload the page before saving.');
        }
        $file = $draft ?: ['original_name' => '', 'stored_name' => '', 'mime_type' => '', 'file_size' => 0];
        if (($_FILES['signed_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $newFile = legislation_upload($_FILES['signed_file']);
            $file = $newFile;
        }
        $fields = ['number' => legislation_number($record, $kind),
            'approved_date' => $record['plenary_approved_date'] ?: null] + $values;
        foreach (['original_name', 'stored_name', 'mime_type', 'file_size'] as $field) {
            $fields[$field] = $file[$field];
        }
        $fields['updated_by'] = current_user()['id'];
        if ($draft) {
            $assignments = implode(',', array_map(fn ($field) => "$field=?", array_keys($fields)));
            $save = db()->prepare("UPDATE legislation_drafts SET $assignments, revision=revision+1 WHERE id=? AND revision=?");
            $save->execute([...array_values($fields), $draft['id'], $revision]);
            if ($save->rowCount() !== 1) {
                throw new InvalidArgumentException('Another staff member updated this draft. Reload before saving.');
            }
            $id = (int) $draft['id'];
        } else {
            $fields = ['record_id' => $recordId, 'kind' => $kind] + $fields;
            $save = db()->prepare('INSERT INTO legislation_drafts (' . implode(',', array_keys($fields)) . ') VALUES (' . implode(',', array_fill(0, count($fields), '?')) . ')');
            $save->execute(array_values($fields));
            $id = (int) db()->lastInsertId();
        }
        audit_log('elibrary_draft_save', 'Saved E-Library draft for ' . $kind . ' ' . $fields['number'] . '.', 'record', $recordId);
        redirect('/elibrary_review.php?id=' . $id);
    } catch (Throwable $exception) {
        if ($newFile && isset($save) && !isset($id)) {
            unlink(dirname(__DIR__) . '/storage/elibrary/' . $newFile['stored_name']);
        }
        $error = $exception instanceof InvalidArgumentException ? $exception->getMessage() : 'Unable to save the draft. Please try again.';
        error_log('E-Library save: ' . $exception->getMessage());
    }
}
require __DIR__ . '/../app/partials/header.php';
?>
<section class="panel">
    <div class="page-head"><div><h1>Post on E-Library</h1><p><?= e(ucfirst($kind) . ' ' . legislation_number($record, $kind)) ?></p></div><a class="btn secondary" href="<?= url('/dashboard.php?city_tab=approved-plenary') ?>">Back to Plenary</a></div>
    <?php if (count($kinds) > 1): ?><div class="actions"><?php foreach ($kinds as $option): ?><a class="btn secondary" href="<?= url('/elibrary_form.php?') . e(http_build_query(['record_id' => $recordId, 'kind' => $option])) ?>"><?= e(ucfirst($option)) ?></a><?php endforeach; ?></div><?php endif; ?>
    <?php if ($error): ?><div class="flash error" role="alert"><?= e($error) ?></div><?php endif; ?>
    <p class="muted">Save your details, review them, and confirm posting to make this legislation publicly searchable. Co-Author and Folder Code are optional.</p>
    <form method="post" enctype="multipart/form-data" class="form-grid">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="revision" value="<?= (int) ($draft['revision'] ?? 0) ?>">
        <label class="full">Title / Subject<textarea name="title" rows="10" style="min-height:240px;resize:vertical;" maxlength="20000" required><?= e($values['title']) ?></textarea>
            <span class="muted">This title is used for the E-Library posting. The tracked record title stays unchanged.</span>
        </label>
        <label>Keywords<input name="keywords" value="<?= e($values['keywords'] ?? '') ?>" maxlength="1000" placeholder="Example: health, public services, transport" required>
            <span class="muted">Separate each keyword or phrase with a comma. Each item is saved as a separate keyword.</span>
        </label>
        <label>Category<select name="category" required>
            <option value="">Select <?= e($kind) ?> category</option>
            <?php foreach ($categories as $category): ?><option value="<?= e($category) ?>" <?= ($values['category'] ?? '') === $category ? 'selected' : '' ?>><?= e($category) ?></option><?php endforeach; ?>
        </select><?php if (!$categories): ?><span class="muted">No categories have been added for <?= e($kind) ?>s. Add them in <a href="<?= url('/elibrary_categories.php') ?>">E-Library Data Entry</a>.</span><?php endif; ?></label>
        <?php foreach (['author' => 'Author', 'co_author' => 'Co-Author', 'folder_code' => 'Folder Code'] as $field => $label): ?>
            <label><?= e($label) ?><?= $field === 'folder_code' ? ' (optional)' : '' ?><input name="<?= e($field) ?>" value="<?= e($values[$field] ?? '') ?>" maxlength="<?= $field === 'folder_code' ? 255 : 1000 ?>" <?= $field !== 'folder_code' ? 'list="councilor-names"' : '' ?> <?= $field === 'author' ? 'required' : '' ?>></label>
        <?php endforeach; ?>
        <datalist id="councilor-names"><option value="N/A"></option><?php foreach ($councilors as $name): ?><option value="<?= e($name) ?>"></option><?php endforeach; ?></datalist>
        <label>Final signed Ordinance / Resolution (optional)<input type="file" name="signed_file" accept="application/pdf,image/jpeg,image/png">
            <span class="muted">PDF, JPEG or PNG, up to 10 MB (server upload limit: <?= e(ini_get('upload_max_filesize')) ?>).</span>
            <?php if (!empty($draft['stored_name'])): ?><span>Current file: <a href="<?= url('/legislation_file.php?draft=1&id=') . (int) $draft['id'] ?>" target="_blank" rel="noopener"><?= e($draft['original_name']) ?></a>. Leave empty to keep it.</span><?php endif; ?>
        </label>
        <?php render_legislation_amendment_selector($values, (int) ($draft['id'] ?? 0)); ?>
        <div class="actions"><button class="btn" type="submit">Post — Review Data</button></div>
    </form>
</section>
<?php require __DIR__ . '/../app/partials/footer.php'; ?>
