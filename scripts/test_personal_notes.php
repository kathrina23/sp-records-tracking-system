<?php
// Isolated handler and rendering regression checks. No database or session writes.
error_reporting(E_ALL);
set_error_handler(static function ($severity, $message) { throw new RuntimeException($message); });
date_default_timezone_set('Asia/Manila');
class NoteRedirect extends Exception {}
class NoteTestDb {
    public array $writes = [];
    public ?array $existing = null;
    public function prepare($sql) { return new NoteTestStatement($this, $sql); }
    public function lastInsertId() { return 101; }
}
class NoteTestStatement {
    public function __construct(private NoteTestDb $db, private string $sql) {}
    public function execute($params) { $this->db->writes[] = [$this->sql, $params]; }
    public function fetch() { return str_contains($this->sql, 'FROM records') ? false : $this->db->existing; }
}
function db() { return $GLOBALS['testDb']; }
function current_user() { return ['id' => 7, 'role' => 'city_secretary']; }
function require_login() {}
function verify_csrf() {}
function ensure_division_chief_notes_schema() { return true; }
function redirect($path) { throw new NoteRedirect($path); }
function flash($text, $kind = '') { $GLOBALS['flashes'][] = $text; }
function audit_log(...$args) {}
function can_view_record($record) { return false; }
function e($text) { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
function url($path) { return '/system' . $path; }
function csrf_token() { return 'test'; }
function display_datetime($date) { return (string) $date; }
function display_record_title($title) { return (string) $title; }
function check($condition, $label) {
    if (!$condition) { throw new RuntimeException('FAIL: ' . $label); }
    echo 'PASS: ' . $label . PHP_EOL;
}
$handler = file_get_contents(__DIR__ . '/../public/division_chief_note.php');
$handler = preg_replace('/^<\?php\s*/', '', $handler);
$handler = str_replace("require_once __DIR__ . '/../app/auth.php';", '', $handler);
function runNote($post, $existing = null) {
    global $handler;
    $GLOBALS['testDb'] = new NoteTestDb();
    $GLOBALS['testDb']->existing = $existing;
    $GLOBALS['flashes'] = [];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = $post;
    try { eval($handler); } catch (NoteRedirect $redirect) {
        check($redirect->getMessage() === '/dashboard.php?city_tab=notes', 'notes return path');
    }
    return $GLOBALS['testDb']->writes;
}
$writes = runNote(['action' => 'create', 'note_text' => 'Unt agged note']);
check(count($writes) === 1 && $writes[0][1] === [7, null, 'Unt agged note', null], 'create without a record tag');
$writes = runNote(['action' => 'create', 'note_text' => 'Invalid tag', 'record_id' => 999]);
check(count($writes) === 1 && str_contains($writes[0][0], 'SELECT'), 'provided record still requires authorization');
$note = ['id' => 12, 'record_id' => null, 'reminder_at' => null, 'completed_at' => null, 'archived_at' => null];
$writes = runNote(['action' => 'update', 'note_id' => 12, 'note_text' => 'Edited'], $note);
check($writes[1][1] === [null, 'Edited', null, 12, 7], 'edit an untagged note and scope write to owner');
$writes = runNote(['action' => 'complete', 'note_id' => 12], $note);
check(str_contains($writes[1][0], 'completed_at = COALESCE') && $writes[1][1] === [12, 7], 'mark done without note text or tag');
$writes = runNote(['action' => 'archive', 'note_id' => 12], $note);
check(count($writes) === 1, 'reject archive before completion');
$note['completed_at'] = '2026-01-01 09:00:00';
$writes = runNote(['action' => 'archive', 'note_id' => 12], $note);
check(str_contains($writes[1][0], 'archived_at = COALESCE') && $writes[1][1] === [12, 7], 'archive completed note with owner scope');
$base = ['id' => 1, 'record_id' => null, 'note_text' => 'Sample', 'reminder_at' => '2020-01-01 09:00:00', 'completed_at' => null, 'archived_at' => null, 'updated_at' => '2020-01-01 09:00:00'];
$divisionChiefDashboard = ['notes_available' => true, 'due_reminder_count' => 1, 'notes' => [
    $base,
    array_replace($base, ['id' => 2, 'completed_at' => '2020-01-01 10:00:00']),
    array_replace($base, ['id' => 3, 'completed_at' => '2020-01-01 10:00:00', 'archived_at' => '2020-01-01 11:00:00'])
]];
$isDashboardMonitor = false;
ob_start();
require __DIR__ . '/../app/partials/personal_notes_dashboard_panel.php';
$html = ob_get_clean();
check(substr_count($html, 'class="division-sticky-note reminder-due"') === 1, 'only active due note is red');
check(substr_count($html, 'class="division-sticky-note note-done"') === 2, 'completed and archived notes use gray state');
check(str_contains($html, 'Archived Notes (1)') && substr_count($html, '>Archive</button>') === 1, 'archive section and completed-only archive action');
check(!str_contains($html, 'record_view.php?id=0'), 'untagged notes do not link to nonexistent record');
check(!preg_match('/aria-expanded="false"\s+required/', $html), 'record picker is optional');
$isDashboardMonitor = true;
ob_start();
require __DIR__ . '/../app/partials/personal_notes_dashboard_panel.php';
$html = ob_get_clean();
check(!str_contains($html, '<form'), 'monitor view remains read only');
echo "All personal note checks passed.\n";
