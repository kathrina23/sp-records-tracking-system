<?php $personalNotesPanelActive = !empty($personalNotesPanelActive); ?>
<div class="division-tab-panel division-notes-panel<?= $personalNotesPanelActive ? ' active' : '' ?>" data-division-panel="notes">
    <div class="division-notes-heading">
        <div>
            <h2>Personal Notes</h2>
            <p class="muted">
                <?= $isDashboardMonitor
                    ? 'These personal notes and reminders belong to the selected user and are shown read-only to the Administrator.'
                    : 'These notes and reminders are personal to your dashboard.' ?>
                They are never shown in the tagged record's updates or movement history.
            </p>
        </div>
        <?php if ($divisionChiefDashboard['notes']): ?>
            <span class="division-note-total"><?= count($divisionChiefDashboard['notes']) ?> <?= count($divisionChiefDashboard['notes']) === 1 ? 'note' : 'notes' ?></span>
        <?php endif; ?>
    </div>

    <?php if (!$divisionChiefDashboard['notes_available']): ?>
        <p class="muted">Personal notes are not available yet. Import <strong>database/migration_division_chief_notes_20260904.sql</strong> to enable this tab.</p>
    <?php else: ?>
        <div class="division-reminder-alert" data-note-reminder-alert role="status" <?= (int) $divisionChiefDashboard['due_reminder_count'] === 0 ? 'hidden' : '' ?>>
            <span class="division-reminder-alert-icon" aria-hidden="true">⏰</span>
            <div>
                <strong>Personal reminder due</strong>
                <p data-note-reminder-message>You have <?= (int) $divisionChiefDashboard['due_reminder_count'] ?> due <?= (int) $divisionChiefDashboard['due_reminder_count'] === 1 ? 'reminder' : 'reminders' ?>.</p>
            </div>
        </div>

        <?php if (!$isDashboardMonitor): ?>
        <form class="division-note-composer" method="post" action="<?= url('/division_chief_note.php') ?>">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="create">
            <label class="division-note-message">Note
                <textarea name="note_text" rows="4" maxlength="2000" placeholder="Write a reminder, follow-up, or important detail..." required></textarea>
            </label>
            <div class="division-note-record personal-note-record-picker" data-personal-note-record-picker>
                <label for="personal-note-record-search">Tag a Record</label>
                <div class="personal-note-record-search-wrap">
                    <input id="personal-note-record-search" type="search" data-personal-note-record-search
                        placeholder="Type communication no., title, origin, or client" autocomplete="off"
                        role="combobox" aria-autocomplete="list" aria-controls="personal-note-record-suggestions"
                        aria-expanded="false" required>
                    <input type="hidden" name="record_id" data-personal-note-record-id>
                    <div id="personal-note-record-suggestions" class="personal-note-record-suggestions"
                        data-personal-note-record-suggestions role="listbox" hidden></div>
                </div>
                <span class="personal-note-record-help">Type at least 2 characters, then select a suggested record.</span>
            </div>
            <div class="division-note-reminder-input" data-personal-note-reminder-picker>
                <label for="personal-note-reminder-date">Remind Me <span>Optional</span></label>
                <div class="personal-note-reminder-control">
                    <input id="personal-note-reminder-date" type="date" min="<?= e(date('Y-m-d')) ?>"
                        data-personal-note-reminder-date aria-label="Reminder date">
                    <button type="button" class="personal-note-reminder-open" data-personal-note-reminder-open
                        aria-label="Open date picker" title="Choose a date">🗓</button>
                    <select id="personal-note-reminder-time" data-personal-note-reminder-time aria-label="Reminder time">
                        <option value="">Select time</option>
                        <?php for ($reminderMinute = 0; $reminderMinute < 1440; $reminderMinute += 15): ?>
                            <?php
                                $reminderHour24 = intdiv($reminderMinute, 60);
                                $reminderMinutePart = $reminderMinute % 60;
                                $reminderHour12 = $reminderHour24 % 12 ?: 12;
                                $reminderTimeValue = sprintf('%02d:%02d', $reminderHour24, $reminderMinutePart);
                                $reminderTimeLabel = sprintf('%d:%02d %s', $reminderHour12, $reminderMinutePart, $reminderHour24 < 12 ? 'AM' : 'PM');
                            ?>
                            <option value="<?= e($reminderTimeValue) ?>"><?= e($reminderTimeLabel) ?></option>
                        <?php endfor; ?>
                    </select>
                    <input type="hidden" name="reminder_at" data-personal-note-reminder-input>
                </div>
                <div class="personal-note-reminder-presets" aria-label="Quick reminder choices">
                    <button type="button" data-reminder-preset="hour">In 1 hour</button>
                    <button type="button" data-reminder-preset="tomorrow">Tomorrow 9 AM</button>
                    <button type="button" data-reminder-preset="monday">Next Monday</button>
                    <button type="button" class="personal-note-reminder-clear" data-reminder-preset="clear" hidden>Clear</button>
                </div>
                <span class="personal-note-reminder-summary" data-personal-note-reminder-summary aria-live="polite">No reminder set</span>
            </div>
            <button class="btn division-note-add" type="submit">Add Note</button>
        </form>
        <?php else: ?>
            <p class="dashboard-monitor-note">Personal Notes are visible in this complete dashboard view. Note changes remain with the signed-in user.</p>
        <?php endif; ?>

        <div class="division-note-grid">
            <?php foreach ($divisionChiefDashboard['notes'] as $note): ?>
                <?php
                    $noteReminderTimestamp = strtotime((string) ($note['reminder_at'] ?? ''));
                    $noteHasReminder = $noteReminderTimestamp !== false;
                    $noteReminderIsDue = $noteHasReminder && $noteReminderTimestamp <= time();
                ?>
                <article class="division-sticky-note<?= $noteReminderIsDue ? ' reminder-due' : '' ?>"<?= $noteHasReminder ? ' data-reminder-at="' . e(date('c', $noteReminderTimestamp)) . '"' : '' ?>>
                    <a class="division-note-record-tag record-view-action" href="<?= url('/record_view.php?id=') ?><?= (int) $note['record_id'] ?>">
                        <span>Tagged Record</span>
                        <strong><?= e($note['control_number']) ?></strong>
                    </a>
                    <p class="division-note-text"><?= nl2br(e($note['note_text'])) ?></p>
                    <?php if ($noteHasReminder): ?>
                        <div class="division-note-reminder<?= $noteReminderIsDue ? ' reminder-due' : '' ?>">
                            <span aria-hidden="true">⏰</span>
                            <div>
                                <strong data-note-reminder-state><?= $noteReminderIsDue ? 'Reminder due' : 'Reminder' ?></strong>
                                <time datetime="<?= e(date('c', $noteReminderTimestamp)) ?>"><?= e(display_datetime($note['reminder_at'])) ?></time>
                            </div>
                        </div>
                    <?php endif; ?>
                    <div class="division-note-record-details">
                        <strong><?= e(display_record_title($note['record_title'])) ?></strong>
                        <span><?= e($note['committee_name'] ?? '') ?><?= trim((string) ($note['committee_name'] ?? '')) !== '' ? ' · ' : '' ?><?= e($note['record_status']) ?></span>
                    </div>
                    <footer class="division-note-footer">
                        <time datetime="<?= e($note['updated_at']) ?>">Updated <?= e(display_datetime($note['updated_at'])) ?></time>
                        <?php if (!$isDashboardMonitor): ?>
                            <div class="division-note-actions">
                                <details class="division-note-editor">
                                    <summary>Edit</summary>
                                    <form method="post" action="<?= url('/division_chief_note.php') ?>">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="update">
                                        <input type="hidden" name="note_id" value="<?= (int) $note['id'] ?>">
                                        <input type="hidden" name="record_id" value="<?= (int) $note['record_id'] ?>">
                                        <textarea name="note_text" rows="5" maxlength="2000" required><?= e($note['note_text']) ?></textarea>
                                        <label>Remind Me
                                            <input type="datetime-local" name="reminder_at" value="<?= $noteHasReminder ? e(date('Y-m-d\TH:i', $noteReminderTimestamp)) : '' ?>">
                                            <span>Leave blank to remove the reminder.</span>
                                        </label>
                                        <button class="btn small-btn" type="submit">Save</button>
                                    </form>
                                </details>
                                <form method="post" action="<?= url('/division_chief_note.php') ?>" onsubmit="return confirm('Delete this personal note?');">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="note_id" value="<?= (int) $note['id'] ?>">
                                    <button class="division-note-delete" type="submit">Delete</button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </footer>
                </article>
            <?php endforeach; ?>
            <?php if (!$divisionChiefDashboard['notes']): ?>
                <div class="division-notes-empty">
                    <span aria-hidden="true">✎</span>
                    <strong>No personal notes yet</strong>
                    <p>Add your first note and connect it to a record above.</p>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
<?php $personalNotesPanelActive = false; ?>
