<?php

require_once __DIR__ . '/../app/auth.php';
require_login();

try {
    db()->query('SELECT 1 FROM committee_terms LIMIT 1');
    db()->query('SELECT 1 FROM committee_members LIMIT 1');
} catch (Throwable $error) {
    flash('Import database/migration_committee_terms.sql before editing rosters.', 'error');
    redirect('/committees.php');
}

$officialsReady = true;
try {
    db()->query('SELECT 1 FROM city_officials LIMIT 1');
} catch (Throwable $error) {
    $officialsReady = false;
}

$committeeId = (int) ($_GET['committee_id'] ?? $_POST['committee_id'] ?? 0);
$termId = (int) ($_GET['term_id'] ?? $_POST['term_id'] ?? 0);
$isPopup = ($_GET['popup'] ?? $_POST['popup'] ?? '') === '1';
$returnTarget = ($_GET['return'] ?? $_POST['return'] ?? '') === 'dashboard' ? 'dashboard' : 'committees';
$divisionTab = $_GET['division_tab'] ?? $_POST['division_tab'] ?? '';
$closeUrl = $returnTarget === 'dashboard'
    ? '/dashboard.php' . ($divisionTab !== '' ? '?division_tab=' . urlencode($divisionTab) : '')
    : '/committees.php';

$stmt = db()->prepare('SELECT * FROM committees WHERE id = ?');
$stmt->execute([$committeeId]);
$committee = $stmt->fetch();
if (!$committee) {
    http_response_code(404);
    exit('Committee not found.');
}

if ((current_user()['role'] ?? '') === 'division_chief' && !can_access_committee($committeeId)) {
    http_response_code(403);
    exit('This committee is not assigned to you.');
}

if (!$termId) {
    $termId = (int) db()->query('SELECT id FROM committee_terms WHERE is_current = 1 ORDER BY id DESC LIMIT 1')->fetchColumn();
}

$termStmt = db()->prepare('SELECT * FROM committee_terms WHERE id = ?');
$termStmt->execute([$termId]);
$term = $termStmt->fetch();
if (!$term) {
    flash('Please create a term before adding committee rosters.', 'error');
    redirect('/terms.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!is_admin()) {
        http_response_code(403);
        exit('Only the City Secretary and Division Chief can change committee rosters.');
    }

    verify_csrf();
    $action = $_POST['action'] ?? 'save_roster';

    if ($action === 'delete_member') {
        $memberId = (int) ($_POST['member_id'] ?? 0);
        $delete = db()->prepare('DELETE FROM committee_members WHERE id = ? AND committee_id = ? AND term_id = ?');
        $delete->execute([$memberId, $committeeId, $termId]);
        audit_log('roster_member_delete', 'Removed member #' . $memberId . ' from committee roster.', 'committee', $committeeId);
        flash('Member removed from this term roster.');
        redirect('/committee_roster.php?committee_id=' . $committeeId . '&term_id=' . $termId);
    }

    $chairperson = trim($_POST['chairperson'] ?? '');
    $viceChairperson = trim($_POST['vice_chairperson'] ?? '');
    $members = array_values(array_filter(array_map('trim', $_POST['members'] ?? [])));

    if (count($members) > 20) {
        flash('A committee can have only up to 20 members.', 'error');
        redirect('/committee_roster.php?committee_id=' . $committeeId . '&term_id=' . $termId);
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $delete = $pdo->prepare('DELETE FROM committee_members WHERE committee_id = ? AND term_id = ?');
        $delete->execute([$committeeId, $termId]);

        $insert = $pdo->prepare('INSERT INTO committee_members (committee_id, term_id, name, position, sort_order) VALUES (?, ?, ?, ?, ?)');
        $order = 1;
        if ($chairperson !== '') {
            $insert->execute([$committeeId, $termId, $chairperson, 'Chairperson', $order++]);
            $legacy = $pdo->prepare('UPDATE committees SET chairperson = ? WHERE id = ?');
            $legacy->execute([$chairperson, $committeeId]);
        }
        if ($viceChairperson !== '') {
            $insert->execute([$committeeId, $termId, $viceChairperson, 'Vice Chairperson', $order++]);
        }
        foreach ($members as $member) {
            $insert->execute([$committeeId, $termId, $member, 'Member', $order++]);
        }

        $pdo->commit();
        audit_log('roster_update', 'Updated roster for ' . $committee['name'] . ' / ' . $term['name'] . '.', 'committee', $committeeId);
        flash('Committee roster saved.');
    } catch (Throwable $error) {
        $pdo->rollBack();
        flash('Unable to save committee roster. Please try again.', 'error');
    }

    redirect('/committee_roster.php?committee_id=' . $committeeId . '&term_id=' . $termId);
}

$terms = db()->query('SELECT id, name FROM committee_terms ORDER BY start_year DESC, id DESC')->fetchAll();
$officials = [];
if ($officialsReady) {
    $officialStmt = db()->prepare('SELECT name, position, officer_role FROM city_officials WHERE term_id = ? ORDER BY name, sort_order');
    $officialStmt->execute([$termId]);
    $officials = $officialStmt->fetchAll();
}
$membersStmt = db()->prepare('SELECT * FROM committee_members WHERE committee_id = ? AND term_id = ? ORDER BY sort_order, id');
$membersStmt->execute([$committeeId, $termId]);
$roster = $membersStmt->fetchAll();

$chairperson = '';
$viceChairperson = '';
$memberNames = [];
foreach ($roster as $person) {
    if ($person['position'] === 'Chairperson') {
        $chairperson = $person['name'];
    } elseif ($person['position'] === 'Vice Chairperson') {
        $viceChairperson = $person['name'];
    } else {
        $memberNames[] = $person['name'];
    }
}

require __DIR__ . '/../app/partials/header.php';

function official_select(string $name, string $currentValue, array $officials, string $placeholder): void
{
    ?>
    <select name="<?= e($name) ?>">
        <option value=""><?= e($placeholder) ?></option>
        <?php if ($currentValue !== '' && !in_array($currentValue, array_column($officials, 'name'), true)): ?>
            <option value="<?= e($currentValue) ?>" selected><?= e($currentValue) ?></option>
        <?php endif; ?>
        <?php foreach ($officials as $official): ?>
            <option value="<?= e($official['name']) ?>" <?= $currentValue === $official['name'] ? 'selected' : '' ?>>
                <?= e($official['name']) ?> - <?= e($official['position']) ?><?= !empty($official['officer_role']) ? ' / ' . e($official['officer_role']) : '' ?>
            </option>
        <?php endforeach; ?>
    </select>
    <?php
}
?>
<?php if ($isPopup): ?>
<div class="modal-backdrop" role="presentation">
    <section class="panel user-edit-modal" role="dialog" aria-modal="true" aria-labelledby="committee_roster_title">
        <div class="modal-title-row">
            <div>
                <h1 id="committee_roster_title"><?= e($committee['name']) ?> Roster</h1>
                <p class="muted"><?= e($term['name']) ?></p>
            </div>
            <a class="modal-close" href="<?= e($closeUrl) ?>" aria-label="Close roster window">X</a>
        </div>

        <div class="detail-list">
            <div class="full"><strong>Chairperson:</strong><br><?= e($chairperson !== '' ? $chairperson : 'Not assigned') ?></div>
            <div class="full"><strong>Vice Chairperson:</strong><br><?= e($viceChairperson !== '' ? $viceChairperson : 'Not assigned') ?></div>
        </div>

        <div class="table-wrap" style="margin-top:14px;">
            <table>
                <thead><tr><th>Members</th></tr></thead>
                <tbody>
                <?php foreach ($memberNames as $memberName): ?>
                    <tr><td><?= e($memberName) ?></td></tr>
                <?php endforeach; ?>
                <?php if (!$memberNames): ?><tr><td>No members encoded for this term.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>
<?php require __DIR__ . '/../app/partials/footer.php'; exit; ?>
<?php endif; ?>

<div class="page-head">
    <div>
        <h1><?= e($committee['name']) ?> Roster</h1>
        <p class="muted"><?= e($term['name']) ?> committee membership.</p>
    </div>
    <a class="btn secondary" href="<?= url('/committees.php') ?>">Back to Committees</a>
</div>

<section class="panel" style="margin-bottom:16px;">
    <form method="get" class="filters" style="grid-template-columns: 1fr 1fr auto;">
        <input type="hidden" name="committee_id" value="<?= (int) $committeeId ?>">
        <label>Term
            <select name="term_id">
                <?php foreach ($terms as $item): ?>
                    <option value="<?= (int) $item['id'] ?>" <?= (int) $item['id'] === $termId ? 'selected' : '' ?>><?= e($item['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <span></span>
        <button class="btn secondary" type="submit">View Term</button>
    </form>
</section>

<?php if (is_admin()): ?>
    <form method="post" class="panel form-grid">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="save_roster">
        <input type="hidden" name="committee_id" value="<?= (int) $committeeId ?>">
        <input type="hidden" name="term_id" value="<?= (int) $termId ?>">
        <label>Chairperson
            <?php official_select('chairperson', $chairperson, $officials, 'Select Chairperson'); ?>
        </label>
        <label>Vice Chairperson
            <?php official_select('vice_chairperson', $viceChairperson, $officials, 'Select Vice Chairperson'); ?>
        </label>
        <div class="full">
            <h2>Members</h2>
            <p class="muted">Select up to 20 committee members from the officials encoded for this term.</p>
            <?php if (!$officialsReady): ?>
                <p class="muted">Import <strong>database/migration_city_officials.sql</strong> to enable official selection.</p>
            <?php elseif (!$officials): ?>
                <p class="muted">No Vice Mayor or City Councilors encoded yet for this term. Add them on the Officials page.</p>
            <?php endif; ?>
            <div class="member-grid">
                <?php for ($i = 0; $i < 20; $i++): ?>
                    <label>Member <?= $i + 1 ?>
                        <?php official_select('members[]', $memberNames[$i] ?? '', $officials, 'Select member'); ?>
                    </label>
                <?php endfor; ?>
            </div>
        </div>
        <div class="actions full">
            <button class="btn" type="submit">Save Roster</button>
        </div>
    </form>
<?php endif; ?>

<section class="panel table-wrap" style="margin-top:16px;">
    <h2>Current Roster Entries</h2>
    <table>
        <thead><tr><th>Name</th><th>Position</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($roster as $person): ?>
            <tr>
                <td><?= e($person['name']) ?></td>
                <td><?= e($person['position']) ?></td>
                <td>
                    <?php if (is_admin()): ?>
                        <form method="post" class="inline-form" onsubmit="return confirm('Remove this member from the roster?');">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="delete_member">
                            <input type="hidden" name="committee_id" value="<?= (int) $committeeId ?>">
                            <input type="hidden" name="term_id" value="<?= (int) $termId ?>">
                            <input type="hidden" name="member_id" value="<?= (int) $person['id'] ?>">
                            <button class="link-danger" type="submit">Delete</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$roster): ?><tr><td colspan="3">No roster entries yet.</td></tr><?php endif; ?>
        </tbody>
    </table>
</section>
<?php require __DIR__ . '/../app/partials/footer.php'; ?>
