<?php
require __DIR__ . '/../app/helpers.php';
function check(bool $ok, string $message): void {
    if (!$ok) { throw new RuntimeException($message); }
}
// Exercise the actual form decision without connecting to the production database.
$source = file_get_contents(__DIR__ . '/../public/record_form.php');
check((bool) preg_match('/    \$canFinalizeExistingTag =.*?;/s', $source, $match), 'Merge decision missing');
foreach (['admin', 'city_secretary', 'receiving_clerk'] as $role) {
    $_SESSION['user'] = ['role' => $role];
    foreach (array_merge(administrative_document_types(), ['Committee Referrals']) as $type) {
        $record = ['document_type' => $type];
        foreach ([true, false] as $isRecordCorrection) {
            eval($match[0]);
            $expected = $role !== 'receiving_clerk' && (!$isRecordCorrection || is_administrative_document_type($type));
            check($canFinalizeExistingTag === $expected, "Incorrect merge eligibility: $role / $type");
        }
    }
}
class NumberDatabase {
    public array $numbers = [];
    public function prepare(string $sql): object {
        return new class($this) {
            private array $rows = [];
            public function __construct(private NumberDatabase $db) {}
            public function execute(array $params): void {
                $this->rows = [];
                foreach ($this->db->numbers as $number) {
                    if (preg_match('/' . $params[0] . '/', $number)) {
                        $this->rows[] = (int) substr($number, 2, 5);
                    }
                }
                sort($this->rows);
            }
            public function fetchColumn(): mixed { return array_shift($this->rows) ?? false; }
        };
    }
}
function db(): NumberDatabase { global $numberDb; return $numberDb; }
$numberDb = new NumberDatabase();
$year = date('Y');
foreach ([administrative_document_types()[0] => 'A', 'Committee Referrals' => 'L'] as $type => $prefix) {
    $numberDb->numbers = ["$prefix-00000-$year", "$prefix-00001-$year", "$prefix-00002-$year"];
    check(next_control_number($type) === "$prefix-00003-$year", 'Occupied numbers must not be reused');
    unset($numberDb->numbers[1]); // The completed merge removes the source record.
    check(next_control_number($type) === "$prefix-00001-$year", 'Merged source number must be reused');
    $numberDb->numbers[] = "$prefix-00001-$year";
    check(next_control_number($type) === "$prefix-00003-$year", 'Reassigned number must not be reused again');
}
echo "PASS: administrative merge permissions and A/L number reuse.\n";