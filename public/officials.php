<?php

require_once __DIR__ . '/../app/auth.php';
require_login();
require_management_access();

try {
    db()->query('SELECT 1 FROM city_officials LIMIT 1');
} catch (Throwable $error) {
    require __DIR__ . '/../app/partials/header.php';
    ?>
    <section class="panel">
        <h1>Officials</h1>
        <p class="muted">Import <strong>database/migration_city_officials.sql</strong> and <strong>database/migration_term_officers.sql</strong> in phpMyAdmin to enable term officials and officers.</p>
    </section>
    <?php
    require __DIR__ . '/../app/partials/footer.php';
    exit;
}

$electedPositions = [
    'Vice Mayor',
    'City Councilor',
];
$officerRoles = [
    'Presiding Officer',
    'Presiding Officer Pro-Tempore',
    'Majority Floor Leader',
    'Assistant Majority Floor Leader',
    'Minority Floor Leader',
    'Assistant Minority Floor Leader',
    'SK Federation President',
    'IPMR Representative',
    'Association of Barangay Captain President',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!is_admin()) {
        http_response_code(403);
        exit('Only authorized management users can change officials.');
    }

    verify_csrf();
    $action = $_POST['action'] ?? 'save';
    $id = (int) ($_POST['id'] ?? 0);

    if ($action === 'delete') {
        $stmt = db()->prepare('DELETE FROM city_officials WHERE id = ?');
        $stmt->execute([$id]);
        audit_log('city_official_delete', 'Deleted city official #' . $id . '.', 'city_official', $id);
        flash('Official deleted.');
        redirect('/officials.php');
    }

    $termId = (int) ($_POST['term_id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $position = $_POST['position'] ?? 'City Councilor';
    $officerRole = trim($_POST['officer_role'] ?? '');
    $officerRole = $officerRole !== '' ? $officerRole : null;
    $sortOrder = (int) ($_POST['sort_order'] ?? 0);

    if (!in_array($position, $electedPositions, true) || ($officerRole !== null && !in_array($officerRole, $officerRoles, true)) || !$termId || $name === '') {
        flash('Please complete the official details.', 'error');
        redirect('/officials.php');
    }

    if ($id) {
        $stmt = db()->prepare('UPDATE city_officials SET term_id=?, name=?, position=?, officer_role=?, sort_order=? WHERE id=?');
        $stmt->execute([$termId, $name, $position, $officerRole, $sortOrder, $id]);
        audit_log('city_official_update', 'Updated official ' . $name . '.', 'city_official', $id);
        flash('Official updated.');
    } else {
        $stmt = db()->prepare('INSERT INTO city_officials (term_id, name, position, officer_role, sort_order) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$termId, $name, $position, $officerRole, $sortOrder]);
        audit_log('city_official_create', 'Created official ' . $name . '.', 'city_official', (int) db()->lastInsertId());
        flash('Official added.');
    }

    redirect('/officials.php');
}

$edit = null;
if (isset($_GET['edit'])) {
    $stmt = db()->prepare('SELECT * FROM city_officials WHERE id = ?');
    $stmt->execute([(int) $_GET['edit']]);
    $edit = $stmt->fetch();
}

$terms = db()->query('SELECT id, name, is_current FROM committee_terms ORDER BY start_year DESC, id DESC')->fetchAll();
$selectedTermId = (int) ($_GET['term_id'] ?? ($edit['term_id'] ?? 0));
if (!$selectedTermId) {
    foreach ($terms as $term) {
        if ((int) $term['is_current'] === 1) {
            $selectedTermId = (int) $term['id'];
            break;
        }
    }
}
if (!$selectedTermId && $terms) {
    $selectedTermId = (int) $terms[0]['id'];
}

$stmt = db()->prepare('SELECT o.*, t.name term_name FROM city_officials o INNER JOIN committee_terms t ON t.id = o.term_id WHERE o.term_id = ? ORDER BY o.name, o.sort_order');
$stmt->execute([$selectedTermId]);
$officials = $stmt->fetchAll();

require __DIR__ . '/../app/partials/header.php';
?>
<div class="page-head">
    <div>
        <h1>Officials</h1>
        <p class="muted">Manage the Vice Mayor and City Councilors, then assign optional officer roles elected among them.</p>
    </div>
</div>

<section class="panel" style="margin-bottom:16px;">
    <form method="get" class="filters" style="grid-template-columns: 1fr auto;">
        <label>Term
            <select name="term_id">
                <?php foreach ($terms as $term): ?>
                    <option value="<?= (int) $term['id'] ?>" <?= $selectedTermId === (int) $term['id'] ? 'selected' : '' ?>><?= e($term['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <button class="btn secondary" type="submit">View Term</button>
    </form>
</section>

<?php if (is_admin()): ?>
    <section class="panel" style="margin-bottom:16px;">
        <h2><?= $edit ? 'Edit Official' : 'Add Official' ?></h2>
        <form method="post" class="form-grid">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
            <label>Term
                <select name="term_id" required>
                    <?php foreach ($terms as $term): ?>
                        <option value="<?= (int) $term['id'] ?>" <?= (int) ($edit['term_id'] ?? $selectedTermId) === (int) $term['id'] ? 'selected' : '' ?>><?= e($term['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Position
                <select name="position" required>
                    <?php foreach ($electedPositions as $position): ?>
                        <option value="<?= e($position) ?>" <?= ($edit['position'] ?? 'City Councilor') === $position ? 'selected' : '' ?>><?= e($position) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Officer Role
                <select name="officer_role">
                    <option value="">No officer role</option>
                    <?php foreach ($officerRoles as $role): ?>
                        <option value="<?= e($role) ?>" <?= ($edit['officer_role'] ?? '') === $role ? 'selected' : '' ?>><?= e($role) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Name
                <input name="name" required value="<?= e($edit['name'] ?? '') ?>">
            </label>
            <label>Display Order
                <input type="number" name="sort_order" value="<?= e((string) ($edit['sort_order'] ?? 0)) ?>">
            </label>
            <div class="actions full">
                <button class="btn" type="submit">Save Official</button>
                <?php if ($edit): ?><a class="btn secondary" href="<?= url('/officials.php?term_id=') ?><?= (int) $selectedTermId ?>">Cancel</a><?php endif; ?>
            </div>
        </form>
    </section>
<?php endif; ?>

<section class="panel table-wrap">
    <h2>Officials for Selected Term</h2>
    <table>
        <thead><tr><th>Name</th><th>Position</th><th>Officer Role</th><th>Order</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($officials as $official): ?>
            <tr>
                <td><?= e($official['name']) ?></td>
                <td><?= e($official['position']) ?></td>
                <td><?= e($official['officer_role'] ?? '') ?></td>
                <td><?= (int) $official['sort_order'] ?></td>
                <td class="actions">
                    <?php if (is_admin()): ?>
                        <a href="<?= url('/officials.php?edit=') ?><?= (int) $official['id'] ?>&term_id=<?= (int) $selectedTermId ?>">Edit</a>
                        <form method="post" class="inline-form" onsubmit="return confirm('Delete this official?');">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int) $official['id'] ?>">
                            <button class="link-danger" type="submit">Delete</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$officials): ?><tr><td colspan="5">No officials encoded for this term.</td></tr><?php endif; ?>
        </tbody>
    </table>
</section>
<?php require __DIR__ . '/../app/partials/footer.php'; ?>
