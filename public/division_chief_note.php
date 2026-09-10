<?php

require_once __DIR__ . '/../app/auth.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/dashboard.php');
}

verify_csrf();

$user = current_user() ?? [];
$userId = (int) ($user['id'] ?? 0);
$userRole = (string) ($user['role'] ?? '');
if ($userId <= 0) {
    http_response_code(403);
    exit('A signed-in account is required to manage personal notes.');
}
$notesReturnPath = match (true) {
    in_array($userRole, ['admin', 'city_secretary', 'administrative_support'], true) => '/dashboard.php?city_tab=notes',
    in_array($userRole, ['division_chief', 'secretariat'], true) => '/dashboard.php?division_tab=notes',
    default => '/dashboard.php?tab=notes',
};

if (!ensure_division_chief_notes_schema()) {
    flash('Personal notes are not available yet. Please ask the administrator to apply the notes migration.', 'error');
    redirect($notesReturnPath);
}

$action = trim((string) ($_POST['action'] ?? 'create'));
if (!in_array($action, ['create', 'update', 'delete', 'complete', 'archive'], true)) {
    http_response_code(400);
    exit('Invalid note action.');
}

$noteId = (int) ($_POST['note_id'] ?? 0);
$existingNote = null;
if (in_array($action, ['update', 'delete', 'complete', 'archive'], true)) {
    $existingStmt = db()->prepare('SELECT id, record_id, reminder_at, completed_at, archived_at FROM division_chief_notes WHERE id = ? AND user_id = ? LIMIT 1');
    $existingStmt->execute([$noteId, $userId]);
    $existingNote = $existingStmt->fetch();
    if (!$existingNote) {
        http_response_code(404);
        exit('Personal note not found.');
    }
}

if (in_array($action, ['complete', 'archive'], true)) {
    if ($action === 'archive' && empty($existingNote['completed_at'])) {
        flash('Mark the note as done before archiving it.', 'error');
        redirect($notesReturnPath);
    }
    try {
        $column = $action === 'complete' ? 'completed_at' : 'archived_at';
        $stateStmt = db()->prepare('UPDATE division_chief_notes SET ' . $column . ' = COALESCE(' . $column . ', NOW()) WHERE id = ? AND user_id = ?');
        $stateStmt->execute([$noteId, $userId]);
        audit_log('personal_note_' . $action, $action === 'complete' ? 'Marked personal note as done.' : 'Archived personal note.', 'personal_note', $noteId);
        flash($action === 'complete' ? 'Personal note marked as done.' : 'Personal note archived.');
    } catch (Throwable $error) {
        error_log('Unable to change personal note state: ' . $error->getMessage());
        flash('Unable to change the note. Please try again.', 'error');
    }
    redirect($notesReturnPath);
}

if ($action === 'delete') {
    try {
        $deleteStmt = db()->prepare('DELETE FROM division_chief_notes WHERE id = ? AND user_id = ?');
        $deleteStmt->execute([$noteId, $userId]);
        audit_log(
            'personal_note_deleted',
            'Deleted a personal note tagged to record #' . (int) $existingNote['record_id'] . '.',
            'personal_note',
            $noteId
        );
        flash('Personal note deleted.');
    } catch (Throwable $error) {
        error_log('Unable to delete personal note: ' . $error->getMessage());
        flash('Unable to delete the personal note. Please try again.', 'error');
    }
    redirect($notesReturnPath);
}

$noteText = trim((string) ($_POST['note_text'] ?? ''));
$noteLength = function_exists('mb_strlen') ? mb_strlen($noteText, 'UTF-8') : strlen($noteText);
if ($noteText === '') {
    flash('Please enter a note before saving.', 'error');
    redirect($notesReturnPath);
}
if ($noteLength > 2000) {
    flash('Personal notes can contain up to 2,000 characters.', 'error');
    redirect($notesReturnPath);
}

$reminderInput = trim((string) ($_POST['reminder_at'] ?? ''));
$reminderAt = null;
if ($reminderInput !== '') {
    $reminderDate = DateTime::createFromFormat('!Y-m-d\TH:i', $reminderInput);
    $reminderErrors = DateTime::getLastErrors();
    $reminderIsValid = $reminderDate !== false
        && ($reminderErrors === false
            || ((int) $reminderErrors['warning_count'] === 0 && (int) $reminderErrors['error_count'] === 0))
        && $reminderDate->format('Y-m-d\TH:i') === $reminderInput;
    if (!$reminderIsValid) {
        flash('Please select a valid reminder date and time.', 'error');
        redirect($notesReturnPath);
    }

    $reminderAt = $reminderDate->format('Y-m-d H:i:00');
    $existingReminderAt = trim((string) ($existingNote['reminder_at'] ?? ''));
    if ($reminderDate->getTimestamp() <= time()
        && ($action === 'create' || $reminderAt !== $existingReminderAt)) {
        flash('Please set the reminder to a future date and time.', 'error');
        redirect($notesReturnPath);
    }
}

$recordId = (int) ($_POST['record_id'] ?? 0);
$record = null;
if ($recordId > 0) {
    $recordStmt = db()->prepare('SELECT r.* FROM records r WHERE r.id = ? LIMIT 1');
    $recordStmt->execute([$recordId]);
    $record = $recordStmt->fetch();
    if (!$record || !can_view_record($record)) {
        flash('The selected record is not available to your account.', 'error');
        redirect($notesReturnPath);
    }
} else {
    $recordId = null;
}

try {
    if ($action === 'create') {
        $noteStmt = db()->prepare('INSERT INTO division_chief_notes (user_id, record_id, note_text, reminder_at) VALUES (?, ?, ?, ?)');
        $noteStmt->execute([$userId, $recordId, $noteText, $reminderAt]);
        $noteId = (int) db()->lastInsertId();
        $auditAction = 'personal_note_created';
        $flashMessage = 'Personal note added.';
    } else {
        $noteStmt = db()->prepare('UPDATE division_chief_notes SET record_id = ?, note_text = ?, reminder_at = ? WHERE id = ? AND user_id = ?');
        $noteStmt->execute([$recordId, $noteText, $reminderAt, $noteId, $userId]);
        $auditAction = 'personal_note_updated';
        $flashMessage = 'Personal note updated.';
    }

    audit_log(
        $auditAction,
        ($action === 'create' ? 'Created' : 'Updated') . ' a personal note' . ($record ? ' tagged to ' . $record['control_number'] : '')
            . ($reminderAt !== null ? ' with a reminder for ' . display_datetime($reminderAt) : '') . '.',
        'personal_note',
        $noteId
    );
    flash($flashMessage);
} catch (Throwable $error) {
    error_log('Unable to save personal note: ' . $error->getMessage());
    flash('Unable to save the personal note. Please try again.', 'error');
}
redirect($notesReturnPath);
