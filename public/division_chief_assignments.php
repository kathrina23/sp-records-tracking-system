<?php

require_once __DIR__ . '/../app/auth.php';
require_login();

if (!can_manage_division_chief_assignments()) {
    http_response_code(403);
    exit('Only the Administrator and City Secretary can assign Division Chief committees.');
}

try {
    db()->query('SELECT 1 FROM committee_division_chiefs LIMIT 1');
} catch (Throwable $error) {
    require __DIR__ . '/../app/partials/header.php';
    ?>
    <section class="panel">
        <h1>Staff Committee Assignment</h1>
        <p class="muted">Import <strong>database/migration_division_chief_committees.sql</strong> in phpMyAdmin to enable committee assignments.</p>
    </section>
    <?php
    require __DIR__ . '/../app/partials/footer.php';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $divisionChiefId = (int) ($_POST['division_chief_id'] ?? 0);
    $selectedCommittees = array_map('intval', $_POST['committee_ids'] ?? []);

    if ($selectedCommittees) {
        $placeholders = implode(',', array_fill(0, count($selectedCommittees), '?'));
        $params = array_merge($selectedCommittees, [$divisionChiefId]);
        $conflictStmt = db()->prepare("
            SELECT c.name, COALESCE(NULLIF(u.division_name, ''), u.name) division_chief_name
            FROM committee_division_chiefs a
            INNER JOIN committees c ON c.id = a.committee_id
            INNER JOIN users u ON u.id = a.user_id
            WHERE a.committee_id IN ($placeholders)
            AND a.user_id <> ?
            LIMIT 1
        ");
        $conflictStmt->execute($params);
        $conflict = $conflictStmt->fetch();

        if ($conflict) {
            flash($conflict['name'] . ' is already assigned to Division Chief ' . $conflict['division_chief_name'] . '.', 'error');
            redirect('/division_chief_assignments.php');
        }
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $delete = $pdo->prepare('DELETE FROM committee_division_chiefs WHERE user_id = ?');
        $delete->execute([$divisionChiefId]);

        $insert = $pdo->prepare('INSERT INTO committee_division_chiefs (committee_id, user_id, assigned_by) VALUES (?, ?, ?)');
        foreach ($selectedCommittees as $committeeId) {
            $insert->execute([$committeeId, $divisionChiefId, current_user()['id']]);
        }

        $pdo->commit();
        audit_log('division_chief_committee_assignment_update', 'Updated committee assignments for Division Chief #' . $divisionChiefId . '.', 'user', $divisionChiefId);
        flash('Division Chief committee assignment saved.');
    } catch (Throwable $error) {
        $pdo->rollBack();
        flash('Unable to save Division Chief committee assignment. Please try again.', 'error');
    }

    redirect('/division_chief_assignments.php');
}

$divisionChiefs = db()->query("SELECT id, name, division_name, email, COALESCE(NULLIF(division_name, ''), name) display_name FROM users WHERE role = 'division_chief' AND is_active = 1 ORDER BY display_name")->fetchAll();
$committees = db()->query('SELECT id, name FROM committees ORDER BY name')->fetchAll();
$assignments = db()->query('SELECT committee_id, user_id FROM committee_division_chiefs')->fetchAll();
$assignedMap = [];
foreach ($assignments as $assignment) {
    $assignedMap[(int) $assignment['user_id']][] = (int) $assignment['committee_id'];
}

require __DIR__ . '/../app/partials/header.php';
?>
<div class="page-head">
    <div>
        <h1>Staff Committee Assignment</h1>
        <p class="muted">Assign which committees each Division Chief can access and manage.</p>
    </div>
</div>

<?php foreach ($divisionChiefs as $chief): ?>
    <section class="panel" style="margin-bottom:16px;">
        <h2><?= e($chief['display_name']) ?></h2>
        <p class="muted"><?= e($chief['name']) ?> - <?= e($chief['email']) ?></p>
        <form method="post" class="grid">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="division_chief_id" value="<?= (int) $chief['id'] ?>">
            <div class="assignment-grid">
                <?php foreach ($committees as $committee): ?>
                    <label class="check-row">
                        <span>
                            <input type="checkbox" name="committee_ids[]" value="<?= (int) $committee['id'] ?>" <?= in_array((int) $committee['id'], $assignedMap[(int) $chief['id']] ?? [], true) ? 'checked' : '' ?>>
                            <?= e($committee['name']) ?>
                        </span>
                    </label>
                <?php endforeach; ?>
            </div>
            <div class="actions">
                <button class="btn" type="submit">Save Staff Committee Assignment</button>
            </div>
        </form>
    </section>
<?php endforeach; ?>

<?php if (!$divisionChiefs): ?>
    <section class="panel"><p class="muted">No active Division Chief users yet.</p></section>
<?php endif; ?>
<?php require __DIR__ . '/../app/partials/footer.php'; ?>
