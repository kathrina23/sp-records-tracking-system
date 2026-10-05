<?php
// All fixture writes use connection-local TEMPORARY tables, never official records.
require __DIR__ . '/../app/db.php';
require __DIR__ . '/../app/helpers.php';
require __DIR__ . '/../app/record_merges.php';
function current_user(): array { return $_SESSION['user']; }
function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function expect_failure(callable $call, string $message): void {
    try { $call(); } catch (RuntimeException $error) { return; }
    throw new RuntimeException($message);
}
$pdo = db();
foreach (array_merge(['records'], MERGE_MOVED_TABLES, ['record_committees', 'record_division_receipts']) as $table) {
    $ddl = $pdo->query('SHOW CREATE TABLE ' . $table)->fetch(PDO::FETCH_NUM)[1];
    $ddl = str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $ddl);
    $ddl = preg_replace('/^\s*CONSTRAINT .*\n/m', '', $ddl);
    $ddl = preg_replace('/,\n\)/', "\n)", $ddl);
    $ddl = preg_replace('/AUTO_INCREMENT=\d+/', 'AUTO_INCREMENT=1', $ddl);
    $pdo->exec($ddl);
}
$pdo->exec(str_replace('CREATE TABLE IF NOT EXISTS', 'CREATE TEMPORARY TABLE', file_get_contents(__DIR__ . '/../database/migration_record_merges.sql')));
$year = date('Y');
$_SESSION['user'] = ['id' => 1, 'role' => 'admin'];
$base = ['document_type' => 'Committee Referrals', 'origin' => 'Test origin', 'received_date' => date('Y-m-d'), 'status' => 'Received', 'current_location' => 'Committee Assignment', 'remarks' => 'Original remarks'];
$target = insert_separated_record($base + ['title' => 'Target', 'control_number' => "L-00000-$year"]);
$source = insert_separated_record($base + ['title' => 'Source', 'control_number' => "L-00001-$year"]);
$pdo->exec("INSERT INTO record_attachments (record_id,original_name,title,stored_name,mime_type,file_size) VALUES ($source,'source.pdf','Original attachment','unchanged.pdf','application/pdf',3145728),($target,'target.pdf',NULL,'target.pdf','application/pdf',42)");
$pdo->exec("INSERT INTO record_movements (record_id,to_status,to_location,notes) VALUES ($source,'Received','Committee Assignment','Source history')");
$pdo->exec("INSERT INTO record_committees (record_id,committee_id) VALUES ($source,1)");
$pdo->exec("INSERT INTO record_division_receipts (record_id,division_name) VALUES ($source,'Test division')");
$pdo->exec("INSERT INTO record_recipients (record_id,name) VALUES ($source,'Recipient')");
$pdo->exec("INSERT INTO division_chief_notes (record_id,user_id,note_text) VALUES ($source,1,'Private note')");
$pdo->exec("INSERT INTO committee_report_numbers (record_id,committee_id,report_year,sequence_no) VALUES ($source,1,$year,1)");
$pdo->beginTransaction();
capture_record_merge($source, $target, ['title' => 'Edited before merge']);
foreach (MERGE_MOVED_TABLES as $table) $pdo->exec("UPDATE $table SET record_id=$target WHERE record_id=$source");
// Temporary tables do not carry foreign keys; simulate the original cascade.
foreach (['records' => 'id', 'record_committees' => 'record_id', 'record_division_receipts' => 'record_id'] as $table => $field) $pdo->exec("DELETE FROM $table WHERE $field=$source");
$pdo->exec("INSERT INTO record_movements (record_id,to_status,to_location,notes) VALUES ($target,'Received','Committee Assignment','Merged record: L-00001-$year')");
$pdo->commit();
$mergeId = (int) active_record_merges($target)[0]['id'];
check(!has_legacy_record_merge(['id' => $target]), 'Tracked merge incorrectly treated as legacy');
check(merged_attachment_ids(['id' => $target]) === [1], 'Destination attachment included in source mapping');
$pdo->exec("UPDATE record_attachments SET title='Edited after merge' WHERE id=1");
// Occupy the old communication number to exercise safe renumbering.
insert_separated_record($base + ['title' => 'Reused number', 'control_number' => "L-00001-$year"]);
$_SESSION['user']['role'] = 'receiving_clerk';
expect_failure(fn () => restore_record_merge($mergeId, $target, 'Denied'), 'Unauthorized unmerge succeeded');
$_SESSION['user']['role'] = 'city_secretary';
$pdo->beginTransaction();
$newId = restore_record_merge($mergeId, $target, 'Restored title');
$pdo->commit();
$restored = $pdo->query("SELECT * FROM records WHERE id=$newId")->fetch();
check($restored['control_number'] === "L-00002-$year", 'Reused communication number collided');
check($restored['title'] === 'Restored title' && $restored['remarks'] === 'Original remarks', 'Record details not restored');
$attachment = $pdo->query('SELECT * FROM record_attachments WHERE id=1')->fetch();
check((int) $attachment['record_id'] === $newId && $attachment['title'] === 'Edited after merge' && $attachment['stored_name'] === 'unchanged.pdf', 'Attachment or subsequent edits lost');
check((int) $pdo->query('SELECT record_id FROM record_attachments WHERE id=2')->fetchColumn() === $target, 'Destination attachment moved');
foreach (['division_chief_notes', 'record_recipients', 'committee_report_numbers', 'record_committees', 'record_division_receipts'] as $table) {
    check((int) $pdo->query("SELECT COUNT(*) FROM $table WHERE record_id=$newId")->fetchColumn() === 1, "$table not restored");
}
expect_failure(fn () => restore_record_merge($mergeId, $target, 'Duplicate'), 'Repeated restore succeeded');
check(!active_record_merges($target) && !has_legacy_record_merge(['id' => $target]), 'Restored merge remains active');
// Legacy separation: explicit selection, wrong-owner rejection, and rollback.
$pdo->exec("INSERT INTO record_movements (record_id,to_status,to_location,notes) VALUES ($target,'Received','Committee Assignment','Merged record: old record')");
$record = $pdo->query("SELECT * FROM records WHERE id=$target")->fetch();
check(has_legacy_record_merge($record), 'Legacy merge not detected');
$pdo->beginTransaction();
expect_failure(fn () => separate_merged_attachments($record, [1], 'Wrong owner'), 'Cross-record attachment accepted');
$pdo->rollBack();
$pdo->beginTransaction();
$separated = separate_merged_attachments($record, [2], 'Separated PDF');
check((int) $pdo->query('SELECT record_id FROM record_attachments WHERE id=2')->fetchColumn() === $separated, 'Legacy attachment did not move');
$pdo->rollBack();
check((int) $pdo->query('SELECT record_id FROM record_attachments WHERE id=2')->fetchColumn() === $target, 'Rollback did not restore attachment');
$_SESSION['user']['role'] = 'admin';
$pdo->beginTransaction();
$separated = separate_merged_attachments($record, [2], 'Separated PDF');
$pdo->commit();
check($pdo->query("SELECT title FROM records WHERE id=$separated")->fetchColumn() === 'Separated PDF', 'Admin separation failed');
// Restore a second merge when the old number is still free.
$pdo->beginTransaction();
capture_record_merge($separated, $target, []);
foreach (MERGE_MOVED_TABLES as $table) $pdo->exec("UPDATE $table SET record_id=$target WHERE record_id=$separated");
$oldNumber = $pdo->query("SELECT control_number FROM records WHERE id=$separated")->fetchColumn();
$pdo->exec("DELETE FROM records WHERE id=$separated");
$pdo->commit();
$merges = active_record_merges($target);
expect_failure(fn () => capture_record_merge($target, $newId, []), 'Nested source merge accepted');
// Render the actual page template with synthetic data, without opening a private record.
$recordId = $target;
$legacy = true;
$attachments = [['id' => 2, 'original_name' => 'test.pdf', 'title' => '<script>test</script>']];
$page = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../public/record_unmerge.php'));
$templateStart = strpos($page, '?>' . "\n" . '<section class="panel">');
$templateEnd = strrpos($page, '<?php require');
ob_start();
eval(substr($page, $templateStart, $templateEnd - $templateStart));
$html = ob_get_clean();
$dom = new DOMDocument();
@$dom->loadHTML($html);
$xpath = new DOMXPath($dom);
check($xpath->query('//form')->length === 3, 'Restore, title, and separation forms not rendered');
check($xpath->query('//form/input[@name="csrf_token"]')->length === 3, 'Missing CSRF field');
check(!str_contains($html, '<script>test</script>') && str_contains($html, '&lt;script&gt;test&lt;/script&gt;'), 'Attachment title was not escaped');
$pdo->beginTransaction();
$secondRestore = restore_record_merge((int) $merges[0]['id'], $target, 'Second restore');
$pdo->commit();
check($pdo->query("SELECT control_number FROM records WHERE id=$secondRestore")->fetchColumn() === $oldNumber, 'Available original number was not reused');
// Pending tags must not be inferred from the destination record's merge history.
$form = file_get_contents(__DIR__ . '/../public/record_form.php');
$start = strpos($form, 'function pending_existing_record_target');
$end = strpos($form, 'function strip_pending_existing_record_tag');
eval(substr($form, $start, $end - $start));
check(pending_existing_record_target("Tagged as update to existing Communication Number L-00000-$year.\nMerged record: old") === '', 'Completed merge mistaken for pending tag');
check(pending_existing_record_target("Tagged as update to existing Communication Number L-00000-$year. Pending SP Secretary review.") === "L-00000-$year", 'Pending tag not detected');
echo "PASS: restore, number collisions, roles, attachment ownership, edits, history, legacy separation, rollback, and pending tags.\n";
