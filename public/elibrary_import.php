<?php
require_once __DIR__ . '/../app/legislation_import.php';
require_elibrary_staff();
if (isset($_GET['template'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="legislation-import-template.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Type', 'Number', 'Title / Subject', 'Date Approved (MM/DD/YYYY)', 'Keywords', 'Category', 'Author', 'Co Author', 'Folder Code']);
    fclose($out); exit;
}
$terms = db()->query('SELECT id,name,start_year,start_month,end_year,end_month FROM committee_terms ORDER BY start_year DESC,id DESC')->fetchAll();
$errors = []; $entries = []; $termId = (int) ($_POST['term_id'] ?? 0); $success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        if (($_POST['action'] ?? '') === 'confirm') {
            $preview = $_SESSION['legislation_import'] ?? null;
            if (!$preview || !hash_equals($preview['token'], (string) ($_POST['preview_token'] ?? '')) || $preview['user_id'] !== (int) current_user()['id'] || $preview['expires'] < time()) {
                throw new InvalidArgumentException('The preview expired. Upload the workbook again.');
            }
            $termId = $preview['term_id'];
            $term = db()->prepare('SELECT id,name,start_year,start_month,end_year,end_month FROM committee_terms WHERE id=?');
            $term->execute([$termId]); $term = $term->fetch();
            if (!$term) { throw new InvalidArgumentException('The selected term no longer exists.'); }
            // Revalidate against current entries and metadata before inserting.
            $columns = array_keys($preview['entries'][0]);
            [$entries, $errors] = legislation_import_validate([$columns, ...array_map('array_values', $preview['entries'])], $term);
            if ($errors) { throw new InvalidArgumentException('The data changed since preview. Upload again to resolve: ' . implode(' ', $errors)); }
            db()->beginTransaction();
            foreach ($entries as $values) {
                $values += ['record_id' => null, 'term_id' => $termId, 'committee_names' => '', 'committee_ids' => '[]',
                    'original_name' => '', 'stored_name' => '', 'mime_type' => '', 'file_size' => 0, 'updated_by' => current_user()['id']];
                $save = db()->prepare('INSERT INTO legislation_drafts (' . implode(',', array_keys($values)) . ') VALUES (' . implode(',', array_fill(0, count($values), '?')) . ')');
                $save->execute(array_values($values));
                audit_log('elibrary_historical_import', 'Imported ' . $values['kind'] . ' ' . $values['number'] . ' from Excel.', 'legislation_draft', (int) db()->lastInsertId());
            }
            db()->commit();
            unset($_SESSION['legislation_import']);
            $success = count($entries) . ' entries imported as drafts. Open Legislation Entries and choose Edit Details to add or change record information. You can upload PDF copies separately later.';
            $entries = [];
        } else {
            unset($_SESSION['legislation_import']);
            $term = null;
            foreach ($terms as $option) { if ((int) $option['id'] === $termId) { $term = $option; break; } }
            if (!$term) { throw new InvalidArgumentException('Select the term for these entries.'); }
            $file = $_FILES['workbook'] ?? [];
            $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '') || ($file['size'] ?? 0) > 5 * 1024 * 1024 || !in_array($extension, ['xlsx', 'csv'], true)) {
                throw new InvalidArgumentException('Upload an .xlsx or UTF-8 CSV file up to 5 MB (subject to the server upload limit). Save older .xls files as .xlsx first.');
            }
            [$entries, $errors] = legislation_import_validate(legislation_excel_rows($file['tmp_name'], $extension), $term);
            if (!$errors) {
                $_SESSION['legislation_import'] = ['entries' => $entries, 'term_id' => $termId, 'user_id' => (int) current_user()['id'], 'expires' => time() + 1800, 'token' => bin2hex(random_bytes(24))];
            }
        }
    } catch (Throwable $error) {
        if (db()->inTransaction()) { db()->rollBack(); }
        $errors = [$error instanceof InvalidArgumentException ? $error->getMessage() : legislation_import_failure_message($error)];
        error_log('Legislation import: ' . get_class($error) . ': ' . $error->getMessage() . ' in ' . $error->getFile() . ':' . $error->getLine());
    }
}
require __DIR__ . '/../app/partials/header.php';
?>
<section class="panel">
    <h1>Bulk Import Ordinances &amp; Resolutions</h1>
    <p>Upload an Excel workbook with one legislation entry per row. The first visible worksheet is read, with column headers in row 1. Up to 1,000 entries per upload.</p>
    <p><a class="btn secondary" href="<?= url('/elibrary_import.php?template=1') ?>">Download Excel-ready CSV Template</a> <a href="<?= url('/e-library_old.php#legislation-entries') ?>">Legislation Entries / Edit Details</a></p>
    <p class="muted">Required columns: Type, Number, Title / Subject and Date Approved. Use MM/DD/YYYY for dates (for example, 08/17/2026). Excel date cells are also accepted. Keep document numbers as text to preserve leading zeros. Optional columns: Keywords, Category, Author, Co Author and Folder Code. Categories must match saved categories; authors must match the selected term's councilors (separate names with semicolons). Blank metadata can be completed later using Edit. PDFs can be uploaded later. Imports remain drafts until reviewed and posted.</p>
    <?php if ($success): ?><div class="flash success" role="status"><?= e($success) ?></div><?php endif; ?>
    <?php if ($errors): ?><div class="flash error" role="alert"><p>No entries saved. Correct these issues and upload again:</p><ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
    <form method="post" enctype="multipart/form-data" class="form-grid">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <label>Term (Month and Year)<select name="term_id" required><option value="">Select term</option><?php foreach ($terms as $term): ?><option value="<?= (int) $term['id'] ?>" <?= $termId === (int) $term['id'] ? 'selected' : '' ?>><?= e(term_option_label($term)) ?></option><?php endforeach; ?></select></label>
        <label>Excel file<input type="file" name="workbook" accept=".xlsx,.csv" required></label>
        <div class="actions full"><button class="btn">Upload and Preview</button></div>
    </form>
</section>
<?php if ($entries && !$errors): ?>
<section class="panel"><h2>Preview <?= count($entries) ?> Entries</h2>
    <p>Confirm to save all rows as drafts without PDF copies. Existing legislation is never overwritten.</p>
    <div class="table-wrap"><table><thead><tr><th>Type</th><th>Number</th><th>Title</th><th>Date Approved</th><th>Keywords</th><th>Category</th><th>Author</th><th>Co Author</th><th>Folder Code</th></tr></thead><tbody>
    <?php foreach ($entries as $entry): ?><tr><?php foreach ($entry as $field => $value): ?><td><?= e($field === 'approved_date' ? display_date($value) : $value) ?></td><?php endforeach; ?></tr><?php endforeach; ?>
    </tbody></table></div>
    <form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="confirm"><input type="hidden" name="preview_token" value="<?= e($_SESSION['legislation_import']['token']) ?>"><button class="btn">Confirm Import <?= count($entries) ?> Drafts</button></form>
</section>
<?php endif; ?>
<?php require __DIR__ . '/../app/partials/footer.php'; ?>
