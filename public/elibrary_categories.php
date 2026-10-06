<?php
require_once __DIR__ . '/../app/elibrary.php';
require_login();
if (!can_manage_elibrary_data()) {
    http_response_code(403);
    exit('This page is for authorized E-Library Data Entry users only.');
}
$error = '';
$name = '';
$kind = 'ordinance';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $name = is_string($_POST['name'] ?? null) ? trim($_POST['name']) : '';
    $kind = is_string($_POST['kind'] ?? null) ? $_POST['kind'] : '';
    if (!in_array($kind, ['ordinance', 'resolution'], true) || $name === '' || strlen($name) > 255) {
        $error = 'Choose a legislation type and enter a category within 255 bytes.';
    } else {
        try {
            $stmt = db()->prepare('INSERT INTO legislation_categories (kind, name) VALUES (?, ?)');
            $stmt->execute([$kind, $name]);
            audit_log('elibrary_category_create', 'Added ' . $kind . ' category: ' . $name . '.', 'legislation_category', (int) db()->lastInsertId());
            flash('Category added.');
            redirect('/elibrary_categories.php');
        } catch (PDOException $exception) {
            $error = $exception->getCode() === '23000' ? 'This category already exists for the selected legislation type.' : 'Unable to add category. Please try again.';
        }
    }
}
$lists = ['ordinance' => legislation_categories('ordinance'), 'resolution' => legislation_categories('resolution')];
require __DIR__ . '/../app/partials/header.php';
?>
<section class="panel">
    <h1>E-Library Data Entry</h1>
    <p class="muted">Add categories for the ordinance and resolution dropdowns in Post on E-Library.</p>
    <?php if ($error): ?><div class="flash error" role="alert"><?= e($error) ?></div><?php endif; ?>
    <form method="post" class="form-grid">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <label>Legislation Type<select name="kind" required>
            <option value="ordinance" <?= $kind === 'ordinance' ? 'selected' : '' ?>>Ordinance</option>
            <option value="resolution" <?= $kind === 'resolution' ? 'selected' : '' ?>>Resolution</option>
        </select></label>
        <label>Category Name<input name="name" value="<?= e($name) ?>" maxlength="255" required></label>
        <div class="actions"><button class="btn" type="submit">Add Category</button></div>
    </form>
</section>
<?php foreach ($lists as $type => $categories): ?>
<section class="panel">
    <h2><?= e(ucfirst($type)) ?> Categories</h2>
    <?php if (!$categories): ?><p class="muted">No categories added yet.</p><?php else: ?>
    <ul><?php foreach ($categories as $category): ?><li><?= e($category) ?></li><?php endforeach; ?></ul>
    <?php endif; ?>
</section>
<?php endforeach; ?>
<?php require __DIR__ . '/../app/partials/footer.php'; ?>
