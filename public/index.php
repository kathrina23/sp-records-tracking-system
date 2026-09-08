<?php

require_once __DIR__ . '/../app/auth.php';

require __DIR__ . '/../app/partials/header.php';
?>
<section class="landing-window">
    <div class="landing-head">
        <img src="<?= url('/assets/splogo.jpg') ?>" alt="Sangguniang Panlungsod logo">
        <div>
            <h1><?= e(APP_NAME) ?></h1>
            <p class="muted">Legislative Committees Division</p>
        </div>
    </div>

    <div class="landing-grid">
        <article class="landing-card staff-card">
            <h2>Authorized Users</h2>
            <p class="muted">Sign in to manage, update, assign, print, and monitor records.</p>
            <a class="btn" href="<?= current_user() ? '/dashboard.php' : '/login.php' ?>">
                <?= current_user() ? 'Open Dashboard' : 'Sign In' ?>
            </a>
        </article>

        <article class="landing-card public-card">
            <h2>Check the Status of your Request</h2>
            <p class="muted">Search using a communication number to view the latest status of your request.</p>
            <form method="get" action="<?= url('/public_status.php') ?>" class="public-search-form">
                <label>Communication Number
                    <input name="control_number" placeholder="Example: L-00001-2026" required>
                </label>
                <button class="btn secondary" type="submit">Search Status</button>
            </form>
        </article>
    </div>
</section>
<?php require __DIR__ . '/../app/partials/footer.php'; ?>
