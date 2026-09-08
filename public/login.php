<?php

require_once __DIR__ . '/../app/auth.php';

if (current_user()) {
    redirect('/dashboard.php');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (attempt_login(trim($_POST['email'] ?? ''), $_POST['password'] ?? '')) {
        redirect('/dashboard.php');
    }
    $error = 'Invalid email or password.';
}

require __DIR__ . '/../app/partials/header.php';
?>
<section class="auth-card">
    <h1>Sangguniang Panlungsod Records Tracking System</h1>
    <?php if ($error): ?>
        <div class="flash error"><?= e($error) ?></div>
    <?php endif; ?>
    <form method="post" class="grid">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <label>Email
            <input type="email" name="email" required autofocus value="admin@example.com">
        </label>
        <label>Password
            <input type="password" name="password" required value="admin123">
        </label>
        <button class="btn" type="submit">Sign in</button>
        <a class="btn secondary" href="<?= url('/') ?>">Public Main Window</a>
    </form>
</section>
<?php require __DIR__ . '/../app/partials/footer.php'; ?>
