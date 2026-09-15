<?php
require_once __DIR__ . '/../app/plenary_results.php';
require_once __DIR__ . '/../app/helpers.php';
define('BASE_PATH', '/records/public');
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
function db(): PDO { global $pdo; return $pdo; }
function expect_plenary(bool $ok, string $message): void {
    if (!$ok) { throw new RuntimeException($message); }
}
$pdo->exec('CREATE TABLE committees (id INTEGER, name TEXT)');
$pdo->exec('CREATE TABLE records (id INTEGER, committee_id INTEGER, document_type TEXT, status TEXT, plenary_session_date TEXT, updated_at TEXT, control_number TEXT, title TEXT, proposed_ordinance_number TEXT)');
$insert = $pdo->prepare('INSERT INTO records VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?)');
for ($i = 1; $i <= 61; $i++) {
    $insert->execute([$i, $i % 2 ? 'Committee Referrals' : 'Certified Urgent', 'Scheduled for Plenary',
        '2026-09-15', '2026-09-11 10:00:00', "L-$i", 'Session record ' . $i, "2026-$i"]);
}
$insert->execute([62, 'Committee Referrals', 'Scheduled for Plenary', '2026-09-16', '', 'L-62', 'Other date', '']);
$insert->execute([63, 'Committee Referrals', 'For Plenary Session', null, '', 'L-63', 'Unscheduled', '']);
$insert->execute([64, 'Committee Referrals', 'Approved in the Plenary', '2026-09-15', '', 'L-64', 'Approved', '']);
$insert->execute([65, 'Memorandum', 'Scheduled for Plenary', '2026-09-15', '', 'L-65', 'Wrong type', '']);
expect_plenary(count(for_plenary_results('2026-09-15')) === 61, 'All matching records, including beyond 50');
expect_plenary(count(for_plenary_results('')) === 63, 'Clear filter includes unscheduled and both dates');
expect_plenary(count(for_plenary_results('2026-09-17')) === 0, 'No matches');
expect_plenary(plenary_session_filter(['plenary_date' => '2028-02-29']) === '2028-02-29', 'Leap day');
foreach (['2026-02-29', '2026-09-31', '09/15/2026', ['2026-09-15'], "2026-09-15' OR 1=1"] as $value) {
    try { plenary_session_filter(['plenary_date' => $value]); throw new RuntimeException('Invalid date accepted'); }
    catch (InvalidArgumentException $expected) {}
}
$_SESSION['user'] = ['role' => 'admin'];
$_GET = ['return_tab' => 'city_tab'];
$plenaryDateFilter = '2026-09-15';
$records = for_plenary_results($plenaryDateFilter);
$source = file_get_contents(__DIR__ . '/../public/for_plenary_print.php');
$renderSource = substr($source, strpos($source, 'function plenary_record_date'));
ob_start();
eval($renderSource);
$html = ob_get_clean();
$dom = new DOMDocument();
@$dom->loadHTML($html);
$xpath = new DOMXPath($dom);
expect_plenary($xpath->query('//tbody/tr')->length === 61, 'One printed row per matching record');
expect_plenary($xpath->query('//thead/tr/th')->length === 7, 'Seven tabular columns');
expect_plenary(str_contains($html, '09/15/2026') && str_contains($html, '61 record(s)'), 'Date and count on printout');
expect_plenary(!str_contains($html, 'Other date') && !str_contains($html, 'Unscheduled'), 'Print excludes nonmatches');
expect_plenary(str_contains($html, '/records/public/dashboard.php?city_tab=for-plenary'), 'Subfolder return link');
echo "PASS: session date validation, matching SQL, 61-row filtered print, clear and empty results, and subfolder navigation.\n";