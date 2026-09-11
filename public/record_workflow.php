<?php

require_once __DIR__ . '/../app/auth.php';
require_login();
ensure_plenary_number_schema();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/records.php');
}

verify_csrf();

$id = (int) ($_POST['record_id'] ?? 0);
$action = $_POST['action'] ?? '';

$stmt = db()->prepare('SELECT * FROM records WHERE id = ?');
$stmt->execute([$id]);
$record = $stmt->fetch();

if (!$record) {
    http_response_code(404);
    exit('Record not found.');
}

if ($action === 'assign_committee') {
    if (!can_assign_referral_committee($record) || ($record['status'] ?? '') !== 'Received') {
        http_response_code(403);
        exit('Only the City Secretary can review and assign Committee Referrals.');
    }

    $committeeIds = [];
    if (isset($_POST['committee_ids']) && is_array($_POST['committee_ids'])) {
        $committeeIds = array_values(array_unique(array_filter(array_map('intval', $_POST['committee_ids']))));
    } elseif (($_POST['committee_id'] ?? '') !== '') {
        $committeeIds = [(int) $_POST['committee_id']];
    }
    $leadCommitteeId = (int) ($_POST['lead_committee_id'] ?? 0);
    if (!$leadCommitteeId && count($committeeIds) === 1) {
        $leadCommitteeId = (int) $committeeIds[0];
    }
    if ($leadCommitteeId && $committeeIds) {
        if (!in_array($leadCommitteeId, $committeeIds, true)) {
            flash('Please choose the lead committee from the selected committees.', 'error');
            redirect('/record_view.php?id=' . $id);
        }
        $committeeIds = array_values(array_unique(array_merge(
            [$leadCommitteeId],
            array_values(array_diff($committeeIds, [$leadCommitteeId]))
        )));
    }
    $committeeId = $committeeIds[0] ?? 0;
    $remarks = trim($_POST['remarks'] ?? '');

    if (!$committeeId || $remarks === '') {
        flash('Please select a committee and add remarks.', 'error');
        redirect('/record_view.php?id=' . $id);
    }
    if (count($committeeIds) > 30) {
        flash('Please select up to 30 committees only.', 'error');
        redirect('/record_view.php?id=' . $id);
    }

    $committeeStmt = db()->prepare('SELECT name FROM committees WHERE id = ?');
    $committeeStmt->execute([$committeeId]);
    $committeeName = $committeeStmt->fetchColumn();

    if (!$committeeName) {
        flash('Selected committee was not found.', 'error');
        redirect('/record_view.php?id=' . $id);
    }

    $assignedChief = null;
    $committeeNames = [];
    foreach ($committeeIds as $selectedCommitteeId) {
        $chief = division_chief_for_committee($selectedCommitteeId);
        if (!$chief) {
            flash('Please assign a Division Chief to all selected committees first.', 'error');
            redirect('/record_view.php?id=' . $id);
        }
        $assignedChief = $assignedChief ?: $chief;
        $nameStmt = db()->prepare('SELECT name FROM committees WHERE id = ?');
        $nameStmt->execute([$selectedCommitteeId]);
        $committeeNames[] = (string) $nameStmt->fetchColumn();
    }
    $assignmentNote = count($committeeIds) > 1
        ? referral_group_label(count($committeeIds)) . ' referral to ' . count($committeeIds) . ' committees: ' . implode(', ', array_filter($committeeNames)) . '. '
        : '';

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $update = $pdo->prepare("UPDATE records SET committee_id = ?, assigned_user_id = ?, remarks = ?, status = 'Pending to the Committee', current_location = ?, updated_by = ? WHERE id = ?");
        $update->execute([$committeeId, (int) $assignedChief['id'], trim($assignmentNote . $remarks), 'Admin Receiving Section - For Printing', current_user()['id'], $id]);
        save_record_committees($id, $committeeIds);

        $movement = $pdo->prepare('INSERT INTO record_movements (record_id, from_status, to_status, from_location, to_location, notes, record_title, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $movement->execute([
            $id,
            $record['status'],
            'Pending to the Committee',
            $record['current_location'],
            'Admin Receiving Section - For Printing',
            $assignmentNote . 'Reviewed and assigned to ' . $committeeName . ' under Division Chief ' . $assignedChief['name'] . '. Ready for Committee Referral printing. ' . $remarks,
            $record['title'],
            current_user()['id'],
        ]);

        $pdo->commit();
        audit_log('referral_committee_assignment', 'Reviewed and assigned Committee Referral to ' . $committeeName . '.', 'record', $id);
        flash('Committee Referral reviewed and ready for printing.');
    } catch (Throwable $error) {
        $pdo->rollBack();
        flash('Unable to assign committee. Please try again.', 'error');
    }

    redirect('/record_view.php?id=' . $id);
}

if ($action === 'act_administrative_document') {
    if (!can_act_on_administrative_document($record)) {
        http_response_code(403);
        exit('Only the City Secretary can act on administrative records.');
    }

    $remarks = trim($_POST['remarks'] ?? '');
    if ($remarks === '') {
        flash('Please add remarks or instructions for the Administrative Document.', 'error');
        redirect('/record_view.php?id=' . $id);
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $update = $pdo->prepare("UPDATE records SET remarks = ?, status = 'Completed', current_location = 'Admin Receiving Section - Acted Upon', updated_by = ? WHERE id = ?");
        $update->execute([$remarks, current_user()['id'], $id]);

        $movement = $pdo->prepare('INSERT INTO record_movements (record_id, from_status, to_status, from_location, to_location, notes, record_title, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $movement->execute([
            $id,
            $record['status'],
            'Completed',
            $record['current_location'],
            'Admin Receiving Section - Acted Upon',
            'City Secretary action/instruction: ' . $remarks,
            $record['title'],
            current_user()['id'],
        ]);

        $pdo->commit();
        audit_log('administrative_document_action', 'Acted on Administrative Document and notified the Admin Receiving Section.', 'record', $id);
        flash('Administrative Document marked acted upon. The Admin Receiving Section can now see the completed action.');
    } catch (Throwable $error) {
        $pdo->rollBack();
        flash('Unable to save action. Please try again.', 'error');
    }

    redirect('/record_view.php?id=' . $id);
}

if ($action === 'update_plenary_numbers') {
    $returnTarget = ($_POST['return'] ?? '') === 'dashboard' ? 'dashboard' : 'record';
    $cityTab = trim($_POST['city_tab'] ?? '');
    $returnUrl = '/record_view.php?id=' . $id;
    if ($returnTarget === 'dashboard') {
        $returnUrl = '/dashboard.php?city_tab=' . urlencode($cityTab !== '' ? $cityTab : 'for-plenary');
    }
    $formUrl = '/plenary_number_form.php?id=' . $id . '&popup=1&return=' . urlencode($returnTarget);
    if ($cityTab !== '') {
        $formUrl .= '&city_tab=' . urlencode($cityTab);
    }

    if (!can_assign_plenary_numbers($record)) {
        http_response_code(403);
        exit('Only the Laws and Rules Secretariat, City Secretary, or Administrator can update proposed numbers for For Plenary records.');
    }

    $proposedType = trim($_POST['proposed_type'] ?? '');
    $proposedNumber = trim($_POST['proposed_number'] ?? '');
    $remarks = trim($_POST['remarks'] ?? '');

    if (!in_array($proposedType, ['ordinance', 'resolution'], true) || $proposedNumber === '') {
        flash('Please choose Proposed Ordinance or Proposed Resolution, then enter the proposed number.', 'error');
        redirect($formUrl);
    }

    $currentYear = date('Y');
    if (!preg_match('/^\d{4}\s*-\s*/', $proposedNumber)) {
        $proposedNumber = $currentYear . '-' . ltrim($proposedNumber, " \t\n\r\0\x0B-");
    }
    $proposedNumber = preg_replace('/^(\d{4})\s*-\s*/', '$1-', $proposedNumber) ?: $proposedNumber;

    $proposedOrdinanceNumber = $proposedType === 'ordinance' ? $proposedNumber : '';
    $proposedResolutionNumber = $proposedType === 'resolution' ? $proposedNumber : '';
    $movementNotes = trim(implode("\n", array_filter([
        $proposedOrdinanceNumber !== '' ? 'Proposed Ordinance Number: ' . $proposedOrdinanceNumber : '',
        $proposedResolutionNumber !== '' ? 'Proposed Resolution Number: ' . $proposedResolutionNumber : '',
        $remarks !== '' ? 'Remarks: ' . $remarks : '',
    ])));

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $update = $pdo->prepare('UPDATE records SET proposed_ordinance_number = ?, proposed_resolution_number = ?, updated_by = ? WHERE id = ?');
        $update->execute([$proposedOrdinanceNumber !== '' ? $proposedOrdinanceNumber : null, $proposedResolutionNumber !== '' ? $proposedResolutionNumber : null, current_user()['id'], $id]);

        $movement = $pdo->prepare('INSERT INTO record_movements (record_id, from_status, to_status, from_location, to_location, notes, record_title, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $movement->execute([
            $id,
            $record['status'],
            $record['status'],
            $record['current_location'],
            $record['current_location'],
            $movementNotes,
            $record['title'],
            current_user()['id'],
        ]);

        $pdo->commit();
        audit_log('plenary_number_update', 'Updated proposed ordinance/resolution number for ' . ($record['control_number'] ?? 'record') . '.', 'record', $id);
        flash('Proposed Ordinance / Resolution Number updated.');
    } catch (Throwable $error) {
        $pdo->rollBack();
        flash('Unable to save the proposed number. Please try again.', 'error');
    }

    redirect($returnUrl);
}

if ($action === 'mark_referral_printed') {
    if (!in_array(current_user()['role'] ?? '', ['admin', 'receiving_clerk'], true)) {
        http_response_code(403);
        exit('Only the Administrator or Admin Receiving Section can mark this referral as printed.');
    }

    if (($record['document_type'] ?? '') !== 'Committee Referrals' || !in_array($record['status'] ?? '', ['Assigned to the Committee', 'Pending to the Committee'], true)) {
        flash('This record is not waiting for Committee Referral printing.', 'error');
        redirect('/record_view.php?id=' . $id);
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $update = $pdo->prepare("UPDATE records SET status = 'Pending to the Committee', current_location = 'Division Chief / Committee Secretariat', updated_by = ? WHERE id = ?");
        $update->execute([current_user()['id'], $id]);

        $movement = $pdo->prepare('INSERT INTO record_movements (record_id, from_status, to_status, from_location, to_location, notes, record_title, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $movement->execute([
            $id,
            $record['status'],
            'Pending to the Committee',
            $record['current_location'],
            'Division Chief / Committee Secretariat',
        'Committee Referral printed and forwarded to the assigned committee.',
            $record['title'],
            current_user()['id'],
        ]);

        $pdo->commit();
        audit_log('committee_referral_printed', 'Marked Committee Referral as printed and forwarded.', 'record', $id);
        flash('Committee Referral marked printed and forwarded to the assigned committee.');
    } catch (Throwable $error) {
        $pdo->rollBack();
        flash('Unable to mark referral as printed. Please try again.', 'error');
    }

    redirect('/record_view.php?id=' . $id);
}

flash('Unknown workflow action.', 'error');
redirect('/record_view.php?id=' . $id);
