<?php
require __DIR__ . '/../app/helpers.php';
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
function db(): PDO { global $pdo; return $pdo; }
function check_review(bool $ok, string $message): void {
    if (!$ok) { throw new RuntimeException($message); }
}
$pdo->exec('CREATE TABLE records (id INTEGER PRIMARY KEY, document_type TEXT, status TEXT, proposed_ordinance_number TEXT, proposed_resolution_number TEXT, approved_ordinance_number TEXT, approved_resolution_number TEXT)');
$pdo->exec('CREATE TABLE users (id INTEGER, role TEXT)');
$pdo->exec('CREATE TABLE record_movements (id INTEGER, record_id INTEGER, updated_by INTEGER, notes TEXT)');
$types = array_merge(['Committee Referrals'], administrative_document_types());
$insert = $pdo->prepare('INSERT INTO records (id, document_type, status) VALUES (?, ?, ?)');
foreach (['city_secretary', 'admin'] as $role) {
    $_SESSION['user'] = ['id' => 1, 'role' => $role];
    $pdo->exec('DELETE FROM records');
    foreach ($types as $index => $type) { $insert->execute([$index + 1, $type, 'Received']); }
    check_review(action_required_count() === 3, 'All three unreviewed records are counted');
    foreach ($types as $type) { check_review(action_required_count_for_type($type) === 1, 'Each tab counts only its own type'); }
    foreach ($types as $index => $type) {
        $id = $index + 1;
        $before = $pdo->query("SELECT * FROM records WHERE id = $id")->fetch();
        check_review(record_needs_user_action($before), 'Unreviewed record shows dot');
        $status = $index === 0 ? 'Pending to the Committee' : 'Completed';
        $pdo->prepare('UPDATE records SET status = ? WHERE id = ?')->execute([$status, $id]);
        $after = $pdo->query("SELECT * FROM records WHERE id = $id")->fetch();
        check_review(!record_needs_user_action($after), 'Reviewed record clears dot');
        check_review(action_required_count_for_type($type) === 0, 'Reviewed type clears tab counter');
        check_review(action_required_count() === 2 - $index, 'Overall count drops after each review');
        check_review(dashboard_action_required_count() === 2 - $index, 'Dashboard count drops after each review');
        $priority = $pdo->query('SELECT ' . record_action_priority_sql() . " FROM records WHERE id = $id")->fetchColumn();
        check_review((int) $priority === 1, 'Reviewed record no longer sorts as needing action');
    }
}
// Returning a referral for receiving-staff correction also counts as secretary action.
$_SESSION['user']['role'] = 'city_secretary';
$pdo->exec("UPDATE records SET status = 'Received' WHERE id = 1");
$pdo->exec("INSERT INTO users VALUES (1, 'city_secretary'), (2, 'receiving_clerk')");
$pdo->exec("INSERT INTO record_movements VALUES (1, 1, 1, 'Note to Receiving Staff: Correct title')");
check_review(action_required_count_for_type('Committee Referrals') === 0, 'Secretary comment clears review counter');
check_review(!record_needs_user_action($pdo->query('SELECT * FROM records WHERE id = 1')->fetch()), 'Secretary comment clears dot');
$pdo->exec("INSERT INTO record_movements VALUES (2, 1, 2, 'Receiving Staff updated the record in response to the City Secretary comment.')");
check_review(action_required_count_for_type('Committee Referrals') === 1, 'Staff correction returns record for review');
echo "PASS: separate tab counts, review dot clearing, totals, sorting, and correction handoff.\n";