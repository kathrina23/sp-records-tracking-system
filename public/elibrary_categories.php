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
$editId = 0;
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && isset($_GET['edit'])) {
    $editId = (int) (filter_var($_GET['edit'], FILTER_VALIDATE_INT) ?: 0);
    $stmt = db()->prepare('SELECT id, kind, name FROM legislation_categories WHERE id=?');
    $stmt->execute([$editId]);
    $category = $stmt->fetch();
    if (!$category) {
        http_response_code(404);
        exit('Category not found.');
    }
    $name = $category['name'];
    $kind = $category['kind'];
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : 'create';
    $categoryId = (int) (filter_var($_POST['category_id'] ?? 0, FILTER_VALIDATE_INT) ?: 0);
    $name = is_string($_POST['name'] ?? null) ? trim($_POST['name']) : '';
    $kind = is_string($_POST['kind'] ?? null) ? $_POST['kind'] : '';
    $category = null;
    if (in_array($action, ['update', 'delete'], true)) {
        $stmt = db()->prepare('SELECT id, kind, name FROM legislation_categories WHERE id=?');
        $stmt->execute([$categoryId]);
        $category = $stmt->fetch();
        if (!$category) {
            http_response_code(404);
            exit('Category not found.');
        }
        $kind = $category['kind'];
        $editId = $action === 'update' ? $categoryId : 0;
    }
    if (!in_array($action, ['create', 'update', 'delete'], true)) {
        $error = 'Invalid category action.';
    } elseif ($action !== 'delete' && (!in_array($kind, ['ordinance', 'resolution'], true) || $name === '' || strlen($name) > 255)) {
        $error = 'Choose a legislation type and enter a category within 255 bytes.';
    } else {
        try {
            if ($action === 'delete') {
                $stmt = db()->prepare('DELETE FROM legislation_categories WHERE id=?');
                $stmt->execute([$categoryId]);
                audit_log('elibrary_category_delete', 'Deleted ' . $kind . ' category: ' . $category['name'] . '.', 'legislation_category', $categoryId);
                flash('Category deleted. Existing legislation entries keep their saved category.');
            } elseif ($action === 'update') {
                $stmt = db()->prepare('UPDATE legislation_categories SET name=? WHERE id=?');
                $stmt->execute([$name, $categoryId]);
                audit_log('elibrary_category_update', 'Renamed ' . $kind . ' category from ' . $category['name'] . ' to ' . $name . '.', 'legislation_category', $categoryId);
                flash('Category updated. Existing legislation entries keep their saved category.');
            } else {
                $stmt = db()->prepare('INSERT INTO legislation_categories (kind, name) VALUES (?, ?)');
                $stmt->execute([$kind, $name]);
                audit_log('elibrary_category_create', 'Added ' . $kind . ' category: ' . $name . '.', 'legislation_category', (int) db()->lastInsertId());
                flash('Category added.');
            }
            redirect('/elibrary_categories.php');
        } catch (PDOException $exception) {
            $error = $exception->getCode() === '23000' ? 'This category already exists for the selected legislation type.' : 'Unable to save category changes. Please try again.';
        }
    }
}
$lists = ['ordinance' => [], 'resolution' => []];
foreach (db()->query('SELECT id, kind, name FROM legislation_categories ORDER BY name')->fetchAll() as $category) {
    $lists[$category['kind']][] = $category;
}
require __DIR__ . '/../app/partials/header.php';
?>
<section class="panel">
    <h1>E-Library Data Entry</h1>
    <p class="muted">Add, edit or delete categories for the ordinance and resolution dropdowns in Post on E-Library. Existing legislation entries keep their saved category until edited and reposted.</p>
    <?php if ($error): ?><div class="flash error" role="alert"><?= e($error) ?></div><?php endif; ?>
    <form method="post" class="form-grid">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="<?= $editId ? 'update' : 'create' ?>">
        <input type="hidden" name="category_id" value="<?= $editId ?>">
        <?php if ($editId): ?>
        <h2>Edit Category</h2>
        <label>Legislation Type<input value="<?= e(ucfirst($kind)) ?>" readonly></label>
        <?php else: ?>
        <label>Legislation Type<select name="kind" required>
            <option value="ordinance" <?= $kind === 'ordinance' ? 'selected' : '' ?>>Ordinance</option>
            <option value="resolution" <?= $kind === 'resolution' ? 'selected' : '' ?>>Resolution</option>
        </select></label>
        <?php endif; ?>
        <label>Category Name<input name="name" value="<?= e($name) ?>" maxlength="255" required></label>
        <div class="actions"><button class="btn" type="submit"><?= $editId ? 'Save Category' : 'Add Category' ?></button><?php if ($editId): ?><a class="btn secondary" href="<?= url('/elibrary_categories.php') ?>">Cancel</a><?php endif; ?></div>
    </form>
</section>
<?php foreach ($lists as $type => $categories): ?>
<section class="panel">
    <h2><?= e(ucfirst($type)) ?> Categories</h2>
    <?php if (!$categories): ?><p class="muted">No categories added yet.</p><?php else: ?>
    <div class="table-wrap"><table>
        <thead><tr><th>Category</th><th>Actions</th></tr></thead>
        <tbody><?php foreach ($categories as $category): ?><tr>
            <td><?= e($category['name']) ?></td>
            <td><div class="actions">
                <a class="btn secondary" href="<?= url('/elibrary_categories.php?edit=') ?><?= (int) $category['id'] ?>">Edit</a>
                <form method="post" onsubmit="return confirm('Delete this category from posting choices? Existing legislation entries will keep their saved category.');">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="category_id" value="<?= (int) $category['id'] ?>">
                    <button class="btn danger" type="submit" aria-label="<?= e('Delete category ' . $category['name']) ?>">Delete</button>
                </form>
            </div></td>
        </tr><?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
</section>
<?php endforeach; ?>
<?php require __DIR__ . '/../app/partials/footer.php'; ?>
