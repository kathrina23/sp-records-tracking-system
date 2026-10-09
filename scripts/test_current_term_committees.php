<?php
// Connection-local temporary tables leave production data unchanged.
declare(strict_types=1);
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/helpers.php';
$pdo = db();
$pdo->exec('CREATE TEMPORARY TABLE committees (id INT PRIMARY KEY, name VARCHAR(100))');
$pdo->exec('CREATE TEMPORARY TABLE committee_terms (id INT PRIMARY KEY, is_current INT)');
$pdo->exec('CREATE TEMPORARY TABLE committee_members (committee_id INT, term_id INT)');
$pdo->exec('CREATE TEMPORARY TABLE committee_term_assignments (committee_id INT, term_id INT)');
$pdo->exec("INSERT INTO committees VALUES (1, 'Current'), (2, 'Older term'), (3, 'No membership'), (4, 'Both terms')");
$pdo->exec('INSERT INTO committee_terms VALUES (1, 0), (2, 1)');
$pdo->exec('INSERT INTO committee_members VALUES (1, 2), (1, 2), (2, 1), (4, 1), (4, 2)');
$pdo->exec('INSERT INTO committee_term_assignments SELECT DISTINCT committee_id, term_id FROM committee_members');
function check_current_committees(?array $scope, array $expected): void {
    $actual = array_map('intval', array_column(current_term_committees($scope), 'id'));
    sort($actual);
    sort($expected);
    if ($actual !== $expected) {
        throw new RuntimeException('Incorrect current-term committee choices: ' . json_encode($actual));
    }
}
check_current_committees(null, [1, 4]);
check_current_committees([1, 2, 3], [1]);
check_current_committees([2, 3], []);
check_current_committees([], []);
$pdo->exec('UPDATE committee_terms SET is_current = CASE WHEN id = 1 THEN 1 ELSE 0 END');
check_current_committees(null, [2, 4]);
$pdo->exec('UPDATE committee_terms SET is_current = 0');
check_current_committees(null, []);
echo "PASS: current, older, unassigned and scoped committees; term changes and no current term.\n";
