<?php

require_once __DIR__ . '/../app/auth.php';
require_user_management();
ensure_plenary_number_schema();

$roles = [
    'admin' => 'Administrator',
    'city_secretary' => 'City Secretary',
    'division_chief' => 'Division Chief',
    'receiving_clerk' => 'Admin Receiving Section',
    'secretariat' => 'Secretariat',
    'division_staff' => 'Division Staff',
    'administrative_support' => 'LMIS & Records Staff',
    'others' => 'Others',
    'server_maintenance_staff' => 'Server Maintenance Staff',
];

$defaultDivisionOptions = [
    'Legislative Committees Division',
    'Legislative Support Services Division',
    'Administrative Support Division',
    'Others',
];
$savedDivisionOptions = [];
try {
    $savedDivisionOptions = db()->query("SELECT DISTINCT division_name FROM users WHERE division_name IS NOT NULL AND division_name <> '' ORDER BY division_name")->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $error) {
    $savedDivisionOptions = [];
}
$divisionOptions = array_values(array_unique(array_filter(array_merge($defaultDivisionOptions, $savedDivisionOptions))));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $nickname = trim($_POST['nickname'] ?? '');
    $divisionName = trim($_POST['division_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $role = $_POST['role'] ?? 'secretariat';
    $isActive = isset($_POST['is_active']) ? 1 : 0;
    $password = $_POST['password'] ?? '';

    if (!array_key_exists($role, $roles)) {
        $role = 'secretariat';
    }
    $managementEditingUser = $id > 0 && in_array(current_user()['role'] ?? '', ['admin', 'city_secretary'], true);
    if (!$managementEditingUser && $role === 'others') {
        $divisionName = 'Others';
    }
    if (!$managementEditingUser && $role === 'server_maintenance_staff') {
        $divisionName = 'Administrative Support Division';
    }
    $requiresDivision = !in_array($role, ['admin', 'city_secretary'], true);
    $divisionValue = ($managementEditingUser || $requiresDivision) && $divisionName !== '' ? $divisionName : null;

    if ($requiresDivision && $divisionName === '') {
        flash('Please choose a Division for this user role.', 'error');
        redirect('/users.php' . ($id ? '?edit=' . $id : ''));
    }

    try {
        if ($id) {
            if ($password !== '') {
                $stmt = db()->prepare('UPDATE users SET name=?, nickname=?, division_name=?, email=?, role=?, is_active=?, password_hash=? WHERE id=?');
                $stmt->execute([$name, $nickname !== '' ? $nickname : null, $divisionValue, $email, $role, $isActive, password_hash($password, PASSWORD_DEFAULT), $id]);
            } else {
                $stmt = db()->prepare('UPDATE users SET name=?, nickname=?, division_name=?, email=?, role=?, is_active=? WHERE id=?');
                $stmt->execute([$name, $nickname !== '' ? $nickname : null, $divisionValue, $email, $role, $isActive, $id]);
            }
            audit_log('user_update', 'Updated user ' . $email . ' as ' . role_label($role) . '.', 'user', $id);
            flash('User updated.');
        } else {
            $stmt = db()->prepare('INSERT INTO users (name, nickname, division_name, email, role, is_active, password_hash) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$name, $nickname !== '' ? $nickname : null, $divisionValue, $email, $role, $isActive, password_hash($password ?: 'changeme123', PASSWORD_DEFAULT)]);
            audit_log('user_create', 'Created user ' . $email . ' as ' . role_label($role) . '.', 'user', (int) db()->lastInsertId());
            flash('User added.');
        }
    } catch (Throwable $error) {
        flash('Unable to save user. If this is a role error, apply the database migration for the selected user role first.', 'error');
    }

    redirect('/users.php');
}

$edit = null;
if (isset($_GET['edit'])) {
    $stmt = db()->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([(int) $_GET['edit']]);
    $edit = $stmt->fetch();
    if (!empty($edit['division_name']) && !in_array($edit['division_name'], $divisionOptions, true)) {
        $divisionOptions[] = $edit['division_name'];
        sort($divisionOptions);
    }
}

$users = db()->query("SELECT id, name, nickname, division_name, email, role, is_active, created_at
    FROM users
    ORDER BY CASE WHEN division_name IS NULL OR division_name = '' THEN 1 ELSE 0 END, division_name, role, name")->fetchAll();
$groupedUsersByDivision = [];
foreach ($users as $item) {
    if (($item['role'] ?? '') === 'admin') {
        $divisionGroup = 'Administrator';
    } elseif (($item['role'] ?? '') === 'city_secretary') {
        $divisionGroup = 'City Secretary';
    } else {
        $divisionGroup = trim((string) ($item['division_name'] ?? '')) !== ''
            ? $item['division_name']
            : 'No Division Assigned';
    }
    $groupedUsersByDivision[$divisionGroup][] = $item;
}

require __DIR__ . '/../app/partials/header.php';
?>
<div class="page-head">
    <div>
        <h1>User Creation</h1>
        <p class="muted">For Administrator and City Secretary accounts only. Create users and set their assigned Division.</p>
    </div>
    <a class="btn secondary" href="<?= url('/secretariat_assignments.php') ?>">Assign Secretariats</a>
</div>

<?php if ($edit): ?><div class="modal-backdrop" role="presentation"><?php endif; ?>
<section class="panel <?= $edit ? 'user-edit-modal' : '' ?>" style="margin-bottom:16px;" <?= $edit ? 'role="dialog" aria-modal="true" aria-labelledby="user_form_title"' : '' ?>>
    <div class="<?= $edit ? 'modal-title-row' : '' ?>">
        <h2 id="user_form_title"><?= $edit ? 'Edit User' : 'Add User' ?></h2>
        <?php if ($edit): ?><a class="modal-close" href="<?= url('/users.php') ?>" aria-label="Close edit window">X</a><?php endif; ?>
    </div>
    <form method="post" class="form-grid">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
        <label>Name
            <input name="name" required value="<?= e($edit['name'] ?? '') ?>">
        </label>
        <label>Nickname
            <input name="nickname" value="<?= e($edit['nickname'] ?? '') ?>">
        </label>
        <label>Division
            <select name="division_name" id="division_name">
                <option value="">Select Division</option>
                <?php foreach ($divisionOptions as $divisionOption): ?>
                    <option value="<?= e($divisionOption) ?>" <?= ($edit['division_name'] ?? '') === $divisionOption ? 'selected' : '' ?>><?= e($divisionOption) ?></option>
                <?php endforeach; ?>
            </select>
            <span class="muted">This can be changed only by the Administrator or City Secretary.</span>
        </label>
        <label>Email
            <input type="email" name="email" required value="<?= e($edit['email'] ?? '') ?>">
        </label>
        <label>Role
            <select name="role" id="user_role">
                <?php foreach ($roles as $value => $label): ?>
                    <option value="<?= e($value) ?>" <?= ($edit['role'] ?? 'secretariat') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Password
            <input type="password" name="password" id="user_password" placeholder="<?= $edit ? 'Leave blank to keep current password' : 'Default: changeme123' ?>">
            <span class="muted"><?= $edit ? 'Current passwords are protected and cannot be displayed. Enter a new password only if you want to reset it.' : 'Use Show to check the password before saving.' ?></span>
            <button class="link-button" type="button" id="toggle_password_visibility">Show password</button>
        </label>
        <div class="actions full">
            <button class="btn" type="submit">Save User</button>
            <label class="inline-check">
                <input type="checkbox" name="is_active" value="1" <?= (int) ($edit['is_active'] ?? 1) === 1 ? 'checked' : '' ?>>
                <span>Check to Activate Account</span>
            </label>
            <?php if ($edit): ?><a class="btn secondary" href="<?= url('/users.php') ?>">Cancel</a><?php endif; ?>
        </div>
    </form>
</section>
<?php if ($edit): ?></div><?php endif; ?>

<?php foreach ($groupedUsersByDivision as $divisionName => $divisionUsers): ?>
    <section class="panel table-wrap" style="margin-bottom:16px;">
        <h2><?= e($divisionName) ?></h2>
        <table>
            <thead><tr><th>Name</th><th>Nickname</th><th>Role</th><th>Email</th><th>Password</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($divisionUsers as $item): ?>
                <tr>
                    <td><?= e($item['name']) ?></td>
                    <td><?= e($item['nickname'] ?? '') ?></td>
                    <td><?= e(role_label($item['role'])) ?></td>
                    <td><?= e($item['email']) ?></td>
                    <td><span class="muted">Protected</span><br><a href="<?= url('/users.php?edit=') ?><?= (int) $item['id'] ?>">Reset password</a></td>
                    <td><?= $item['is_active'] ? 'Active' : 'Inactive' ?></td>
                    <td><a href="<?= url('/users.php?edit=') ?><?= (int) $item['id'] ?>">Edit</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>
<?php endforeach; ?>
<?php if (!$groupedUsersByDivision): ?>
    <section class="panel"><p class="muted">No users found.</p></section>
<?php endif; ?>
<script>
const passwordInput = document.getElementById('user_password');
const togglePasswordButton = document.getElementById('toggle_password_visibility');
const userRoleSelect = document.getElementById('user_role');
const divisionSelect = document.getElementById('division_name');
if (passwordInput && togglePasswordButton) {
    togglePasswordButton.addEventListener('click', () => {
        const shouldShow = passwordInput.type === 'password';
        passwordInput.type = shouldShow ? 'text' : 'password';
        togglePasswordButton.textContent = shouldShow ? 'Hide password' : 'Show password';
    });
}
if (userRoleSelect && divisionSelect) {
    const managementEditingUser = <?= json_encode(!empty($edit) && in_array(current_user()['role'] ?? '', ['admin', 'city_secretary'], true)) ?>;
    const syncDivisionField = () => {
        if (managementEditingUser) {
            divisionSelect.disabled = false;
            divisionSelect.required = !['admin', 'city_secretary'].includes(userRoleSelect.value);
            return;
        }
        const requiresDivision = !['admin', 'city_secretary'].includes(userRoleSelect.value);
        const usesOthersDivision = userRoleSelect.value === 'others';
        const usesMaintenanceDivision = userRoleSelect.value === 'server_maintenance_staff';
        divisionSelect.disabled = !requiresDivision || usesOthersDivision || usesMaintenanceDivision;
        divisionSelect.required = requiresDivision && !usesOthersDivision && !usesMaintenanceDivision;
        if (userRoleSelect.value === 'administrative_support' || usesMaintenanceDivision) {
            divisionSelect.value = 'Administrative Support Division';
        }
        if (usesOthersDivision) {
            divisionSelect.value = 'Others';
        }
        if (!requiresDivision) {
            divisionSelect.value = '';
        }
    };
    userRoleSelect.addEventListener('change', syncDivisionField);
    syncDivisionField();
}
</script>
<?php require __DIR__ . '/../app/partials/footer.php'; ?>
