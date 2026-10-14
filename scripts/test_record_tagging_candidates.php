<?php
declare(strict_types=1);

require __DIR__ . '/../app/db.php';

// Temporary records shadow the real table only within this test connection.
// Official records are never changed.
$pdo = db();
$pdo->exec('CREATE TEMPORARY TABLE records (
    id INT PRIMARY KEY, control_number VARCHAR(30), title VARCHAR(100),
    document_type VARCHAR(100), committee_id INT NULL, updated_at DATETIME
)');
try {
    $insert = $pdo->prepare('INSERT INTO records VALUES (?, ?, ?, ?, NULL, ?)');
    for ($number = 1; $number <= 250; $number++) {
        $insert->execute([$number, sprintf('L-%05d-2026', $number), 'Test record',
            'Committee Referrals', '2026-10-14 12:00:00']);
    }
    $insert->execute([251, 'L-00165-2025', 'Older record', 'Committee Referrals', '2025-01-01 00:00:00']);
    $insert->execute([252, '', 'Empty number', 'Committee Referrals', '2026-10-14 12:00:00']);

    $source = file_get_contents(__DIR__ . '/../public/record_form.php');
    $start = strpos($source, '    $candidateStmt = db()->prepare(');
    $end = strpos($source, '    foreach ($mergeCandidateRecords', $start);
    if ($start === false || $end === false) {
        throw new RuntimeException('Candidate query block missing');
    }
    $queryBlock = substr($source, $start, $end - $start);
    foreach ([0, 165] as $id) {
        eval($queryBlock);
        $numbers = array_column($mergeCandidateRecords, 'control_number');
        if (count($numbers) !== ($id ? 250 : 251)
            || !in_array('L-00165-2025', $numbers, true)
            || in_array('', $numbers, true)
            || ($id && in_array('L-00165-2026', $numbers, true))) {
            throw new RuntimeException('Older targets missing or excluded records included');
        }
    }
    echo "PASS: more than 200 targets, older records, current-record exclusion, and empty-number exclusion.\n";
} finally {
    $pdo->exec('DROP TEMPORARY TABLE records');
}
