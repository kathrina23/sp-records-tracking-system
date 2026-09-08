<?php

require_once __DIR__ . '/../app/auth.php';
require_login();
ensure_plenary_number_schema();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/records.php');
}

verify_csrf();

$id = (int) ($_POST['record_id'] ?? 0);
$newStatus = trim($_POST['status'] ?? '');
$notes = trim($_POST['notes'] ?? '');
$reportTitle = trim($_POST['report_title'] ?? '');
$returnTarget = ($_POST['return'] ?? '') === 'dashboard' ? 'dashboard' : 'record';
$divisionTab = trim($_POST['division_tab'] ?? '');
$cityTab = trim($_POST['city_tab'] ?? '');
$dashboardReturnUrl = url('/dashboard.php') . ($cityTab !== '' ? '?city_tab=' . urlencode($cityTab) : ($divisionTab !== '' ? '?division_tab=' . urlencode($divisionTab) : ''));
$updateFormUrl = url('/record_update.php?id=' . $id) . ($returnTarget === 'dashboard'
    ? '&popup=1&return=dashboard' . ($cityTab !== '' ? '&city_tab=' . urlencode($cityTab) : '') . ($divisionTab !== '' ? '&division_tab=' . urlencode($divisionTab) : '')
    : '');

$statuses = all_statuses();

if (!$id || !in_array($newStatus, $statuses, true)) {
    flash('Please complete the status update form.', 'error');
    redirect('/record_view.php?id=' . $id);
}

$stmt = db()->prepare("SELECT r.*, c.name committee_name, clerk.name receiving_clerk_name
    FROM records r
    LEFT JOIN committees c ON c.id = r.committee_id
    LEFT JOIN users clerk ON clerk.id = r.receiving_clerk_id
    WHERE r.id = ?");
$stmt->execute([$id]);
$record = $stmt->fetch();

if (!$record) {
    http_response_code(404);
    exit('Record not found.');
}

if (!can_update_record_status($record)) {
    http_response_code(403);
    exit('You are not assigned to update this record.');
}

$role = current_user()['role'] ?? '';
if (($record['document_type'] ?? '') === 'Certified Urgent'
    && can_manage_plenary_scheduling()
    && !in_array($newStatus, ['For Plenary Session', 'Scheduled for Plenary', 'Disapproved', 'Approved in the Plenary'], true)) {
    flash('Certified Urgent records can only use plenary statuses at this stage.', 'error');
    redirect($updateFormUrl);
}
if (($record['status'] ?? '') === 'Approved in the Plenary'
    && in_array($role, ['admin', 'city_secretary', 'division_chief', 'secretariat', 'receiving_clerk'], true)) {
    http_response_code(403);
    exit('Approved in the Plenary records can no longer be updated.');
}
if ($role === 'administrative_support') {
    if (!in_array($record['document_type'] ?? '', ['Committee Referrals', 'Certified Urgent'], true)
        || !in_array($record['status'] ?? '', array_merge(['Approved in the Plenary'], post_plenary_statuses()), true)
        || !in_array($newStatus, post_plenary_statuses(), true)) {
        flash('Administrative Support can only update approved plenary records using post-plenary statuses.', 'error');
        redirect($updateFormUrl);
    }
} elseif (in_array($record['document_type'] ?? '', ['Committee Referrals', 'Certified Urgent'], true) && in_array($newStatus, post_plenary_statuses(), true)) {
    flash('Only Administrative Support can use post-plenary statuses.', 'error');
    redirect($updateFormUrl);
}
if ($newStatus === 'For Transmittal' && !record_has_plenary_approval($record)) {
    flash('For Transmittal can only be applied to records approved in the plenary.', 'error');
    redirect($updateFormUrl);
}
$isDivisionChiefFirstAction = ($record['document_type'] ?? '') === 'Committee Referrals'
    && $role === 'division_chief'
    && !division_chief_first_action_done($record);
if (in_array($record['document_type'] ?? '', ['Committee Referrals', 'Certified Urgent'], true)
    && $role === 'division_chief'
    && $isDivisionChiefFirstAction
    && $newStatus === 'Pending to the Committee') {
    flash('Please choose the recommended first action for this referral.', 'error');
    redirect($updateFormUrl);
}
$canEditTitleDuringStatusUpdate = in_array($record['document_type'] ?? '', ['Committee Referrals', 'Certified Urgent'], true)
    && in_array($role, ['admin', 'city_secretary', 'secretariat'], true);
$recordTitleForMovement = trim((string) ($record['title'] ?? ''));
$previousTitleForMovement = null;
$titleChanged = false;
if ($canEditTitleDuringStatusUpdate && array_key_exists('report_title', $_POST)) {
    if ($reportTitle === '') {
        flash('The record title cannot be blank.', 'error');
        redirect($updateFormUrl);
    }
    if ((function_exists('mb_strlen') ? mb_strlen($reportTitle, 'UTF-8') : strlen($reportTitle)) > 1000) {
        flash('The record title cannot exceed 1,000 characters.', 'error');
        redirect($updateFormUrl);
    }
    if ($reportTitle !== $recordTitleForMovement) {
        $previousTitleForMovement = $recordTitleForMovement;
        $recordTitleForMovement = $reportTitle;
        $titleChanged = true;
    }
}
if (($record['document_type'] ?? '') === 'Committee Referrals' && in_array($role, ['division_chief', 'secretariat'], true) && in_array($newStatus, ['Received', 'Assigned to the Committee'], true)) {
    flash('Received and Reviewed/Assigned statuses cannot be updated by the Division Chief or Secretariat.', 'error');
    redirect($updateFormUrl);
}
if ($newStatus === 'Scheduled for Plenary') {
    if (!can_manage_plenary_scheduling()) {
        http_response_code(403);
        exit('Only the Laws and Rules Secretariat, City Secretary, or Administrator can schedule a record for plenary.');
    }
}
if ($role === 'secretariat'
    && is_laws_and_rules_secretariat()
    && in_array($record['status'] ?? '', ['For Plenary Session', 'Scheduled for Plenary'], true)
    && !in_array($newStatus, ['For Plenary Session', 'Scheduled for Plenary', 'Referred Back to Committee'], true)) {
    http_response_code(403);
    exit('The Laws and Rules Secretariat can only schedule, reschedule, or refer a record back to a committee from the For Plenary tab.');
}
if (in_array($record['document_type'] ?? '', ['Committee Referrals', 'Certified Urgent'], true)
    && $newStatus === 'Approved in the Plenary'
    && !in_array($role, ['admin', 'city_secretary'], true)) {
    flash('Only the Administrator or City Secretary can mark a record as Approved in the Plenary.', 'error');
    redirect($updateFormUrl);
}

$detailLabel = '';
$detailValue = '';
$recommendation = null;
$approvedPlenaryType = '';
$approvedPlenaryNumber = '';
$approvedPlenaryDate = null;
$plenarySessionDate = null;
$referredBackCommitteeId = 0;
$referredBackAssignedChiefId = null;
if (in_array($record['document_type'] ?? '', ['Committee Referrals', 'Certified Urgent'], true)) {
    if ($newStatus === 'Received') {
        $detailLabel = 'Admin Receiving Section';
        $detailValue = $record['receiving_clerk_name'] ?? '';
    } elseif ($newStatus === 'Assigned to the Committee') {
        $detailLabel = 'Committee';
        $detailValue = $record['committee_name'] ?? '';
    } elseif ($newStatus === 'Pending to the Committee') {
        $detailLabel = 'Committee';
        $detailValue = $record['committee_name'] ?? '';
    } elseif ($newStatus === 'For Meeting') {
        $detailLabel = 'Date of Meeting';
        $detailValue = trim($_POST['meeting_date'] ?? '');
    } elseif ($newStatus === 'For Inspection') {
        $detailLabel = 'Date of Inspection';
        $detailValue = trim($_POST['inspection_date'] ?? '');
    } elseif ($newStatus === 'Recommending Approval') {
        $recommendedCommitteeId = (int) ($_POST['recommended_committee_id'] ?? 0);
        $recommendationDate = trim($_POST['recommending_approval_date'] ?? '');
        if (!$recommendedCommitteeId || $recommendationDate === '') {
            flash('Please select the recommended committee and date for Recommending Approval.', 'error');
            redirect($updateFormUrl);
        }

        $committeeStmt = db()->prepare('SELECT name FROM committees WHERE id = ?');
        $committeeStmt->execute([$recommendedCommitteeId]);
        $recommendedCommitteeName = (string) $committeeStmt->fetchColumn();
        if ($recommendedCommitteeName === '') {
            flash('Selected recommended committee was not found.', 'error');
            redirect($updateFormUrl);
        }

        $assignedChief = division_chief_for_committee($recommendedCommitteeId);
        if (!$assignedChief) {
            flash('Please assign a Division Chief to the recommended committee first.', 'error');
            redirect($updateFormUrl);
        }

        $recommendation = [
            'committee_id' => $recommendedCommitteeId,
            'committee_name' => $recommendedCommitteeName,
            'date' => $recommendationDate,
            'assigned_chief' => $assignedChief,
        ];
        $detailLabel = 'Recommended Committee';
        $detailValue = $recommendedCommitteeName . ' - ' . $recommendationDate;
    } elseif ($newStatus === 'Deferred') {
        $detailLabel = 'Date Deferred';
        $detailValue = trim($_POST['deferred_date'] ?? '');
    } elseif ($newStatus === 'Tabled') {
        $detailLabel = 'Date Tabled';
        $detailValue = trim($_POST['tabled_date'] ?? '');
    } elseif ($newStatus === 'Noted') {
        $detailLabel = 'Date Noted';
        $detailValue = trim($_POST['noted_date'] ?? '');
    } elseif ($newStatus === 'Referred To') {
        $detailLabel = 'Office / Organization / Individual';
        $detailValue = trim($_POST['referred_to'] ?? '');
    } elseif ($newStatus === 'Referred Back to Committee') {
        $referredBackCommitteeId = (int) ($_POST['referred_back_committee_id'] ?? 0);
        if ($referredBackCommitteeId > 0) {
            $committeeStmt = db()->prepare('SELECT id, name FROM committees WHERE id = ?');
            $committeeStmt->execute([$referredBackCommitteeId]);
            $referredBackCommittee = $committeeStmt->fetch();
            if ($referredBackCommittee) {
                $detailValue = (string) $referredBackCommittee['name'];
                $referredBackAssignedChief = division_chief_for_committee($referredBackCommitteeId);
                $referredBackAssignedChiefId = $referredBackAssignedChief
                    ? (int) $referredBackAssignedChief['id']
                    : null;
            }
        }
        $detailLabel = 'Committee';
    } elseif ($newStatus === 'Endorsement') {
        $detailLabel = 'Date';
        $detailValue = trim($_POST['endorsement_date'] ?? '');
    } elseif ($newStatus === 'Scheduled for Plenary') {
        $plenarySessionDate = trim($_POST['regular_session_date'] ?? '');
        if ($plenarySessionDate !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $plenarySessionDate) || !strtotime($plenarySessionDate))) {
            flash('Please enter a valid Plenary / Session Date.', 'error');
            redirect($updateFormUrl);
        }
        $detailLabel = 'Plenary / Session Date';
        $detailValue = $plenarySessionDate !== '' ? display_date($plenarySessionDate) : '';
    } elseif ($newStatus === 'Disapproved') {
        $sessionDate = trim($_POST['disapproved_session_date'] ?? '');
        if ($sessionDate !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $sessionDate) || !strtotime($sessionDate))) {
            flash('Please enter a valid Date of Session.', 'error');
            redirect($updateFormUrl);
        }
        $detailLabel = 'Date of Session';
        $detailValue = $sessionDate !== '' ? display_date($sessionDate) : '';
    } elseif ($newStatus === 'Approved in the Plenary') {
        $alreadyApprovedStmt = db()->prepare("SELECT COUNT(*) FROM record_movements WHERE record_id = ? AND to_status = 'Approved in the Plenary'");
        $alreadyApprovedStmt->execute([$id]);
        $alreadyApproved = (int) $alreadyApprovedStmt->fetchColumn() > 0
            || trim((string) ($record['approved_ordinance_number'] ?? '')) !== ''
            || trim((string) ($record['approved_resolution_number'] ?? '')) !== '';
        if ($alreadyApproved) {
            flash('This record already has an Approved in the Plenary update and cannot be approved again.', 'error');
            redirect($updateFormUrl);
        }

        $approvedPlenaryType = trim($_POST['approved_plenary_type'] ?? '');
        $approvedPlenaryNumberInput = trim($_POST['approved_plenary_number'] ?? '');
        $approvedPlenaryDate = trim($_POST['approved_plenary_date'] ?? '');

        if (!in_array($approvedPlenaryType, ['ordinance', 'resolution'], true) || $approvedPlenaryNumberInput === '' || $approvedPlenaryDate === '') {
            flash('Please select Ordinance or Resolution, enter the number, and add the Date Approved.', 'error');
            redirect($updateFormUrl);
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $approvedPlenaryDate)) {
            flash('Please enter a valid Date Approved.', 'error');
            redirect($updateFormUrl);
        }

        $approvedTimestamp = strtotime($approvedPlenaryDate);
        if (!$approvedTimestamp) {
            flash('Please enter a valid Date Approved.', 'error');
            redirect($updateFormUrl);
        }

        $approvedYear = date('Y', $approvedTimestamp);
        $approvedNumberOnly = preg_replace('/\s*-\s*\d{4}$/', '', $approvedPlenaryNumberInput) ?: $approvedPlenaryNumberInput;
        $approvedNumberOnly = trim($approvedNumberOnly, " \t\n\r\0\x0B-");
        $approvedPlenaryNumber = $approvedNumberOnly . '-' . $approvedYear;
        $detailLabel = $approvedPlenaryType === 'ordinance' ? 'Ordinance Number' : 'Resolution Number';
        $detailValue = $approvedPlenaryNumber . "\nDate Approved: " . display_date($approvedPlenaryDate);
    } elseif ($newStatus === 'Others') {
        $detailLabel = 'Details';
        $detailValue = trim($_POST['other_status_detail'] ?? '');
    }
}

if ($detailLabel !== '' && $detailValue === '') {
    flash('Please complete the required field for this status: ' . $detailLabel . '.', 'error');
    redirect($updateFormUrl);
}

$combinedNotes = trim(
    ($isDivisionChiefFirstAction ? "Division Chief First Action\n" : '') .
    ($detailLabel !== '' ? $detailLabel . ': ' . $detailValue : '') .
    ($notes !== '' ? "\nNotes: " . $notes : '')
);

$pdo = db();
$pdo->beginTransaction();

try {
    if ($titleChanged) {
        $titleUpdate = $pdo->prepare('UPDATE records SET title = ? WHERE id = ?');
        $titleUpdate->execute([$recordTitleForMovement, $id]);
    }

    if ($newStatus === 'Recommending Approval' && $recommendation) {
        $committeeRows = record_committee_rows((int) $record['id'], !empty($record['committee_id']) ? (int) $record['committee_id'] : null);
        $committeeIds = array_map(fn ($row) => (int) $row['committee_id'], $committeeRows);
        $committeeIds[] = (int) $recommendation['committee_id'];
        $committeeIds = array_values(array_unique(array_filter($committeeIds)));

        $oldRemarks = trim(
            $combinedNotes . "\n" .
            'Recommended committee added under the same Communication Number: ' . $record['control_number'] . '.'
        );

        $update = $pdo->prepare('UPDATE records SET status = ?, remarks = TRIM(CONCAT(COALESCE(NULLIF(remarks, ""), ""), CASE WHEN COALESCE(NULLIF(remarks, ""), "") <> "" THEN CONCAT(CHAR(10), CHAR(10)) ELSE "" END, ?)), updated_by = ? WHERE id = ?');
        $update->execute([$newStatus, $oldRemarks, current_user()['id'], $id]);
        save_record_committees($id, $committeeIds);
    } elseif ($newStatus === 'Referred Back to Committee' && $referredBackCommitteeId > 0) {
        $committeeRows = record_committee_rows(
            (int) $record['id'],
            !empty($record['committee_id']) ? (int) $record['committee_id'] : null
        );
        $committeeIds = array_map(fn ($row) => (int) $row['committee_id'], $committeeRows);
        $committeeIds = array_values(array_unique(array_merge(
            [$referredBackCommitteeId],
            array_values(array_diff($committeeIds, [$referredBackCommitteeId]))
        )));

        $update = $pdo->prepare('UPDATE records SET status = ?, committee_id = ?, assigned_user_id = ?, plenary_session_date = NULL, remarks = CASE WHEN ? <> "" THEN ? ELSE remarks END, updated_by = ? WHERE id = ?');
        $update->execute([
            $newStatus,
            $referredBackCommitteeId,
            $referredBackAssignedChiefId,
            $combinedNotes,
            $combinedNotes,
            current_user()['id'],
            $id,
        ]);
        save_record_committees($id, $committeeIds);
    } elseif ($newStatus === 'For Plenary Session') {
        $update = $pdo->prepare('UPDATE records SET status = ?, plenary_session_date = NULL, remarks = CASE WHEN ? <> "" THEN ? ELSE remarks END, updated_by = ? WHERE id = ?');
        $update->execute([$newStatus, $combinedNotes, $combinedNotes, current_user()['id'], $id]);
    } elseif ($newStatus === 'Scheduled for Plenary') {
        $update = $pdo->prepare('UPDATE records SET status = ?, plenary_session_date = ?, remarks = CASE WHEN ? <> "" THEN ? ELSE remarks END, updated_by = ? WHERE id = ?');
        $update->execute([$newStatus, $plenarySessionDate, $combinedNotes, $combinedNotes, current_user()['id'], $id]);
    } elseif ($newStatus === 'Approved in the Plenary') {
        $approvedOrdinanceNumber = $approvedPlenaryType === 'ordinance' ? $approvedPlenaryNumber : null;
        $approvedResolutionNumber = $approvedPlenaryType === 'resolution' ? $approvedPlenaryNumber : null;
        $update = $pdo->prepare('UPDATE records SET status = ?, approved_ordinance_number = ?, approved_resolution_number = ?, plenary_approved_date = ?, remarks = CASE WHEN ? <> "" THEN ? ELSE remarks END, updated_by = ? WHERE id = ?');
        $update->execute([$newStatus, $approvedOrdinanceNumber, $approvedResolutionNumber, $approvedPlenaryDate, $combinedNotes, $combinedNotes, current_user()['id'], $id]);
    } else {
        $update = $pdo->prepare('UPDATE records SET status = ?, remarks = CASE WHEN ? <> "" THEN ? ELSE remarks END, updated_by = ? WHERE id = ?');
        $update->execute([$newStatus, $combinedNotes, $combinedNotes, current_user()['id'], $id]);
    }

    $movement = $pdo->prepare('INSERT INTO record_movements (record_id, from_status, to_status, from_location, to_location, notes, report_title, record_title, previous_title, report_remarks, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $movement->execute([
        $id,
        $record['status'],
        $newStatus,
        $record['current_location'],
        $record['current_location'],
        $combinedNotes,
        $titleChanged ? $recordTitleForMovement : null,
        $recordTitleForMovement,
        $previousTitleForMovement,
        $notes !== '' ? $notes : null,
        current_user()['id'],
    ]);

    $pdo->commit();
    audit_log('record_status_update', 'Updated record status from ' . $record['status'] . ' to ' . $newStatus . '.', 'record', $id);
    if ($titleChanged) {
        audit_log('record_title_update', 'Changed record title from "' . $previousTitleForMovement . '" to "' . $recordTitleForMovement . '" during the ' . $newStatus . ' update.', 'record', $id);
    }
    if ($newStatus === 'Recommending Approval' && $recommendation) {
        audit_log('recommended_approval_committee_added', 'Added recommended committee ' . $recommendation['committee_name'] . ' to Communication Number ' . $record['control_number'] . '.', 'record', $id);
        flash('Status saved. The recommended committee was grouped under the same Communication Number ' . $record['control_number'] . '.');
        redirect($returnTarget === 'dashboard' ? $dashboardReturnUrl : '/record_view.php?id=' . $id);
    }
    if ($newStatus === 'Referred Back to Committee') {
        audit_log('record_referred_back_to_committee', 'Referred ' . ($record['control_number'] ?? 'record') . ' back to ' . $detailValue . '.', 'record', $id);
        flash('Record referred back to ' . $detailValue . '. Its assigned committee secretariat can now see and act on it again.');
        redirect($returnTarget === 'dashboard' ? $dashboardReturnUrl : '/record_view.php?id=' . $id);
    }
    flash('Status update saved and added to the tracking history.');
    if ($returnTarget === 'dashboard') {
        if ($newStatus === 'Approved in the Plenary' && in_array($role, ['admin', 'city_secretary'], true)) {
            redirect('/dashboard.php?city_tab=approved-plenary');
        }
        redirect($dashboardReturnUrl);
    }
} catch (Throwable $error) {
    $pdo->rollBack();
    flash('Unable to save the status update. Please try again.', 'error');
}

redirect($returnTarget === 'dashboard' ? $dashboardReturnUrl : '/record_update.php?id=' . $id);
