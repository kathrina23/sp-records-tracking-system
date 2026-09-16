<?php

require_once __DIR__ . '/../app/auth.php';
$publicLanding = true;
require __DIR__ . '/../app/partials/header.php';
?>
<section class="landing-window public-landing">
    <a class="landing-login" href="<?= url('/login.php') ?>">User Login <span aria-hidden="true">&rarr;</span></a>
    <div class="landing-head">
        <img src="<?= url('/assets/splogo.jpg') ?>" alt="Sangguniang Panlungsod logo">
        <div>
            <h1><?= e(APP_NAME) ?></h1>
            <p class="muted">Cagayan de Oro City</p>
        </div>
    </div>
    <div class="landing-center">
        <article class="landing-card public-card">
            <h2>Check the Status<br>of Your Request</h2>
            <form method="get" action="<?= url('/public_status.php') ?>" class="public-search-form">
                <label>Communication Number
                    <input name="control_number" placeholder="Example: L-00001-2026" aria-describedby="tracking-help" spellcheck="false" required>
                </label>
                <button class="btn" type="submit">Check Request Status <span aria-hidden="true">&rarr;</span></button>
            </form>
            <p id="tracking-help" class="landing-help">Use the communication number provided when your request was received. No sign-in required.</p>
        </article>
    </div>
    <button class="landing-about" type="button" id="open-system-about" aria-haspopup="dialog" aria-controls="system-about">
        About the Legislative Records Tracking System <span aria-hidden="true">&rarr;</span>
    </button>
    <dialog id="system-about" class="system-about-dialog" aria-labelledby="system-about-title">
        <form method="dialog" class="system-about-close">
            <button type="submit" aria-label="Close system overview" autofocus>&times;</button>
        </form>
        <p class="system-about-city">SANGGUNIANG PANLUNGSOD &middot; CAGAYAN DE ORO CITY</p>
        <h2 id="system-about-title">About the Legislative Records Tracking System</h2>
        <p>The Legislative Records Tracking System helps the Sangguniang Panlungsod of Cagayan de Oro City organize and monitor legislative records, committee referrals, letters, endorsements, and other official communications.</p>
        <h3>For public clients</h3>
        <p>Check the progress of your request using the communication number provided when it was received. The public status page shows the latest status, available remarks, and the last update, without requiring a user account.</p>
        <h3>For authorized personnel</h3>
        <p>Authorized users sign in to record and update requests, route documents, manage assignments, and review tracking history. These tools support coordination among the offices and personnel handling legislative records.</p>
        <h3>Checking your request</h3>
        <p>Enter your communication number in the main page search box and select <strong>Check Request Status</strong>. If no record is found, check the number and try again.</p>
        <form method="dialog"><button class="btn" type="submit">Back to Request Tracking</button></form>
    </dialog>
    <script>
    document.getElementById('open-system-about').addEventListener('click', function () {
        document.getElementById('system-about').showModal();
    });
    </script>
</section>
<?php require __DIR__ . '/../app/partials/footer.php'; ?>