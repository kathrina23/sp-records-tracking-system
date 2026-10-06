<?php

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/term_dates.php';
require_login();
if (!can_view_terms()) {
    http_response_code(403);
    exit('This page is for authorized term viewers only.');
}

try {
    db()->query('SELECT 1 FROM committee_terms LIMIT 1');
} catch (Throwable $error) {
    require __DIR__ . '/../app/partials/header.php';
    ?>
    <section class="panel">
        <h1>Terms</h1>
        <p class="muted">Import <strong>database/migration_committee_terms.sql</strong> in phpMyAdmin to enable term management.</p>
    </section>
    <?php
    require __DIR__ . '/../app/partials/footer.php';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!is_admin()) {
        http_response_code(403);
        exit('Only the City Secretary and Division Chief can change terms.');
    }

    verify_csrf();
    $action = $_POST['action'] ?? 'save';
    $id = (int) ($_POST['id'] ?? 0);

    if ($action === 'delete') {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT is_current FROM committee_terms WHERE id = ?');
            $stmt->execute([$id]);
            $wasCurrent = (int) $stmt->fetchColumn() === 1;

            $delete = $pdo->prepare('DELETE FROM committee_terms WHERE id = ?');
            $delete->execute([$id]);

            if ($wasCurrent) {
                $nextCurrent = $pdo->query('SELECT id FROM committee_terms ORDER BY start_year DESC, id DESC LIMIT 1')->fetchColumn();
                if ($nextCurrent) {
                    $update = $pdo->prepare('UPDATE committee_terms SET is_current = 1 WHERE id = ?');
                    $update->execute([(int) $nextCurrent]);
                }
            }

            $pdo->commit();
            audit_log('term_delete', 'Deleted term #' . $id . '.', 'term', $id);
            flash('Term deleted.');
        } catch (Throwable $error) {
            $pdo->rollBack();
            flash('Unable to delete term. Please try again.', 'error');
        }

        redirect('/terms.php');
    }

    $name = trim($_POST['name'] ?? '');
    try {
        [$startYear, $startMonth] = term_parse_period(is_string($_POST['start_period'] ?? null) ? $_POST['start_period'] : '');
        [$endYear, $endMonth] = term_parse_period(is_string($_POST['end_period'] ?? null) ? $_POST['end_period'] : '');
        if ($name === '' || strlen($name) > 120 || $_POST['end_period'] < $_POST['start_period']) {
            throw new InvalidArgumentException('Enter a term name up to 120 bytes and an end month on or after the start month.');
        }
    } catch (InvalidArgumentException $error) {
        flash($error->getMessage(), 'error');
        redirect('/terms.php' . ($id ? '?edit=' . $id : ''));
    }
    $isCurrent = isset($_POST['is_current']) ? 1 : 0;

    $pdo = db();
    $pdo->beginTransaction();
    try {
        if ($isCurrent) {
            $pdo->exec('UPDATE committee_terms SET is_current = 0');
        }

        if ($id) {
            $stmt = $pdo->prepare('UPDATE committee_terms SET name=?, start_year=?, start_month=?, end_year=?, end_month=?, is_current=? WHERE id=?');
            $stmt->execute([$name, $startYear, $startMonth, $endYear, $endMonth, $isCurrent, $id]);
            audit_log('term_update', 'Updated term ' . $name . '.', 'term', $id);
            flash('Term updated.');
        } else {
            $stmt = $pdo->prepare('INSERT INTO committee_terms (name, start_year, start_month, end_year, end_month, is_current) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->execute([$name, $startYear, $startMonth, $endYear, $endMonth, $isCurrent]);
            audit_log('term_create', 'Created term ' . $name . '.', 'term', (int) $pdo->lastInsertId());
            flash('Term added. You can now add committee rosters for this term.');
        }

        $pdo->commit();
    } catch (Throwable $error) {
        $pdo->rollBack();
        flash('Unable to save term. Please check the details and try again.', 'error');
    }

    redirect('/terms.php');
}

$edit = null;
if (isset($_GET['edit'])) {
    $stmt = db()->prepare('SELECT * FROM committee_terms WHERE id = ?');
    $stmt->execute([(int) $_GET['edit']]);
    $edit = $stmt->fetch();
}

$terms = db()->query('SELECT t.*, COUNT(m.id) member_count FROM committee_terms t LEFT JOIN committee_members m ON m.term_id = t.id GROUP BY t.id ORDER BY t.start_year DESC, t.id DESC')->fetchAll();

require __DIR__ . '/../app/partials/header.php';
?>
<div class="page-head">
    <div>
        <h1>Terms</h1>
        <p class="muted">Set each term's start and end month and year, and mark the active roster group. Edit older terms to specify their months.</p>
    </div>
</div>

<section class="panel" style="margin-bottom:16px;">
    <?php if (is_admin()): ?>
        <h2><?= $edit ? 'Edit Term' : 'Add Term' ?></h2>
        <form method="post" class="form-grid">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
            <label>Term Name
                <input name="name" required placeholder="2026-2029 Term" value="<?= e($edit['name'] ?? '') ?>">
            </label>
            <label>Start Month and Year
                <input type="month" name="start_period" min="1901-01" max="2155-12" required value="<?= e($edit ? term_period_input($edit, 'start') : date('Y-m')) ?>">
            </label>
            <label>End Month and Year
                <input type="month" name="end_period" min="1901-01" max="2155-12" required value="<?= e($edit ? term_period_input($edit, 'end') : date('Y-m', strtotime('+3 years'))) ?>">
            </label>
            <label>
                <span><input type="checkbox" name="is_current" value="1" <?= (int) ($edit['is_current'] ?? 0) === 1 ? 'checked' : '' ?>> Current term</span>
            </label>
            <div class="actions full">
                <button class="btn" type="submit">Save Term</button>
                <?php if ($edit): ?><a class="btn secondary" href="<?= url('/terms.php') ?>">Cancel</a><?php endif; ?>
            </div>
        </form>
    <?php else: ?>
        <h2>Term Directory</h2>
        <p class="muted">Term changes are managed by the City Secretary and Division Chief.</p>
    <?php endif; ?>
</section>

<section class="panel table-wrap">
    <table>
        <thead><tr><th>Term</th><th>Month and Year</th><th>Status</th><th>Roster Entries</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($terms as $term): ?>
            <tr>
                <td><?= e($term['name']) ?></td>
                <td><?= e(term_period_label($term)) ?></td>
                <td><?= $term['is_current'] ? 'Current' : 'Archived' ?></td>
                <td><?= (int) $term['member_count'] ?></td>
                <td class="actions">
                    <?php if (is_admin()): ?>
                        <a href="<?= url('/terms.php?edit=') ?><?= (int) $term['id'] ?>">Edit</a>
                        <form method="post" class="inline-form" onsubmit="return confirm('Delete this term and its committee rosters?');">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int) $term['id'] ?>">
                            <button class="link-danger" type="submit">Delete</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
<?php require __DIR__ . '/../app/partials/footer.php'; ?>
