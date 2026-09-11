<div class="division-tab-panel" data-division-panel="for-plenary">
    <h2>For Plenary</h2>
    <p class="muted">Committee Referrals and Certified Urgent records ready for or scheduled for plenary.</p>
    <div class="actions plenary-print-controls">
        <a class="btn" href="<?= e(dashboard_action_url('/for_plenary_print.php')) ?>" target="_blank">Print Result</a>
    </div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Communication No.</th><th class="title-column">Title</th><th>Committee</th><th class="status-column">Status</th><th class="updated-column">Updated</th><th>Action</th></tr></thead>
            <tbody>
            <?php foreach ($citySecretaryDashboard['for_plenary'] as $record): ?>
                <?php
                    $committeeRowsForRecord = record_committee_rows((int) $record['id'], !empty($record['committee_id']) ? (int) $record['committee_id'] : null);
                    $committeeNamesForRecord = $committeeRowsForRecord
                        ? implode('; ', array_map(fn ($row) => $row['committee_name'], $committeeRowsForRecord))
                        : (($record['document_type'] ?? '') === 'Certified Urgent' ? 'Certified Urgent' : ($record['committee_name'] ?? 'Unassigned'));
                    $proposedLabel = '';
                    $proposedNumber = '';
                    if (trim((string) ($record['proposed_ordinance_number'] ?? '')) !== '') {
                        $proposedLabel = 'Proposed Ordinance';
                        $proposedNumber = (string) $record['proposed_ordinance_number'];
                    } elseif (trim((string) ($record['proposed_resolution_number'] ?? '')) !== '') {
                        $proposedLabel = 'Proposed Resolution';
                        $proposedNumber = (string) $record['proposed_resolution_number'];
                    }
                    $scheduledDate = ($record['status'] ?? '') === 'Scheduled for Plenary'
                        && trim((string) ($record['plenary_session_date'] ?? '')) !== ''
                            ? display_date($record['plenary_session_date'])
                            : '';
                ?>
                <tr>
                    <td><?= control_number_link($record) ?></td>
                    <td><?= e(display_record_title(record_title_for_current_user($record))) ?></td>
                    <td><?= e($committeeNamesForRecord) ?></td>
                    <td><span class="badge <?= e(status_class($record['status'])) ?>"><?= e($record['status']) ?></span></td>
                    <td><?= e(display_datetime($record['updated_at'] ?? '')) ?></td>
                    <td class="center-cell for-plenary-action-cell">
                        <div class="for-plenary-actions">
                            <a class="print-link small-action-link record-view-action" href="<?= e(url('/record_view.php?') . http_build_query([
                                'id' => (int) $record['id'],
                                'popup' => 1,
                                'return' => 'dashboard',
                                'return_url' => '/dashboard.php?division_tab=for-plenary',
                            ])) ?>">View Record</a>
                            <?php if (can_manage_plenary_record_attachments($record)): ?>
                                <a class="print-link small-action-link" href="<?= e(dashboard_action_url('/record_attachments_manage.php?' . http_build_query([
                                    'record_id' => (int) $record['id'],
                                    'popup' => 1,
                                    'return_url' => '/dashboard.php?division_tab=for-plenary',
                                ]))) ?>">Attachments on File</a>
                            <?php endif; ?>
                            <?php if (can_edit_record($record)): ?>
                                <a class="print-link small-action-link record-edit-action" href="<?= e(dashboard_action_url('/record_form.php?' . http_build_query([
                                    'id' => (int) $record['id'],
                                    'popup' => 1,
                                    'return' => 'dashboard',
                                    'return_url' => '/dashboard.php?division_tab=for-plenary',
                                ]))) ?>">Edit</a>
                            <?php endif; ?>
                            <?php if (can_update_record_status($record)): ?>
                                <a class="print-link small-action-link record-update-action" href="<?= e(dashboard_action_url('/record_update.php?' . http_build_query([
                                    'id' => (int) $record['id'],
                                    'popup' => 1,
                                    'return' => 'dashboard',
                                    'division_tab' => 'for-plenary',
                                ]))) ?>">Update Status</a>
                            <?php endif; ?>
                            <?php if (can_assign_plenary_numbers($record)): ?>
                                <a class="print-link small-action-link" href="<?= e(dashboard_action_url('/plenary_number_form.php?' . http_build_query([
                                    'id' => (int) $record['id'],
                                    'popup' => 1,
                                    'return' => 'dashboard',
                                    'city_tab' => 'for-plenary',
                                ]))) ?>">Assign Proposed No.</a>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php if ($proposedNumber !== '' || $scheduledDate !== ''): ?>
                    <tr class="plenary-detail-row">
                        <td colspan="6">
                            <?php if ($proposedNumber !== ''): ?><strong><?= e($proposedLabel) ?>:</strong> <?= e($proposedNumber) ?><?php endif; ?>
                            <?php if ($proposedNumber !== '' && $scheduledDate !== ''): ?><span class="detail-separator">|</span><?php endif; ?>
                            <?php if ($scheduledDate !== ''): ?><strong>Scheduled Plenary Date:</strong> <?= e($scheduledDate) ?><?php endif; ?>
                        </td>
                    </tr>
                <?php endif; ?>
            <?php endforeach; ?>
            <?php if (!$citySecretaryDashboard['for_plenary']): ?><tr><td colspan="6">No records are currently for plenary.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
