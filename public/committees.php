<?php

require_once __DIR__ . '/../app/auth.php';
require_login();
require_management_access();
ensure_committee_reporting_schema();

function committee_terms_ready(): bool
{
    try {
        db()->query('SELECT 1 FROM committee_terms LIMIT 1');
        db()->query('SELECT 1 FROM committee_members LIMIT 1');
        return true;
    } catch (Throwable $error) {
        return false;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!is_admin()) {
        http_response_code(403);
        exit('Only the City Secretary and Division Chief can change committees.');
    }

    verify_csrf();
    $action = $_POST['action'] ?? 'save';
    $id = (int) ($_POST['id'] ?? 0);

    if ($action === 'delete') {
        try {
            $stmt = db()->prepare('DELETE FROM committees WHERE id = ?');
            $stmt->execute([$id]);
            audit_log('committee_delete', 'Deleted committee #' . $id . '.', 'committee', $id);
            flash('Committee deleted. Related records were left in the system as unassigned.');
        } catch (Throwable $error) {
            flash('Unable to delete committee. Please try again.', 'error');
        }

        redirect('/committees.php');
    }

    $name = trim($_POST['name'] ?? '');
    $committeeCode = normalize_committee_code($_POST['committee_code'] ?? '');
    $description = trim($_POST['description'] ?? '');

    if ($id) {
        $stmt = db()->prepare('UPDATE committees SET name=?, committee_code=?, description=? WHERE id=?');
        $stmt->execute([$name, $committeeCode, $description, $id]);
        audit_log('committee_update', 'Updated committee ' . $name . '.', 'committee', $id);
        flash('Committee updated.');
    } else {
        $stmt = db()->prepare('INSERT INTO committees (name, committee_code, description) VALUES (?, ?, ?)');
        $stmt->execute([$name, $committeeCode, $description]);
        audit_log('committee_create', 'Created committee ' . $name . '.', 'committee', (int) db()->lastInsertId());
        flash('Committee added. You can now add its term roster.');
    }
    redirect('/committees.php');
}

$edit = null;
if (isset($_GET['edit'])) {
    $stmt = db()->prepare('SELECT * FROM committees WHERE id = ?');
    $stmt->execute([(int) $_GET['edit']]);
    $edit = $stmt->fetch();
}

$termsReady = committee_terms_ready();
$currentTerm = null;
$committees = [];

if ($termsReady) {
    $currentTerm = db()->query('SELECT * FROM committee_terms WHERE is_current = 1 ORDER BY id DESC LIMIT 1')->fetch();
    $currentTermId = (int) ($currentTerm['id'] ?? 0);
    $chiefCommitteeIds = (current_user()['role'] ?? '') === 'division_chief' ? division_chief_committee_ids() : [];
    $secretariatCommitteeIds = (current_user()['role'] ?? '') === 'secretariat' ? secretariat_committee_ids() : [];
    $committeeFilter = '';
    $params = [$currentTermId];
    if ((current_user()['role'] ?? '') === 'division_chief') {
        if (!$chiefCommitteeIds) {
            $committeeFilter = 'WHERE 1 = 0';
        } else {
            $committeeFilter = 'WHERE c.id IN (' . implode(',', array_fill(0, count($chiefCommitteeIds), '?')) . ')';
            $params = array_merge($params, $chiefCommitteeIds);
        }
    } elseif ((current_user()['role'] ?? '') === 'secretariat') {
        if (!$secretariatCommitteeIds) {
            $committeeFilter = 'WHERE 1 = 0';
        } else {
            $committeeFilter = 'WHERE c.id IN (' . implode(',', array_fill(0, count($secretariatCommitteeIds), '?')) . ')';
            $params = array_merge($params, $secretariatCommitteeIds);
        }
    }

    $stmt = db()->prepare("
        SELECT c.*,
            COUNT(DISTINCT r.id) record_count,
            MAX(CASE WHEN m.position = 'Chairperson' THEN m.name END) chairperson_name,
            MAX(CASE WHEN m.position = 'Vice Chairperson' THEN m.name END) vice_chairperson_name,
            SUM(CASE WHEN m.position = 'Member' THEN 1 ELSE 0 END) member_count
        FROM committees c
        LEFT JOIN records r ON r.committee_id = c.id
        LEFT JOIN committee_members m ON m.committee_id = c.id AND m.term_id = ?
        $committeeFilter
        GROUP BY c.id
        ORDER BY c.name
    ");
    $stmt->execute($params);
    $committees = $stmt->fetchAll();
} else {
    if (in_array(current_user()['role'] ?? '', ['division_chief', 'secretariat'], true)) {
        $assignedCommitteeIds = (current_user()['role'] ?? '') === 'division_chief' ? division_chief_committee_ids() : secretariat_committee_ids();
        if ($assignedCommitteeIds) {
            $placeholders = implode(',', array_fill(0, count($assignedCommitteeIds), '?'));
            $stmt = db()->prepare("SELECT c.*, COUNT(r.id) record_count FROM committees c LEFT JOIN records r ON r.committee_id = c.id WHERE c.id IN ($placeholders) GROUP BY c.id ORDER BY c.name");
            $stmt->execute($assignedCommitteeIds);
            $committees = $stmt->fetchAll();
        } else {
            $committees = [];
        }
    } else {
        $committees = db()->query('SELECT c.*, COUNT(r.id) record_count FROM committees c LEFT JOIN records r ON r.committee_id = c.id GROUP BY c.id ORDER BY c.name')->fetchAll();
    }
}

require __DIR__ . '/../app/partials/header.php';
?>
<div class="page-head">
    <div>
        <h1>Committees</h1>
        <p class="muted">Maintain committees and their current term rosters.</p>
    </div>
    <?php if (is_admin()): ?>
        <a class="btn secondary" href="<?= url('/terms.php') ?>">Manage Terms</a>
    <?php endif; ?>
</div>

<?php if (!$termsReady): ?>
    <section class="panel" style="margin-bottom:16px;">
        <h2>Database Update Needed</h2>
        <p class="muted">Import <strong>database/migration_committee_terms.sql</strong> in phpMyAdmin to enable term-based committee rosters.</p>
    </section>
<?php endif; ?>

<section class="panel" style="margin-bottom:16px;">
    <?php if (is_admin()): ?>
        <h2><?= $edit ? 'Edit Committee' : 'Add Committee' ?></h2>
        <form method="post" class="form-grid">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
            <label class="full">Committee Name
                <input name="name" required value="<?= e($edit['name'] ?? '') ?>">
            </label>
            <label>Committee Code
                <input name="committee_code" maxlength="20" value="<?= e($edit['committee_code'] ?? '') ?>" placeholder="Example: FIN">
            </label>
            <label class="full">Description
                <textarea name="description"><?= e($edit['description'] ?? '') ?></textarea>
            </label>
            <div class="actions full">
                <button class="btn" type="submit">Save Committee</button>
                <?php if ($edit): ?><a class="btn secondary" href="<?= url('/committees.php') ?>">Cancel</a><?php endif; ?>
            </div>
        </form>
    <?php else: ?>
        <h2>Committee Directory</h2>
        <p class="muted">Committee changes are managed by the City Secretary and Division Chief.</p>
    <?php endif; ?>
</section>

<section class="panel table-wrap">
    <h2>Committee List <?= $currentTerm ? '(' . e($currentTerm['name']) . ')' : '' ?></h2>
    <table>
        <thead><tr><th>Name</th><th>Code</th><th>Chairperson</th><th>Vice Chairperson</th><th>Members</th><th>Records</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($committees as $committee): ?>
            <tr>
                <td><?= e($committee['name']) ?></td>
                <td><?= e($committee['committee_code'] ?? '') ?></td>
                <td><?= e($committee['chairperson_name'] ?? $committee['chairperson'] ?? 'Not set') ?></td>
                <td><?= e($committee['vice_chairperson_name'] ?? 'Not set') ?></td>
                <td><?= (int) ($committee['member_count'] ?? 0) ?>/20</td>
                <td><?= (int) $committee['record_count'] ?></td>
                <td class="actions">
                    <?php if ($termsReady && $currentTerm): ?>
                        <a href="<?= url('/committee_roster.php?committee_id=') ?><?= (int) $committee['id'] ?>">Roster</a>
                    <?php endif; ?>
                    <?php if (is_admin()): ?>
                        <a href="<?= url('/committees.php?edit=') ?><?= (int) $committee['id'] ?>">Edit</a>
                        <form method="post" class="inline-form" onsubmit="return confirm('Delete this committee? Related records will remain but become unassigned.');">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int) $committee['id'] ?>">
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
