    <footer class="footer">
        <span><?= e(APP_NAME) ?></span>
        <span><?= date('Y') ?></span>
    </footer>
</main>
<script>
document.addEventListener('click', (event) => {
    const link = event.target.closest('a.record-view-action');
    if (!link) {
        return;
    }

    const basePath = <?= json_encode(BASE_PATH) ?>;
    const returnTargets = {
        [basePath + '/records.php']: 'records',
        [basePath + '/dashboard.php']: 'dashboard',
        [basePath + '/record_recipients.php']: 'recipients',
    };
    const returnTarget = returnTargets[window.location.pathname];
    if (!returnTarget) {
        return;
    }

    const destination = new URL(link.href, window.location.origin);
    if (destination.origin !== window.location.origin || destination.pathname !== basePath + '/record_view.php') {
        return;
    }

    destination.searchParams.set('popup', '1');
    destination.searchParams.set('return', returnTarget);
    if (!destination.searchParams.has('return_url')) {
        destination.searchParams.set(
            'return_url',
            window.location.pathname + window.location.search + window.location.hash
        );
    }
    link.href = destination.pathname + destination.search + destination.hash;
});
</script>
<script src="<?= url('/assets/table-pagination.js') ?>"></script>
<script src="<?= url('/assets/record-search-suggestions.js') ?>"></script>
</body>
</html>
