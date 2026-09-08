<?php

require_once __DIR__ . '/../app/auth.php';
require_login();
ensure_plenary_number_schema();

const NEW_RECORD_ATTACHMENT_MAX_BYTES = 10485760;
const NEW_RECORD_ATTACHMENT_MAX_FILES = 10;
const NEW_RECORD_ATTACHMENT_MAX_TOTAL_BYTES = 36700160;
const NEW_RECORD_ATTACHMENT_ALLOWED_MIME_TYPES = [
    'application/pdf' => 'pdf',
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/gif' => 'gif',
    'image/webp' => 'webp',
];

function pending_existing_record_target(?string $remarks): string
{
    if (!$remarks) {
        return '';
    }

    if (preg_match('/Tagged as update to existing Communication Number\s+([A-Z]-\d{5}-\d{4})/i', $remarks, $matches)) {
        return strtoupper($matches[1]);
    }

    return '';
}

function strip_pending_existing_record_tag(?string $remarks): string
{
    $remarks = (string) $remarks;
    $remarks = preg_replace('/(^|\R)Tagged as update to existing Communication Number\s+[A-Z]-\d{5}-\d{4}\.\s*Pending SP Secretary review\.\s*/i', "\n", $remarks);
    $remarks = preg_replace("/\n{3,}/", "\n\n", (string) $remarks);
    return trim((string) $remarks);
}

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$popupReturnTarget = in_array($_GET['return'] ?? '', ['dashboard', 'records', 'record'], true)
    ? (string) $_GET['return']
    : '';
$isPopup = $id > 0 && ($_GET['popup'] ?? '') === '1' && $popupReturnTarget !== '';
$popupDefaultCloseUrl = match ($popupReturnTarget) {
    'dashboard' => '/dashboard.php?city_tab=review',
    'record' => '/record_view.php?id=' . $id,
    default => '/records.php',
};
$popupAllowedClosePath = match ($popupReturnTarget) {
    'dashboard' => '/dashboard.php',
    'record' => '/record_view.php',
    default => '/records.php',
};
$popupCloseUrl = (string) ($_GET['return_url'] ?? $popupDefaultCloseUrl);
$popupCloseParts = parse_url($popupCloseUrl);
if (
    $popupCloseUrl === ''
    || $popupCloseParts === false
    || isset($popupCloseParts['scheme'])
    || isset($popupCloseParts['host'])
    || ($popupCloseParts['path'] ?? '') !== $popupAllowedClosePath
) {
    $popupCloseUrl = $popupDefaultCloseUrl;
}
if (!$id && !can_create_records()) {
    http_response_code(403);
    exit('Your role cannot create new records.');
}

$documentTypes = array_merge(
    ['Committee Referrals', 'Certified Urgent'],
    administrative_document_types()
);
$selectedType = $_GET['type'] ?? 'Committee Referrals';
if (!in_array($selectedType, $documentTypes, true)) {
    $selectedType = 'Committee Referrals';
}
$recordFormQuery = [];
if ($id > 0) {
    $recordFormQuery['id'] = $id;
} elseif ($selectedType !== 'Committee Referrals') {
    $recordFormQuery['type'] = $selectedType;
}
if ($isPopup) {
    $recordFormQuery['popup'] = 1;
    $recordFormQuery['return'] = $popupReturnTarget;
    $recordFormQuery['return_url'] = $popupCloseUrl;
}
$recordFormUrl = url('/record_form.php') . ($recordFormQuery ? '?' . http_build_query($recordFormQuery) : '');
$recordFormSubmissionToken = trim((string) ($_POST['record_form_submission_token'] ?? ''));
if (!$id) {
    if (!isset($_SESSION['record_form_submission_tokens']) || !is_array($_SESSION['record_form_submission_tokens'])) {
        $_SESSION['record_form_submission_tokens'] = [];
    }
    $tokenCutoff = time() - 14400;
    foreach ($_SESSION['record_form_submission_tokens'] as $token => $createdAt) {
        if ((int) $createdAt < $tokenCutoff) {
            unset($_SESSION['record_form_submission_tokens'][$token]);
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (
            $recordFormSubmissionToken === ''
            || !isset($_SESSION['record_form_submission_tokens'][$recordFormSubmissionToken])
        ) {
            flash('This new record form was already submitted. No additional record was created.', 'error');
            redirect($recordFormUrl);
        }
    } else {
        $recordFormSubmissionToken = bin2hex(random_bytes(32));
        $_SESSION['record_form_submission_tokens'][$recordFormSubmissionToken] = time();
        if (count($_SESSION['record_form_submission_tokens']) > 20) {
            asort($_SESSION['record_form_submission_tokens']);
            while (count($_SESSION['record_form_submission_tokens']) > 20) {
                array_shift($_SESSION['record_form_submission_tokens']);
            }
        }
    }
}
$attachmentCloseUrl = $recordFormUrl;
$attachmentViewUrl = url('/record_attachments_view.php?' . http_build_query([
    'record_id' => $id,
    'popup' => 1,
    'return' => 'review',
    'return_url' => $attachmentCloseUrl,
]));
$nextControlNumbers = [];
foreach ($documentTypes as $typeOption) {
    $nextControlNumbers[$typeOption] = next_control_number($typeOption);
}

$record = [
    'control_number' => $nextControlNumbers[$selectedType],
    'title' => '',
    'plenary_print_title' => null,
    'document_type' => $selectedType,
    'origin' => '',
    'client_name' => '',
    'contact_number' => '',
    'client_email' => '',
    'committee_id' => '',
    'assigned_user_id' => '',
    'receiving_clerk_id' => current_user()['role'] === 'receiving_clerk' ? current_user()['id'] : '',
    'priority' => 'Normal',
    'status' => 'Received',
    'received_date' => date('Y-m-d'),
    'due_date' => '',
    'current_location' => 'Legislative Committees Division',
    'remarks' => '',
    'proposed_by_city_council_member' => 0,
    'proposed_ordinance_number' => null,
    'proposed_resolution_number' => null,
];
$forwardOptions = ['City Vice Mayor', 'City Councilors', 'Divisions', 'Sections', 'Employee'];

if ($id) {
    $stmt = db()->prepare('SELECT * FROM records WHERE id = ?');
    $stmt->execute([$id]);
    $record = $stmt->fetch();
    if (!$record) {
        http_response_code(404);
        exit('Record not found.');
    }

    if (!can_edit_record($record)) {
        http_response_code(403);
        exit('You are not assigned to edit this record.');
    }
}
$canCorrectForPlenaryRecord = $id > 0
    && can_manage_for_plenary_record($record);
$isLawsAndRulesPlenaryEdit = $canCorrectForPlenaryRecord
    && (current_user()['role'] ?? '') === 'secretariat'
    && is_laws_and_rules_secretariat();
$recordFormTitle = $isLawsAndRulesPlenaryEdit
    && trim((string) ($record['plenary_print_title'] ?? '')) !== ''
        ? (string) $record['plenary_print_title']
        : (string) ($record['title'] ?? '');
$recordFormDocumentTypes = $documentTypes;

$recordFormStatusOptions = is_administrative_document_type($record['document_type'] ?? '')
    ? administrative_statuses()
    : referral_statuses();
if (!can_manage_plenary_scheduling()) {
    $recordFormStatusOptions = array_values(array_filter(
        $recordFormStatusOptions,
        static fn (string $status): bool => $status !== 'Scheduled for Plenary'
    ));
}
if (($record['document_type'] ?? '') === 'Certified Urgent' && !$canCorrectForPlenaryRecord) {
    $recordFormStatusOptions = array_values(array_filter(
        $recordFormStatusOptions,
        static fn (string $status): bool => !in_array(
            $status,
            ['Received', 'Assigned to the Committee', 'Pending to the Committee'],
            true
        )
    ));
}
$hideStatusInRecordForm = $id > 0
    && in_array(current_user()['role'] ?? '', ['admin', 'city_secretary'], true)
    && ($record['document_type'] ?? '') === 'Certified Urgent';
$canEditStatusInRecordForm = $id > 0
    && in_array(current_user()['role'] ?? '', ['admin', 'city_secretary'], true)
    && ($record['status'] ?? '') !== 'Received'
    && !$hideStatusInRecordForm;
$storedForwardOptions = preg_split('/\s*;\s*/', trim((string) ($record['current_location'] ?? ''))) ?: [];
$selectedForwardOptions = array_values(array_intersect($forwardOptions, $storedForwardOptions));
$receivingStaffCommentPending = $id && record_has_pending_receiving_staff_comment($record);
$receivingStaffComment = $receivingStaffCommentPending
    ? pending_receiving_staff_comment((int) $id)
    : null;
$canChooseDocumentType = !$id
    || $canCorrectForPlenaryRecord
    || (($record['status'] ?? '') === 'Received' && in_array(current_user()['role'] ?? '', ['admin', 'city_secretary', 'receiving_clerk'], true));
$canChooseReferralCommittee = (
        ($record['document_type'] ?? 'Committee Referrals') === 'Committee Referrals'
        || $canChooseDocumentType
    )
    && (
        ((can_city_secretary_action() || $canCorrectForPlenaryRecord) && (
            ($record['status'] ?? '') === 'Received'
            || ($record['document_type'] ?? '') === 'Committee Referrals'
            || $canCorrectForPlenaryRecord
        ))
        || ((current_user()['role'] ?? '') === 'receiving_clerk' && (!$id || receiving_clerk_can_edit_unreviewed_record($record)))
    );
$canReviewCityCouncilProposal = $id > 0
    && can_city_secretary_action()
    && ($record['document_type'] ?? '') === 'Committee Referrals'
    && ($record['status'] ?? '') === 'Received';
$receivingClerkDisplay = current_user()['role'] === 'receiving_clerk' ? current_user()['name'] : '';
if (!empty($record['receiving_clerk_id'])) {
    $clerkNameStmt = db()->prepare('SELECT name FROM users WHERE id = ?');
    $clerkNameStmt->execute([(int) $record['receiving_clerk_id']]);
    $receivingClerkDisplay = (string) ($clerkNameStmt->fetchColumn() ?: $receivingClerkDisplay);
} elseif (!empty($record['created_by'])) {
    $creatorNameStmt = db()->prepare('SELECT name FROM users WHERE id = ?');
    $creatorNameStmt->execute([(int) $record['created_by']]);
    $receivingClerkDisplay = (string) ($creatorNameStmt->fetchColumn() ?: $receivingClerkDisplay);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $documentType = $_POST['document_type'] ?? 'Committee Referrals';
    if (!in_array($documentType, $recordFormDocumentTypes, true)) {
        $documentType = $record['document_type'] ?? 'Committee Referrals';
    }
    $submittedStatus = trim((string) ($_POST['status'] ?? ''));
    if ($canEditStatusInRecordForm && !in_array($submittedStatus, $recordFormStatusOptions, true)) {
        flash('Please select a valid status.', 'error');
        redirect($recordFormUrl);
    }
    if ($canCorrectForPlenaryRecord
        && $documentType === 'Certified Urgent'
        && !in_array($submittedStatus, ['For Plenary Session', 'Scheduled for Plenary', 'Disapproved', 'Approved in the Plenary'], true)) {
        flash('Change the Record Type to Committee Referrals before selecting a Committee Level status.', 'error');
        redirect($recordFormUrl);
    }
    $reviewAction = $_POST['review_action'] ?? 'finalize';
    $mergeTargetControlNumber = strtoupper(trim($_POST['merge_target_control_number'] ?? ''));
    $canTagAsNewOrExisting = $documentType !== 'Certified Urgent'
        && in_array(current_user()['role'] ?? '', ['admin', 'city_secretary', 'receiving_clerk'], true)
        && (!$id || ($record['status'] ?? '') === 'Received');
    if (!$canTagAsNewOrExisting) {
        $reviewAction = 'finalize';
        $mergeTargetControlNumber = '';
    }

    $proposedByCityCouncilMember = !empty($record['proposed_by_city_council_member']) ? 1 : 0;
    $proposedOrdinanceNumber = trim((string) ($record['proposed_ordinance_number'] ?? ''));
    $proposedResolutionNumber = trim((string) ($record['proposed_resolution_number'] ?? ''));
    $isCityCouncilProposalReview = $id > 0
        && can_city_secretary_action()
        && ($record['status'] ?? '') === 'Received'
        && $documentType === 'Committee Referrals'
        && $reviewAction !== 'merge_existing';
    if ($isCityCouncilProposalReview) {
        $proposedByCityCouncilMember = isset($_POST['proposed_by_city_council_member']) ? 1 : 0;
        $proposedType = trim((string) ($_POST['city_council_proposed_type'] ?? 'ordinance'));
        $proposedNumberInput = trim((string) ($_POST['city_council_proposed_number'] ?? ''));
        $proposedOrdinanceNumber = '';
        $proposedResolutionNumber = '';

        if ($proposedByCityCouncilMember && $proposedNumberInput !== '') {
            if (!in_array($proposedType, ['ordinance', 'resolution'], true)) {
                flash('Please select Proposed Ordinance or Proposed Resolution.', 'error');
                redirect($recordFormUrl);
            }
            $normalizedProposedNumber = normalize_proposed_number_input($proposedNumberInput);
            $normalizedLength = function_exists('mb_strlen')
                ? mb_strlen($normalizedProposedNumber, 'UTF-8')
                : strlen($normalizedProposedNumber);
            if ($normalizedLength > 80) {
                flash('The proposed number cannot exceed 80 characters.', 'error');
                redirect($recordFormUrl);
            }
            if ($proposedType === 'ordinance') {
                $proposedOrdinanceNumber = $normalizedProposedNumber;
            } else {
                $proposedResolutionNumber = $normalizedProposedNumber;
            }
        }
    }
    $cityCouncilProposalChanged = $isCityCouncilProposalReview && (
        (int) (!empty($record['proposed_by_city_council_member'])) !== $proposedByCityCouncilMember
        || trim((string) ($record['proposed_ordinance_number'] ?? '')) !== $proposedOrdinanceNumber
        || trim((string) ($record['proposed_resolution_number'] ?? '')) !== $proposedResolutionNumber
    );
    $cityCouncilProposalMovementNote = '';
    if ($cityCouncilProposalChanged) {
        if ($proposedByCityCouncilMember) {
            $assignedProposedNumber = $proposedOrdinanceNumber !== ''
                ? 'Proposed Ordinance Number: ' . $proposedOrdinanceNumber
                : ($proposedResolutionNumber !== '' ? 'Proposed Resolution Number: ' . $proposedResolutionNumber : 'Proposed number not yet assigned.');
            $cityCouncilProposalMovementNote = "Marked as proposed by a City Council Member.\n" . $assignedProposedNumber;
        } else {
            $cityCouncilProposalMovementNote = 'City Council Member proposal marking removed.';
        }
    }

    $receivingClerkId = null;
    if (in_array($documentType, ['Committee Referrals', 'Certified Urgent'], true)) {
        if ($id) {
            $receivingClerkId = $record['receiving_clerk_id'] !== null ? (int) $record['receiving_clerk_id'] : null;
        } elseif ((current_user()['role'] ?? '') === 'receiving_clerk') {
            $receivingClerkId = (int) current_user()['id'];
        } elseif (($_POST['receiving_clerk_id'] ?? '') !== '') {
            $receivingClerkId = (int) $_POST['receiving_clerk_id'];
        }
    } elseif ($id) {
        $receivingClerkId = $record['receiving_clerk_id'] !== null ? (int) $record['receiving_clerk_id'] : null;
    } elseif ((current_user()['role'] ?? '') === 'receiving_clerk') {
        $receivingClerkId = (int) current_user()['id'];
    }
    $postedForwardOptions = $_POST['forwarded_to'] ?? [];
    if (!is_array($postedForwardOptions)) {
        $postedForwardOptions = [$postedForwardOptions];
    }
    $postedForwardOptions = array_values(array_unique(array_filter(array_map(
        fn ($option) => trim((string) $option),
        $postedForwardOptions
    ), fn ($option) => in_array($option, $forwardOptions, true))));
    $forwardedTo = implode('; ', $postedForwardOptions);
    $receivingSectionNote = trim($_POST['receiving_section_note'] ?? '');
    $postedCommitteeIds = [];
    if (isset($_POST['committee_ids']) && is_array($_POST['committee_ids'])) {
        $postedCommitteeIds = array_values(array_unique(array_filter(array_map('intval', $_POST['committee_ids']))));
    } elseif (($_POST['committee_id'] ?? '') !== '') {
        $postedCommitteeIds = [(int) $_POST['committee_id']];
    }
    if ($id && $documentType === 'Committee Referrals' && !$canChooseReferralCommittee) {
        $existingCommitteeRows = record_committee_rows(
            $id,
            !empty($record['committee_id']) ? (int) $record['committee_id'] : null
        );
        $postedCommitteeIds = array_values(array_unique(array_map(
            fn ($row) => (int) $row['committee_id'],
            $existingCommitteeRows
        )));
    }
    $leadCommitteeId = (int) ($_POST['lead_committee_id'] ?? 0);
    if (!$leadCommitteeId && count($postedCommitteeIds) === 1) {
        $leadCommitteeId = (int) $postedCommitteeIds[0];
    }
    if ($leadCommitteeId && $postedCommitteeIds) {
        if (!in_array($leadCommitteeId, $postedCommitteeIds, true)) {
            flash('Please choose the lead committee from the selected committees.', 'error');
            redirect($recordFormUrl);
        }
        $postedCommitteeIds = array_values(array_unique(array_merge(
            [$leadCommitteeId],
            array_values(array_diff($postedCommitteeIds, [$leadCommitteeId]))
        )));
    }
    $primaryCommitteeId = $postedCommitteeIds[0] ?? null;
    $postedOrigin = trim($_POST['origin'] ?? '');
    $postedClientName = trim($_POST['client_name'] ?? '');
    $partyName = $documentType === 'Committee Referrals'
        ? ($postedClientName !== '' ? $postedClientName : $postedOrigin)
        : ($postedOrigin !== '' ? $postedOrigin : $postedClientName);
    $controlNumber = $canCorrectForPlenaryRecord
        ? (string) ($record['control_number'] ?? '')
        : strtoupper(trim($_POST['control_number'] ?? ''));
    $autoAssignControlNumber = !$id || (
        ($record['status'] ?? '') === 'Received'
        && $documentType !== ($record['document_type'] ?? '')
        && in_array(current_user()['role'] ?? '', ['admin', 'city_secretary'], true)
    ) || (
        $canCorrectForPlenaryRecord
        && $documentType !== ($record['document_type'] ?? '')
        && is_administrative_document_type($documentType)
    );
    if ($autoAssignControlNumber) {
        $controlNumber = '';
    }

    $submittedTitle = trim($_POST['title'] ?? '');
    $data = [
        'control_number' => $controlNumber,
        'title' => $isLawsAndRulesPlenaryEdit ? (string) ($record['title'] ?? '') : $submittedTitle,
        'plenary_print_title' => $isLawsAndRulesPlenaryEdit
            ? $submittedTitle
            : ($record['plenary_print_title'] ?? null),
        'document_type' => $documentType,
        'origin' => $documentType !== 'Committee Referrals' ? $partyName : '',
        'client_name' => $documentType === 'Committee Referrals' ? $partyName : null,
        'contact_number' => $documentType === 'Certified Urgent' ? '' : trim($_POST['contact_number'] ?? ($record['contact_number'] ?? '')),
        'client_email' => $documentType === 'Certified Urgent' ? '' : trim($_POST['client_email'] ?? ($record['client_email'] ?? '')),
        'committee_id' => $documentType === 'Committee Referrals' ? $primaryCommitteeId : null,
        'assigned_user_id' => ($_POST['assigned_user_id'] ?? '') !== '' ? (int) $_POST['assigned_user_id'] : null,
        'receiving_clerk_id' => $receivingClerkId,
        'priority' => $record['priority'] ?? 'Normal',
        'status' => $canEditStatusInRecordForm
            ? $submittedStatus
            : ($documentType === 'Certified Urgent' && (!$id || ($record['status'] ?? '') === 'Received')
                ? 'For Plenary Session'
                : ($id ? ($record['status'] ?? 'Received') : 'Received')),
        'received_date' => in_array($documentType, ['Committee Referrals', 'Certified Urgent'], true)
            ? ($_POST['received_date'] ?? date('Y-m-d'))
            : ($record['received_date'] ?? date('Y-m-d')),
        'due_date' => null,
        'current_location' => $documentType === 'Certified Urgent'
            ? 'City Secretary - For Plenary'
            : (is_administrative_document_type($documentType) && $forwardedTo !== ''
                ? $forwardedTo
                : ($record['current_location'] ?? 'Committee Assignment')),
        'remarks' => strip_pending_existing_record_tag(trim($_POST['remarks'] ?? '')),
        'proposed_by_city_council_member' => $proposedByCityCouncilMember,
        'proposed_ordinance_number' => $proposedOrdinanceNumber !== '' ? $proposedOrdinanceNumber : null,
        'proposed_resolution_number' => $proposedResolutionNumber !== '' ? $proposedResolutionNumber : null,
        'updated_by' => current_user()['id'],
    ];
    if ($submittedTitle === '') {
        flash('Please enter the record title.', 'error');
        redirect($recordFormUrl);
    }
    if ((function_exists('mb_strlen') ? mb_strlen($submittedTitle, 'UTF-8') : strlen($submittedTitle)) > 1000) {
        flash('The record title cannot exceed 1,000 characters.', 'error');
        redirect($recordFormUrl);
    }
    if ($canCorrectForPlenaryRecord && $documentType !== ($record['document_type'] ?? '')) {
        $data['status'] = 'Received';
    }
    $hasCityReceivingStaffComment = (
        $documentType === 'Committee Referrals'
        && $id
        && can_city_secretary_action()
        && ($record['status'] ?? '') === 'Received'
        && $receivingSectionNote !== ''
    );
    $cityReceivingStaffCommentNote = $hasCityReceivingStaffComment
        ? 'Note to Receiving Staff: ' . $receivingSectionNote
        : '';

    if ($canTagAsNewOrExisting && $reviewAction === 'merge_existing') {
        if ($mergeTargetControlNumber === '') {
            flash('Please enter the Communication Number of the existing record.', 'error');
            redirect($recordFormUrl);
        }

        $targetCheck = db()->prepare('SELECT id, committee_id FROM records WHERE UPPER(control_number) = ?' . ($id ? ' AND id <> ?' : '') . ' ORDER BY id DESC LIMIT 1');
        $targetCheck->execute($id ? [$mergeTargetControlNumber, $id] : [$mergeTargetControlNumber]);
        $mergeTargetRecord = $targetCheck->fetch();
        $mergeTargetRecordId = (int) ($mergeTargetRecord['id'] ?? 0);
        if (!$mergeTargetRecord) {
            flash('Existing Communication Number was not found.', 'error');
            redirect($recordFormUrl);
        }

        if ($documentType === 'Committee Referrals') {
            $targetCommitteeRows = record_committee_rows(
                $mergeTargetRecordId,
                !empty($mergeTargetRecord['committee_id']) ? (int) $mergeTargetRecord['committee_id'] : null
            );
            $targetCommitteeIds = array_values(array_unique(array_map(
                fn ($row) => (int) $row['committee_id'],
                $targetCommitteeRows
            )));
            if ($targetCommitteeIds) {
                $postedCommitteeIds = $targetCommitteeIds;
                $leadCommitteeId = (int) $targetCommitteeIds[0];
                $primaryCommitteeId = $leadCommitteeId;
                $data['committee_id'] = $primaryCommitteeId;
            }
        }

        if (!can_city_secretary_action()) {
            $data['remarks'] = trim($data['remarks'] . "\n\n" . 'Tagged as update to existing Communication Number ' . $mergeTargetControlNumber . '. Pending SP Secretary review.');
        }
    }

    if (
        $id
        && can_city_secretary_action()
        && ($record['status'] ?? '') === 'Received'
        && $reviewAction === 'merge_existing'
    ) {
        if ($mergeTargetControlNumber === '') {
            flash('Please enter the Communication Number of the existing record.', 'error');
            redirect($recordFormUrl);
        }

        $targetStmt = db()->prepare('SELECT * FROM records WHERE UPPER(control_number) = ? AND id <> ? ORDER BY id DESC LIMIT 1');
        $targetStmt->execute([$mergeTargetControlNumber, $id]);
        $targetRecord = $targetStmt->fetch();

        if (!$targetRecord) {
            flash('Existing Communication Number was not found.', 'error');
            redirect($recordFormUrl);
        }

        $newRecordParty = $partyName !== '' ? $partyName : (($record['client_name'] ?? '') ?: ($record['origin'] ?? ''));
        $mergeNotes = trim(implode("\n", array_filter([
            'Tagged as update to existing Communication Number ' . $targetRecord['control_number'] . '.',
            'New received record: ' . ($record['control_number'] ?? ''),
            'Title: ' . ($data['title'] ?: ($record['title'] ?? '')),
            $newRecordParty !== '' ? 'Client / Origin: ' . $newRecordParty : '',
            trim($data['remarks'] ?? '') !== '' ? 'Remarks: ' . trim($data['remarks']) : '',
        ])));

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $moveHistory = $pdo->prepare('UPDATE record_movements SET record_id = ? WHERE record_id = ?');
            $moveHistory->execute([(int) $targetRecord['id'], $id]);

            $moveAttachments = $pdo->prepare('UPDATE record_attachments SET record_id = ? WHERE record_id = ?');
            $moveAttachments->execute([(int) $targetRecord['id'], $id]);

            $movement = $pdo->prepare('INSERT INTO record_movements (record_id, from_status, to_status, from_location, to_location, notes, record_title, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            $movement->execute([
                (int) $targetRecord['id'],
                $targetRecord['status'],
                $targetRecord['status'],
                $targetRecord['current_location'],
                $targetRecord['current_location'],
                $mergeNotes,
                $targetRecord['title'],
                current_user()['id'],
            ]);

            $touch = $pdo->prepare('UPDATE records SET remarks = TRIM(CONCAT(COALESCE(NULLIF(remarks, ""), ""), CASE WHEN COALESCE(NULLIF(remarks, ""), "") <> "" THEN CONCAT(CHAR(10), CHAR(10)) ELSE "" END, ?)), updated_by = ? WHERE id = ?');
            $touch->execute([$mergeNotes, current_user()['id'], (int) $targetRecord['id']]);

            $delete = $pdo->prepare('DELETE FROM records WHERE id = ?');
            $delete->execute([$id]);

            $pdo->commit();
            audit_log('record_merge_update', 'Merged received record ' . ($record['control_number'] ?? '') . ' into ' . $targetRecord['control_number'] . '.', 'record', (int) $targetRecord['id']);
            flash('New record tagged as an update and merged into Communication Number ' . $targetRecord['control_number'] . '.');
            redirect($isPopup ? $popupCloseUrl : '/record_view.php?id=' . (int) $targetRecord['id']);
        } catch (Throwable $error) {
            $pdo->rollBack();
            flash('Unable to merge the record. Please try again.', 'error');
            redirect($recordFormUrl);
        }
    }

    if ($data['document_type'] === 'Committee Referrals' && $data['status'] === 'Received') {
        $data['current_location'] = 'Committee Assignment';
    }

    if (
        $data['document_type'] === 'Committee Referrals'
        && $id > 0
        && can_city_secretary_action()
        && ($record['status'] ?? 'Received') === 'Received'
    ) {
        if ($hasCityReceivingStaffComment) {
            $data['assigned_user_id'] = null;
            $data['status'] = 'Received';
            $data['current_location'] = 'Receiving Staff - Correction Required';
        } else {
            if ($receivingStaffCommentPending) {
                flash('This review is waiting for the Receiving Staff correction. It cannot be finalized yet.', 'error');
                redirect($recordFormUrl);
            }

            if (empty($postedCommitteeIds)) {
                flash('Please select the committee before marking the record reviewed and ready for printing.', 'error');
                redirect($recordFormUrl);
            }

            if (count($postedCommitteeIds) > 30) {
                flash('Please select up to 30 committees only.', 'error');
                redirect($recordFormUrl);
            }

            $assignedChief = null;
            foreach ($postedCommitteeIds as $committeeId) {
                $chief = division_chief_for_committee((int) $committeeId);
                if (!$chief) {
                    flash('Please assign a Division Chief to all selected committees first.', 'error');
                    redirect($recordFormUrl);
                }
                $assignedChief = $assignedChief ?: $chief;
            }

            $data['assigned_user_id'] = (int) $assignedChief['id'];
            $data['status'] = 'Pending to the Committee';
            $data['current_location'] = 'Admin Receiving Section - For Printing';
            if (count($postedCommitteeIds) > 1) {
                $data['remarks'] = trim($data['remarks'] . "\n\n" . referral_group_label(count($postedCommitteeIds)) . ' referral to ' . count($postedCommitteeIds) . ' committees under one communication number.');
            }
        }
    }

    if (
        is_administrative_document_type($data['document_type'])
        && $id
        && can_city_secretary_action()
        && ($record['status'] ?? 'Received') === 'Received'
        && $forwardedTo !== ''
    ) {
        $data['status'] = 'Completed';
    }

    if ($canCorrectForPlenaryRecord && $data['document_type'] === 'Committee Referrals') {
        if ($data['status'] !== 'Received' && !$postedCommitteeIds) {
            flash('Please select a committee, or choose Received to return the record for review.', 'error');
            redirect($recordFormUrl);
        }
        if ($postedCommitteeIds) {
            $assignedChief = null;
            foreach ($postedCommitteeIds as $committeeId) {
                $chief = division_chief_for_committee((int) $committeeId);
                if (!$chief) {
                    flash('Please assign a Division Chief to all selected committees first.', 'error');
                    redirect($recordFormUrl);
                }
                $assignedChief = $assignedChief ?: $chief;
            }
            $data['assigned_user_id'] = (int) $assignedChief['id'];
        }
        $data['current_location'] = $data['status'] === 'Received'
            ? 'Committee Assignment'
            : 'Admin Receiving Section - For Printing';
    }
    if ($data['document_type'] === 'Committee Referrals' && ($data['client_name'] === '' || !$data['receiving_clerk_id'])) {
        flash('Please complete the client, date received, and Admin Receiving Section for the Committee Referral.', 'error');
        redirect($recordFormUrl);
    }

    if (is_administrative_document_type($data['document_type']) && $data['origin'] === '') {
        flash('Please complete the Client / Origin for this administrative record.', 'error');
        redirect($recordFormUrl);
    }
    if (is_administrative_document_type($data['document_type']) && $id && can_city_secretary_action() && $forwardedTo === '') {
        flash('Please select one or more recipients for this document.', 'error');
        redirect($recordFormUrl);
    }
    if ($data['document_type'] === 'Certified Urgent') {
        if ($data['title'] === '' || $data['origin'] === '' || !$data['receiving_clerk_id']) {
            flash('Please complete the Title, Client / Origin, Date Received, and Admin Receiving Section for the Certified Urgent record.', 'error');
            redirect($recordFormUrl);
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $data['received_date']) || !strtotime((string) $data['received_date'])) {
            flash('Please enter a valid Date Received for the Certified Urgent record.', 'error');
            redirect($recordFormUrl);
        }
    }

    $pendingAttachments = [];
    $attachmentUpload = $_FILES['attachments'] ?? ($_FILES['attachment'] ?? null);
    $attachmentFiles = [];
    if ($attachmentUpload && isset($attachmentUpload['name'])) {
        if (is_array($attachmentUpload['name'])) {
            foreach ($attachmentUpload['name'] as $index => $name) {
                $attachmentFiles[] = [
                    'name' => $name,
                    'tmp_name' => $attachmentUpload['tmp_name'][$index] ?? '',
                    'error' => $attachmentUpload['error'][$index] ?? UPLOAD_ERR_NO_FILE,
                    'size' => $attachmentUpload['size'][$index] ?? 0,
                ];
            }
        } else {
            $attachmentFiles[] = $attachmentUpload;
        }
    }
    $attachmentFiles = array_values(array_filter($attachmentFiles, fn ($file) => ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE));
    if (!$id && $attachmentFiles) {
        if (!can_create_records()) {
            flash('Your role cannot attach files while creating a new record.', 'error');
            redirect($recordFormUrl);
        }

        if (count($attachmentFiles) > NEW_RECORD_ATTACHMENT_MAX_FILES) {
            flash('You may attach up to 10 files while creating a record.', 'error');
            redirect($recordFormUrl);
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $totalAttachmentBytes = 0;
        foreach ($attachmentFiles as $attachmentFile) {
            if (($attachmentFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                flash('One of the selected files could not be read. Please select the files again.', 'error');
                redirect($recordFormUrl);
            }

            $fileSize = (int) ($attachmentFile['size'] ?? 0);
            if ($fileSize <= 0 || $fileSize > NEW_RECORD_ATTACHMENT_MAX_BYTES) {
                flash('Each attachment must be 10 MB or smaller.', 'error');
                redirect($recordFormUrl);
            }
            $totalAttachmentBytes += $fileSize;
            if ($totalAttachmentBytes > NEW_RECORD_ATTACHMENT_MAX_TOTAL_BYTES) {
                flash('The combined size of the selected files must be 35 MB or smaller.', 'error');
                redirect($recordFormUrl);
            }

            $mimeType = (string) $finfo->file((string) $attachmentFile['tmp_name']);
            if (!array_key_exists($mimeType, NEW_RECORD_ATTACHMENT_ALLOWED_MIME_TYPES)) {
                flash('Only PDF and image files are allowed.', 'error');
                redirect($recordFormUrl);
            }

            $pendingAttachments[] = [
                'tmp_name' => (string) $attachmentFile['tmp_name'],
                'name' => substr(basename((string) ($attachmentFile['name'] ?? 'attachment.' . NEW_RECORD_ATTACHMENT_ALLOWED_MIME_TYPES[$mimeType])), 0, 255),
                'mime_type' => $mimeType,
                'extension' => NEW_RECORD_ATTACHMENT_ALLOWED_MIME_TYPES[$mimeType],
                'size' => $fileSize,
            ];
        }
    }

    if (!$autoAssignControlNumber && $data['control_number'] === '') {
        flash('Please enter a Communication Number.', 'error');
        redirect($recordFormUrl);
    }
    if (!$autoAssignControlNumber && control_number_exists($data['control_number'], $id ?: null)) {
        flash('That Communication Number is already assigned to another record.', 'error');
        redirect($recordFormUrl);
    }

    $saveWithUniqueControlNumber = static function (callable $save) use (&$data, $autoAssignControlNumber): void {
        $lockName = '';
        try {
            if ($autoAssignControlNumber) {
                $lockName = acquire_control_number_lock($data['document_type']);
                $data['control_number'] = next_control_number($data['document_type']);
            }
            $save();
        } finally {
            if ($lockName !== '') {
                release_control_number_lock($lockName);
            }
        }
    };

    if ($id) {
        $oldStatus = $record['status'];
        $oldLocation = $record['current_location'];
        $oldTitle = trim((string) ($record['title'] ?? ''));
        $oldPlenaryPrintTitle = trim((string) ($record['plenary_print_title'] ?? ''));
        $titleChanged = $oldTitle !== $data['title'];
        $plenaryPrintTitleChanged = $isLawsAndRulesPlenaryEdit
            && $oldPlenaryPrintTitle !== trim((string) $data['plenary_print_title']);
        $isReceivingStaffCommentResponse = (current_user()['role'] ?? '') === 'receiving_clerk'
            && $receivingStaffCommentPending;
        try {
            $saveWithUniqueControlNumber(static function () use (&$data, $id): void {
                $stmt = db()->prepare('UPDATE records SET control_number=?, title=?, plenary_print_title=?, document_type=?, origin=?, client_name=?, contact_number=?, client_email=?, committee_id=?, assigned_user_id=?, receiving_clerk_id=?, priority=?, status=?, received_date=?, due_date=?, current_location=?, remarks=?, proposed_by_city_council_member=?, proposed_ordinance_number=?, proposed_resolution_number=?, updated_by=? WHERE id=?');
                $stmt->execute([
                    $data['control_number'], $data['title'], $data['plenary_print_title'], $data['document_type'], $data['origin'], $data['client_name'], $data['contact_number'], $data['client_email'], $data['committee_id'],
                    $data['assigned_user_id'], $data['receiving_clerk_id'], $data['priority'], $data['status'], $data['received_date'], $data['due_date'],
                    $data['current_location'], $data['remarks'], $data['proposed_by_city_council_member'], $data['proposed_ordinance_number'], $data['proposed_resolution_number'], $data['updated_by'], $id,
                ]);
            });
        } catch (Throwable $error) {
            if (is_duplicate_control_number_error($error)) {
                flash('That Communication Number was just assigned to another record. Please save again to receive the next available number.', 'error');
                redirect($recordFormUrl);
            }
            throw $error;
        }

        if ($oldStatus !== $data['status'] || $oldLocation !== $data['current_location'] || $titleChanged || $plenaryPrintTitleChanged || $isReceivingStaffCommentResponse || $hasCityReceivingStaffComment || $cityCouncilProposalChanged) {
            $move = db()->prepare('INSERT INTO record_movements (record_id, from_status, to_status, from_location, to_location, notes, record_title, previous_title, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
            if ($isReceivingStaffCommentResponse) {
                $movementNotes = 'Receiving Staff updated the record in response to the City Secretary comment.';
            } elseif ($hasCityReceivingStaffComment) {
                $movementNotes = trim(implode("\n\n", array_filter([
                    $data['remarks'],
                    $cityCouncilProposalMovementNote,
                    $cityReceivingStaffCommentNote,
                ])));
            } else {
                $movementNotes = trim(implode("\n\n", array_filter([
                    $cityCouncilProposalMovementNote,
                    $data['remarks'],
                ])));
            }
            if ($titleChanged) {
                $movementNotes = trim('Record title updated.' . ($movementNotes !== '' ? "\n" . $movementNotes : ''));
            } elseif ($plenaryPrintTitleChanged) {
                $movementNotes = trim('Laws and Rules title updated for plenary. The previous committee title remains in the tracking history.' . ($movementNotes !== '' ? "\n" . $movementNotes : ''));
            }
            $movementTitle = $plenaryPrintTitleChanged
                ? (string) $data['plenary_print_title']
                : (string) $data['title'];
            $previousMovementTitle = $titleChanged
                ? $oldTitle
                : ($plenaryPrintTitleChanged ? ($oldPlenaryPrintTitle !== '' ? $oldPlenaryPrintTitle : $oldTitle) : null);
            $move->execute([$id, $oldStatus, $data['status'], $oldLocation, $data['current_location'], $movementNotes, $movementTitle, $previousMovementTitle, current_user()['id']]);
        }
        save_record_committees($id, $data['document_type'] === 'Committee Referrals' ? $postedCommitteeIds : []);
        audit_log('record_update', 'Updated record ' . $data['control_number'] . '.', 'record', $id);
        if ($cityCouncilProposalChanged) {
            audit_log('city_council_proposal_update', $proposedByCityCouncilMember
                ? 'Marked ' . $data['control_number'] . ' as proposed by a City Council Member.'
                : 'Removed the City Council Member proposal marking from ' . $data['control_number'] . '.', 'record', $id);
        }
        if ($titleChanged) {
            audit_log('record_title_update', 'Changed record title from "' . $oldTitle . '" to "' . $data['title'] . '".', 'record', $id);
        }
        if ($plenaryPrintTitleChanged) {
            audit_log('plenary_print_title_update', 'Changed the Laws and Rules title from "' . ($oldPlenaryPrintTitle !== '' ? $oldPlenaryPrintTitle : $oldTitle) . '" to "' . $data['plenary_print_title'] . '". The previous committee title was preserved in tracking history.', 'record', $id);
        }
        flash($plenaryPrintTitleChanged
            ? 'Laws and Rules title updated. The previous committee title remains in the tracking history.'
            : 'Record updated successfully.');
    } else {
        $newRecordId = 0;
        try {
            $saveWithUniqueControlNumber(static function () use (&$data, &$newRecordId): void {
                $stmt = db()->prepare('INSERT INTO records (control_number, title, document_type, origin, client_name, contact_number, client_email, committee_id, assigned_user_id, receiving_clerk_id, priority, status, received_date, due_date, current_location, remarks, created_by, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([
                    $data['control_number'], $data['title'], $data['document_type'], $data['origin'], $data['client_name'], $data['contact_number'], $data['client_email'], $data['committee_id'],
                    $data['assigned_user_id'], $data['receiving_clerk_id'], $data['priority'], $data['status'], $data['received_date'], $data['due_date'],
                    $data['current_location'], $data['remarks'], current_user()['id'], current_user()['id'],
                ]);
                $newRecordId = (int) db()->lastInsertId();
            });
        } catch (Throwable $error) {
            if (is_duplicate_control_number_error($error)) {
                flash('That Communication Number was just assigned to another record. Please save again to receive the next available number.', 'error');
                redirect($recordFormUrl);
            }
            throw $error;
        }
        if ($newRecordId <= 0) {
            throw new RuntimeException('The new record was saved without a valid record ID.');
        }
        $id = $newRecordId;
        unset($_SESSION['record_form_submission_tokens'][$recordFormSubmissionToken]);
        $move = db()->prepare('INSERT INTO record_movements (record_id, to_status, to_location, notes, record_title, updated_by) VALUES (?, ?, ?, ?, ?, ?)');
        $creationNote = $data['document_type'] === 'Certified Urgent'
            ? 'Certified Urgent record created and routed directly for plenary session.'
            : 'Record created.';
        $move->execute([$id, $data['status'], $data['current_location'], $creationNote, $data['title'], current_user()['id']]);
        if ($data['document_type'] === 'Committee Referrals') {
            save_record_committees($id, $postedCommitteeIds);
        }
        $attachmentSaveWarning = '';
        if ($pendingAttachments) {
            $uploadDir = dirname(__DIR__) . '/storage/attachments';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0775, true);
            }
            $attachmentStmt = db()->prepare('INSERT INTO record_attachments (record_id, original_name, stored_name, mime_type, file_size, uploaded_by) VALUES (?, ?, ?, ?, ?, ?)');
            $uploadedAttachmentCount = 0;
            foreach ($pendingAttachments as $pendingAttachment) {
                $storedName = $id . '_' . bin2hex(random_bytes(16)) . '.' . $pendingAttachment['extension'];
                if (!move_uploaded_file($pendingAttachment['tmp_name'], $uploadDir . '/' . $storedName)) {
                    continue;
                }
                $attachmentStmt->execute([
                    $id,
                    $pendingAttachment['name'] !== '' ? $pendingAttachment['name'] : 'attachment.' . $pendingAttachment['extension'],
                    $storedName,
                    $pendingAttachment['mime_type'],
                    $pendingAttachment['size'],
                    current_user()['id'],
                ]);
                $uploadedAttachmentCount++;
                audit_log('record_attachment_upload', 'Uploaded attachment ' . $pendingAttachment['name'] . ' while creating record ' . $data['control_number'] . '.', 'record', $id);
            }
            if ($uploadedAttachmentCount !== count($pendingAttachments)) {
                $attachmentSaveWarning = 'Record created, but only ' . $uploadedAttachmentCount . ' of ' . count($pendingAttachments) . ' attachments were saved. You may upload the remaining files from the record page.';
            }
        }
        audit_log('record_create', 'Created record ' . $data['control_number'] . '.', 'record', $id);
        if ($attachmentSaveWarning !== '') {
            flash($attachmentSaveWarning, 'error');
        } else {
            flash('Record created successfully.');
        }
    }

    redirect($isPopup ? $popupCloseUrl : '/record_view.php?id=' . $id);
}

$committees = db()->query('SELECT id, name FROM committees ORDER BY name')->fetchAll();
$selectedCommitteeIds = $id
    ? array_map(fn ($row) => (int) $row['committee_id'], record_committee_rows((int) $id, !empty($record['committee_id']) ? (int) $record['committee_id'] : null))
    : (!empty($record['committee_id']) ? [(int) $record['committee_id']] : []);
$selectedLeadCommitteeId = (int) ($record['committee_id'] ?? ($selectedCommitteeIds[0] ?? 0));
if ($selectedLeadCommitteeId && !in_array($selectedLeadCommitteeId, $selectedCommitteeIds, true)) {
    $selectedLeadCommitteeId = $selectedCommitteeIds[0] ?? 0;
}
$committeeChiefRows = db()->query("SELECT a.committee_id, u.id user_id, COALESCE(NULLIF(u.division_name, ''), u.name) user_name FROM committee_division_chiefs a INNER JOIN users u ON u.id = a.user_id WHERE u.is_active = 1 ORDER BY user_name")->fetchAll();
$committeeChiefMap = [];
foreach ($committeeChiefRows as $chiefRow) {
    $committeeChiefMap[(int) $chiefRow['committee_id']] = [
        'id' => (int) $chiefRow['user_id'],
        'name' => $chiefRow['user_name'],
    ];
}
$committeeSecretariatRows = db()->query("SELECT a.committee_id, u.name user_name FROM committee_secretariats a INNER JOIN users u ON u.id = a.user_id WHERE u.is_active = 1 ORDER BY u.name")->fetchAll();
$committeeSecretariatMap = [];
foreach ($committeeSecretariatRows as $secretariatRow) {
    $committeeId = (int) $secretariatRow['committee_id'];
    $committeeSecretariatMap[$committeeId][] = $secretariatRow['user_name'];
}
foreach ($committeeSecretariatMap as $committeeId => $names) {
    $committeeSecretariatMap[$committeeId] = implode(', ', $names);
}
$selectedChief = $selectedCommitteeIds ? ($committeeChiefMap[(int) $selectedCommitteeIds[0]] ?? null) : null;
$selectedChiefNames = array_values(array_unique(array_filter(array_map(fn ($committeeId) => $committeeChiefMap[(int) $committeeId]['name'] ?? '', $selectedCommitteeIds))));
$selectedSecretariatNames = array_values(array_unique(array_filter(array_map(fn ($committeeId) => $committeeSecretariatMap[(int) $committeeId] ?? '', $selectedCommitteeIds))));
$selectedSecretariat = implode('; ', $selectedSecretariatNames);
$users = db()->query("SELECT id, name FROM users WHERE is_active = 1 AND role = 'division_chief' ORDER BY name")->fetchAll();
$receivingClerks = db()->query("SELECT id, name FROM users WHERE is_active = 1 AND role = 'receiving_clerk' ORDER BY name")->fetchAll();
$statuses = $recordFormStatusOptions;
$priorities = ['Low', 'Normal', 'High', 'Urgent'];
$pendingMergeTargetControlNumber = pending_existing_record_target($record['remarks'] ?? '');
$remarksForForm = strip_pending_existing_record_tag($record['remarks'] ?? '');
$canTagAsNewOrExisting = in_array(current_user()['role'] ?? '', ['admin', 'city_secretary', 'receiving_clerk'], true)
    && (!$id || ($record['status'] ?? '') === 'Received');
$canShowClientContactFields = in_array(current_user()['role'] ?? '', ['admin', 'city_secretary', 'receiving_clerk', 'administrative_support'], true)
    || $canCorrectForPlenaryRecord;
$reviewAttachmentCount = 0;
if ($id
    && ($record['status'] ?? '') === 'Received'
    && can_city_secretary_action()
    && can_view_record_attachments($record)) {
    try {
        $reviewAttachmentStmt = db()->prepare('SELECT COUNT(*) FROM record_attachments WHERE record_id = ?');
        $reviewAttachmentStmt->execute([$id]);
        $reviewAttachmentCount = (int) $reviewAttachmentStmt->fetchColumn();
    } catch (Throwable $error) {
        $reviewAttachmentCount = 0;
    }
}
$mergeCandidateRecords = [];
$mergeCandidateCommitteeMap = [];
if ($canTagAsNewOrExisting) {
    $candidateStmt = db()->prepare('SELECT id, control_number, title, document_type, committee_id FROM records WHERE ' . ($id ? 'id <> ? AND ' : '') . 'control_number <> "" ORDER BY updated_at DESC, id DESC LIMIT 200');
    $candidateStmt->execute($id ? [$id] : []);
    $mergeCandidateRecords = $candidateStmt->fetchAll();
    foreach ($mergeCandidateRecords as $candidate) {
        $controlNumberKey = strtoupper(trim((string) $candidate['control_number']));
        if ($controlNumberKey === '' || array_key_exists($controlNumberKey, $mergeCandidateCommitteeMap)) {
            continue;
        }

        $candidateCommitteeRows = record_committee_rows(
            (int) $candidate['id'],
            !empty($candidate['committee_id']) ? (int) $candidate['committee_id'] : null
        );
        $mergeCandidateCommitteeMap[$controlNumberKey] = array_values(array_unique(array_map(
            fn ($row) => (int) $row['committee_id'],
            $candidateCommitteeRows
        )));
    }
}

require __DIR__ . '/../app/partials/header.php';
?>
<?php if ($isPopup): ?>
<div class="modal-backdrop" role="presentation">
    <div class="panel user-edit-modal record-form-modal" role="dialog" aria-modal="true" aria-labelledby="record_form_title">
<?php endif; ?>
<div class="page-head">
    <div>
        <h1 id="record_form_title"><?= $id && $isPopup ? 'Edit Record' : ($id && can_city_secretary_action() && ($record['status'] ?? '') === 'Received' ? 'Review Record' : ($id && is_administrative_document_type($record['document_type'] ?? '') ? 'Forward to' : ($id ? 'Edit Record' : 'New Record'))) ?></h1>
        <p class="muted"><?= ($record['status'] ?? '') === 'Received' ? 'The record type and routing are still for SP Secretary review.' : 'Encode record details and routing information.' ?></p>
    </div>
    <?php if ($isPopup): ?>
        <div class="actions">
            <a class="modal-close" href="<?= e($popupCloseUrl) ?>" aria-label="Close record editor">X</a>
        </div>
    <?php endif; ?>
</div>

<?php if ($receivingStaffComment): ?>
    <section class="receiving-correction-notice" role="status">
        <strong><?= (current_user()['role'] ?? '') === 'receiving_clerk' ? 'City Secretary Comment' : 'Waiting for Receiving Staff Correction' ?></strong>
        <p><?= nl2br(e($receivingStaffComment['comment'] ?? '')) ?></p>
        <span><?= e($receivingStaffComment['author_name'] ?? 'City Secretary') ?>, <?= e(display_datetime($receivingStaffComment['created_at'] ?? '')) ?></span>
    </section>
<?php endif; ?>

<?php if ($reviewAttachmentCount > 0): ?>
    <section class="panel" style="margin-bottom:16px;">
        <div class="panel-title-row">
            <div>
                <h2>Attached Documents</h2>
                <p class="muted"><?= (int) $reviewAttachmentCount ?> <?= $reviewAttachmentCount === 1 ? 'file is' : 'files are' ?> available for review.</p>
            </div>
            <a class="btn secondary record-document-action" href="<?= e($attachmentViewUrl) ?>">View All Documents</a>
        </div>
    </section>
<?php endif; ?>

<form method="post" class="<?= $isPopup ? 'form-grid record-form-panel' : 'panel form-grid record-form-panel' ?>" enctype="multipart/form-data">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <?php if (!$id): ?>
        <input type="hidden" name="record_form_submission_token" value="<?= e($recordFormSubmissionToken) ?>">
    <?php endif; ?>
    <label>Communication Number
        <input name="control_number" required value="<?= e($record['control_number']) ?>">
    </label>
    <label>Record Type
        <?php if ($canChooseDocumentType): ?>
            <select name="document_type" id="document_type">
                <?php foreach ($recordFormDocumentTypes as $typeOption): ?>
                    <option value="<?= e($typeOption) ?>" <?= ($record['document_type'] ?? '') === $typeOption ? 'selected' : '' ?>><?= e($typeOption) ?></option>
                <?php endforeach; ?>
            </select>
            <?php if (($record['status'] ?? '') === 'Received'): ?>
                <span class="muted" id="record_type_help">For review. The SP Secretary will finalize this type.</span>
            <?php elseif ($canCorrectForPlenaryRecord): ?>
                <span class="muted" id="record_type_help">Choose the correct record type. Reclassified records return to Received for the appropriate workflow.</span>
            <?php endif; ?>
        <?php else: ?>
            <input value="<?= e($record['document_type']) ?>" readonly>
            <input type="hidden" name="document_type" value="<?= e($record['document_type']) ?>">
        <?php endif; ?>
    </label>
    <label class="full standard-title-field"><?= $isLawsAndRulesPlenaryEdit ? 'Laws and Rules Title' : 'Title' ?>
        <textarea name="title" required rows="4" maxlength="1000"><?= e($recordFormTitle) ?></textarea>
        <?php if ($isLawsAndRulesPlenaryEdit): ?>
            <span class="muted">This becomes the current title for Laws and Rules, City Secretary, and Administrator views. The previous committee title remains in the tracking history.</span>
        <?php endif; ?>
    </label>

    <?php if ($mergeCandidateRecords): ?>
        <section class="full review-choice-panel record-tagging-field">
            <h2>Tag as New or Existing Record</h2>
            <label class="choice-line">
                <input type="radio" name="review_action" value="finalize" <?= $pendingMergeTargetControlNumber === '' ? 'checked' : '' ?>>
                <span>Tag as new record</span>
            </label>
            <label class="choice-line">
                <input type="radio" name="review_action" value="merge_existing" <?= $pendingMergeTargetControlNumber !== '' ? 'checked' : '' ?>>
                <span>Tag as existing record / update to old record</span>
            </label>
            <label id="merge_target_wrap">Existing Communication Number
                <input name="merge_target_control_number" id="merge_target_control_number" list="merge_record_candidates" value="<?= e($pendingMergeTargetControlNumber) ?>" placeholder="Type or choose the old Communication Number">
                <span class="muted"><?= can_city_secretary_action() ? 'Saving the review will merge this record into the selected old Communication Number.' : 'This tag will remain pending until the SP Secretary reviews the record.' ?></span>
            </label>
            <datalist id="merge_record_candidates">
                <?php foreach ($mergeCandidateRecords as $candidate): ?>
                    <option value="<?= e($candidate['control_number']) ?>"><?= e($candidate['document_type'] . ' - ' . display_record_title($candidate['title'])) ?></option>
                <?php endforeach; ?>
            </datalist>
        </section>
    <?php endif; ?>

    <?php if ($canReviewCityCouncilProposal): ?>
        <?php require __DIR__ . '/../app/partials/city_council_proposal_review_fields.php'; ?>
    <?php endif; ?>

    <div class="full client-details-row">
        <label class="non-committee-field">Client / Origin
            <input name="origin" required value="<?= e($record['origin']) ?>">
        </label>
        <label class="legislative-field">Client / Origin
            <input name="client_name" value="<?= e($record['client_name'] ?? '') ?>">
        </label>
        <?php if ($canShowClientContactFields): ?>
            <label class="client-contact-field"><span class="form-label-text">Client Contact Number <span class="muted">(Optional)</span></span>
                <input name="contact_number" value="<?= e($record['contact_number'] ?? '') ?>">
            </label>
            <label class="client-contact-field"><span class="form-label-text">Client Email <span class="muted">(Optional)</span></span>
                <input type="email" name="client_email" value="<?= e($record['client_email'] ?? '') ?>">
            </label>
        <?php endif; ?>
    </div>
    <label class="admin-field">Date Received
        <input value="<?= e(display_date($record['received_date'] ?? '')) ?>" readonly>
    </label>
    <label class="admin-field">Receiving Staff
        <input value="<?= e($receivingClerkDisplay !== '' ? $receivingClerkDisplay : 'Not set') ?>" readonly>
    </label>
    <?php if ($id && can_city_secretary_action()): ?>
        <div class="admin-field forwarded-recipient-field">
            <span>Forwarded to:</span>
            <details class="forwarded-recipient-dropdown" id="forwarded_to">
                <summary aria-describedby="forwarded_to_help">
                    <span id="forwarded_to_summary"><?= e($selectedForwardOptions ? implode(', ', $selectedForwardOptions) : 'Select recipients') ?></span>
                    <span class="forwarded-recipient-chevron" aria-hidden="true">&#9662;</span>
                </summary>
                <div class="forwarded-recipient-options" role="group" aria-label="Forwarded to recipients">
                    <?php foreach ($forwardOptions as $option): ?>
                        <label class="forwarded-recipient-option">
                            <input type="checkbox" name="forwarded_to[]" value="<?= e($option) ?>" <?= in_array($option, $selectedForwardOptions, true) ? 'checked' : '' ?>>
                            <span><?= e($option) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </details>
            <span class="muted" id="forwarded_to_help">Select one or more recipients.</span>
        </div>
    <?php endif; ?>
    <?php if ($canChooseReferralCommittee): ?>
        <label class="legislative-field full">Committee(s)
            <div class="committee-checkbox-menu" role="group" aria-label="Committee(s) selection">
                <?php foreach ($committees as $committee): ?>
                    <label class="committee-checkbox-option">
                        <input type="checkbox" name="committee_ids[]" value="<?= (int) $committee['id'] ?>" <?= in_array((int) $committee['id'], $selectedCommitteeIds, true) ? 'checked' : '' ?>>
                        <span><?= e($committee['name']) ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
            <span class="muted"><?= $id > 0 && can_city_secretary_action() ? 'Check one or more committees. A correction note keeps the review pending; approval without a correction note finalizes it for committee action and printing.' : 'Check one or more committees. This is not final until reviewed by the SP Secretary.' ?></span>
        </label>
        <label class="legislative-field">Lead Committee
            <select name="lead_committee_id" id="lead_committee_id">
                <option value="">Use first selected committee</option>
                <?php foreach ($committees as $committee): ?>
                    <option value="<?= (int) $committee['id'] ?>" <?= (int) $committee['id'] === $selectedLeadCommitteeId ? 'selected' : '' ?>><?= e($committee['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <span class="muted">The lead committee prints first.</span>
        </label>
    <?php else: ?>
        <input type="hidden" name="committee_id" value="<?= e((string) ($record['committee_id'] ?? '')) ?>">
        <?php if (!empty($record['committee_id'])): ?>
            <div class="legislative-field"><strong>Committee(s)</strong><br><span class="muted">Assigned by City Secretary</span></div>
        <?php endif; ?>
    <?php endif; ?>
    <div class="full record-routing-details-row">
    <label class="plenary-entry-field">Date Received
        <input type="date" name="received_date" value="<?= e($record['received_date']) ?>">
    </label>
    <?php if ($id && can_city_secretary_action() && ($record['status'] ?? '') === 'Received'): ?>
        <label class="legislative-field city-review-note-field">Correction Note to Receiving Staff
            <textarea class="compact-review-note" name="receiving_section_note" rows="2" maxlength="500" placeholder="Leave blank to approve, or enter the correction required."></textarea>
        </label>
    <?php endif; ?>
    <?php if ($id || (current_user()['role'] ?? '') === 'receiving_clerk'): ?>
        <label class="plenary-entry-field">Receiving Staff
            <input value="<?= e($receivingClerkDisplay) ?>" readonly>
            <input type="hidden" name="receiving_clerk_id" value="<?= (int) ($id ? ($record['receiving_clerk_id'] ?? 0) : current_user()['id']) ?>">
            <?php if ($id): ?>
                <span class="muted">The receiving staff is recorded when the record is created and cannot be changed.</span>
            <?php endif; ?>
        </label>
    <?php else: ?>
        <label class="plenary-entry-field">Receiving Staff
            <select name="receiving_clerk_id">
                <option value="">Select Receiving Staff</option>
                <?php foreach ($receivingClerks as $clerk): ?>
                    <option value="<?= (int) $clerk['id'] ?>" <?= (string) ($record['receiving_clerk_id'] ?? '') === (string) $clerk['id'] ? 'selected' : '' ?>><?= e($clerk['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
    <?php endif; ?>
    <?php if (can_city_secretary_action()): ?>
    <label class="legislative-field">Division
        <input id="assigned_staff_display" value="<?= e(implode('; ', $selectedChiefNames)) ?>" readonly placeholder="Select a committee to display the Division">
        <input type="hidden" name="assigned_user_id" id="assigned_user_id" value="<?= e((string) ($selectedChief['id'] ?? $record['assigned_user_id'] ?? '')) ?>">
    </label>
    <label class="legislative-field">Assigned Secretariat
        <input id="assigned_secretariat_display" value="<?= e($selectedSecretariat) ?>" readonly placeholder="Select a committee to display the assigned Secretariat">
    </label>
    <?php if ($hideStatusInRecordForm): ?>
        <input type="hidden" name="status" value="<?= e($record['status']) ?>">
    <?php elseif ($canEditStatusInRecordForm): ?>
        <label>Status
            <select name="status" id="record_status" required>
                <?php foreach ($statuses as $status): ?>
                    <option value="<?= e($status) ?>" <?= ($record['status'] ?? '') === $status ? 'selected' : '' ?>><?= e($status) ?></option>
                <?php endforeach; ?>
            </select>
            <span class="muted">Changes are recorded in the tracking history.</span>
        </label>
    <?php else: ?>
        <label class="legislative-field">Status
            <input id="status_display" value="<?= e(($record['status'] ?? '') === 'Received' ? 'Received - For SP Secretary Review' : (!empty($record['committee_id']) ? 'Pending to the Committee' : $record['status'])) ?>" readonly>
            <input type="hidden" name="status" value="<?= e($record['status']) ?>">
        </label>
    <?php endif; ?>
    <?php else: ?>
        <input type="hidden" name="assigned_user_id" value="<?= e((string) ($record['assigned_user_id'] ?? '')) ?>">
    <?php endif; ?>
    <?php if (!can_city_secretary_action() && in_array(current_user()['role'] ?? '', ['division_chief', 'secretariat'], true)): ?>
        <label class="legislative-field">Division
            <input value="<?= e($selectedChiefNames ? implode('; ', $selectedChiefNames) : 'Unassigned') ?>" readonly>
        </label>
        <label class="legislative-field">Assigned Secretariat
            <input value="<?= e($selectedSecretariat !== '' ? $selectedSecretariat : 'Unassigned') ?>" readonly>
        </label>
    <?php endif; ?>
    <?php if (!can_city_secretary_action() && is_admin() && can_update_record_status($record)): ?>
    <label>Status
        <select name="status">
            <?php foreach ($statuses as $status): ?>
                <option value="<?= e($status) ?>" <?= $record['status'] === $status ? 'selected' : '' ?>><?= e($status) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <?php elseif (!can_city_secretary_action()): ?>
        <input type="hidden" name="status" value="<?= e($record['status']) ?>">
    <?php endif; ?>
    </div>
    <label class="full admin-field"><span class="form-label-text">Remarks <span class="muted">(Optional)</span></span>
        <textarea name="remarks" id="remarks_field"><?= e($remarksForForm) ?></textarea>
    </label>
    <?php if (!$id && can_create_records()): ?>
        <label class="full">Attach Files
            <input type="file" name="attachments[]" accept="application/pdf,image/*" multiple>
            <span class="muted">Optional. Select up to 10 files. Maximum 10 MB per file and 35 MB combined.</span>
        </label>
    <?php endif; ?>
    <div class="actions full">
        <button class="btn record-save-action" type="submit"><?= $id && can_city_secretary_action() && ($record['status'] ?? '') === 'Received' ? 'Save Review' : ($id && is_administrative_document_type($record['document_type'] ?? '') ? 'Save Forwarding' : 'Save Record') ?></button>
        <a class="btn secondary record-cancel-action" href="<?= e($isPopup ? $popupCloseUrl : '/records.php') ?>"><?= $isPopup ? 'Close' : 'Cancel' ?></a>
    </div>
</form>
<?php if ($isPopup): ?>
    </div>
</div>
<?php endif; ?>
<script>
const controlInput = document.querySelector('input[name="control_number"]');
const recordForm = controlInput ? controlInput.form : null;
const recordSaveButton = recordForm ? recordForm.querySelector('.record-save-action') : null;
const documentTypeSelect = document.getElementById('document_type');
const administrativeDocumentTypes = <?= json_encode(administrative_document_types(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const nextControlNumbers = <?= json_encode($nextControlNumbers, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const committeeChiefs = <?= json_encode($committeeChiefMap, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const committeeSecretariats = <?= json_encode($committeeSecretariatMap, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const mergeCandidateCommittees = <?= json_encode($mergeCandidateCommitteeMap, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const committeeInputs = Array.from(document.querySelectorAll('input[name="committee_ids[]"]'));
const committeeSelect = document.querySelector('select[name="committee_id"]');
const leadCommitteeSelect = document.getElementById('lead_committee_id');
const assignedStaffDisplay = document.getElementById('assigned_staff_display');
const assignedStaffInput = document.getElementById('assigned_user_id');
const assignedSecretariatDisplay = document.getElementById('assigned_secretariat_display');
const statusDisplay = document.getElementById('status_display');
const editableStatusSelect = document.getElementById('record_status');
const certifiedUrgentStatuses = ['For Plenary Session', 'Scheduled for Plenary', 'Disapproved', 'Approved in the Plenary'];
const canCorrectForPlenaryRecord = <?= $canCorrectForPlenaryRecord ? 'true' : 'false' ?>;
const forwardedToDropdown = document.getElementById('forwarded_to');
const forwardedToSummary = document.getElementById('forwarded_to_summary');
const forwardedToInputs = Array.from(document.querySelectorAll('input[name="forwarded_to[]"]'));
const remarksField = document.getElementById('remarks_field');
const clientInput = document.querySelector('input[name="client_name"]');
const originInput = document.querySelector('input[name="origin"]');
const reviewActionInputs = Array.from(document.querySelectorAll('input[name="review_action"]'));
const recordTypeHelp = document.getElementById('record_type_help');
const mergeTargetInput = document.getElementById('merge_target_control_number');
const mergeTargetWrap = document.getElementById('merge_target_wrap');
const receivingSectionNoteInput = document.querySelector('[name="receiving_section_note"]');
const cityCouncilProposalPanel = document.querySelector('[data-city-council-proposal-panel]');
const existingId = <?= (int) $id ?>;
const canFinalizeCommitteeAssignment = <?= $id > 0 && can_city_secretary_action() ? 'true' : 'false' ?>;
const originalDocumentType = '<?= e($record['document_type']) ?>';
const originalControlNumber = '<?= e($record['control_number']) ?>';
const originalCommitteeIds = committeeInputs.filter((input) => input.checked).map((input) => input.value);
const originalLeadCommitteeId = leadCommitteeSelect ? leadCommitteeSelect.value : '';
const getSelectedDocumentType = () => documentTypeSelect ? documentTypeSelect.value : '<?= e($record['document_type']) ?>';
const isMergeReviewSelected = () => reviewActionInputs.some((input) => input.checked && input.value === 'merge_existing');
const syncPartyField = (targetType = getSelectedDocumentType()) => {
    if (!clientInput || !originInput) {
        return;
    }

    if (targetType === 'Committee Referrals' && clientInput.value.trim() === '' && originInput.value.trim() !== '') {
        clientInput.value = originInput.value;
    }
    if (targetType !== 'Committee Referrals' && originInput.value.trim() === '' && clientInput.value.trim() !== '') {
        originInput.value = clientInput.value;
    }
};
const setCommitteeSelection = (committeeIds, leadCommitteeId = '') => {
    if (!committeeInputs.length) {
        return;
    }

    const selectedIds = committeeIds.map(String);
    committeeInputs.forEach((input) => {
        input.checked = selectedIds.includes(input.value);
    });
    syncAssignedChief();
    if (leadCommitteeSelect && selectedIds.length) {
        leadCommitteeSelect.value = String(leadCommitteeId || selectedIds[0]);
        syncAssignedChief();
    }

    const firstSelectedOption = committeeInputs
        .find((input) => input.checked)
        ?.closest('.committee-checkbox-option');
    if (firstSelectedOption) {
        firstSelectedOption.scrollIntoView({ block: 'nearest' });
    }
};
const syncExistingRecordCommittees = () => {
    if (!mergeTargetInput || !isMergeReviewSelected()) {
        return;
    }

    const targetControlNumber = mergeTargetInput.value.trim().toUpperCase();
    if (!Object.prototype.hasOwnProperty.call(mergeCandidateCommittees, targetControlNumber)) {
        return;
    }

    const targetCommitteeIds = mergeCandidateCommittees[targetControlNumber] || [];
    setCommitteeSelection(targetCommitteeIds, targetCommitteeIds[0] || '');
};
const syncAssignedChief = () => {
    if (!committeeInputs.length && !committeeSelect) {
        return;
    }

    const selectedValues = committeeInputs
        .filter((input) => input.checked)
        .map((input) => input.value)
        .filter(Boolean);
    const fallbackValue = committeeSelect && committeeSelect.value ? [committeeSelect.value] : [];
    const committeeValues = selectedValues.length ? selectedValues : fallbackValue;
    const selectedCommittees = committeeInputs.length
        ? committeeInputs
            .filter((input) => input.checked)
            .map((input) => ({
                value: input.value,
                label: (input.closest('.committee-checkbox-option')?.innerText || input.value).trim(),
            }))
        : (committeeSelect && committeeSelect.value
            ? [{
                value: committeeSelect.value,
                label: committeeSelect.options[committeeSelect.selectedIndex]?.text || committeeSelect.value,
            }]
            : []);
    let orderedCommitteeValues = committeeValues;
    if (leadCommitteeSelect) {
        const previousLead = leadCommitteeSelect.value;
        leadCommitteeSelect.innerHTML = '';
        if (selectedCommittees.length === 0) {
            leadCommitteeSelect.add(new Option('Select committee first', ''));
        } else if (selectedCommittees.length > 1) {
            leadCommitteeSelect.add(new Option('Select lead committee', ''));
        }
        selectedCommittees.forEach((committee) => {
            leadCommitteeSelect.add(new Option(committee.label, committee.value));
        });
        if (selectedCommittees.length === 1) {
            leadCommitteeSelect.value = selectedCommittees[0].value;
        } else if (committeeValues.includes(previousLead)) {
            leadCommitteeSelect.value = previousLead;
        } else {
            leadCommitteeSelect.value = '';
        }
        leadCommitteeSelect.required = committeeValues.length > 1;
        if (leadCommitteeSelect.value) {
            orderedCommitteeValues = [
                leadCommitteeSelect.value,
                ...committeeValues.filter((committeeId) => committeeId !== leadCommitteeSelect.value),
            ];
        }
    }
    const chiefNames = [];
    let firstChief = null;
    orderedCommitteeValues.forEach((committeeId) => {
        const chief = committeeChiefs[committeeId] || null;
        if (chief && !chiefNames.includes(chief.name)) {
            chiefNames.push(chief.name);
        }
        if (!firstChief && chief) {
            firstChief = chief;
        }
    });
    if (assignedStaffDisplay) {
        assignedStaffDisplay.value = chiefNames.join('; ');
    }
    if (assignedStaffInput) {
        assignedStaffInput.value = firstChief ? firstChief.id : '';
    }
    if (assignedSecretariatDisplay) {
        const secretariatNames = [];
        orderedCommitteeValues.forEach((committeeId) => {
            const value = committeeSecretariats[committeeId] || '';
            if (value && !secretariatNames.includes(value)) {
                secretariatNames.push(value);
            }
        });
        assignedSecretariatDisplay.value = secretariatNames.join('; ');
    }
    if (statusDisplay) {
        if (canFinalizeCommitteeAssignment && receivingSectionNoteInput?.value.trim()) {
            statusDisplay.value = 'Received - Correction Required';
        } else {
            statusDisplay.value = committeeValues.length
                ? (canFinalizeCommitteeAssignment ? 'Pending to the Committee' : 'Received - For SP Secretary Review')
                : '<?= e($record['status']) ?>';
        }
    }
    if (leadCommitteeSelect) {
        const leadWrapper = leadCommitteeSelect.closest('label');
        if (leadWrapper) {
            leadWrapper.style.display = committeeValues.length > 0 ? 'grid' : 'none';
        }
    }
};
const toggleRecordFields = () => {
    const selectedType = getSelectedDocumentType();
    const isLegislative = selectedType === 'Committee Referrals';
    const isAdministrative = administrativeDocumentTypes.includes(selectedType);
    const isCertifiedUrgent = selectedType === 'Certified Urgent';
    if (editableStatusSelect && canCorrectForPlenaryRecord) {
        Array.from(editableStatusSelect.options).forEach((option) => {
            option.disabled = isCertifiedUrgent && !certifiedUrgentStatuses.includes(option.value);
        });
        if (editableStatusSelect.selectedOptions[0]?.disabled) {
            editableStatusSelect.value = 'For Plenary Session';
        }
    }
    if (isCertifiedUrgent) {
        const finalizeReview = reviewActionInputs.find((input) => input.value === 'finalize');
        if (finalizeReview) {
            finalizeReview.checked = true;
        }
    }
    const isMergeReview = !isCertifiedUrgent && isMergeReviewSelected();
    syncPartyField(selectedType);
    document.querySelectorAll('.legislative-field').forEach((field) => field.style.display = isLegislative ? 'grid' : 'none');
    document.querySelectorAll('.admin-field').forEach((field) => field.style.display = isAdministrative ? 'grid' : 'none');
    document.querySelectorAll('.non-committee-field').forEach((field) => field.style.display = !isLegislative ? 'grid' : 'none');
    document.querySelectorAll('.plenary-entry-field').forEach((field) => field.style.display = (isLegislative || isCertifiedUrgent) ? 'grid' : 'none');
    document.querySelectorAll('.client-contact-field, .record-tagging-field').forEach((field) => {
        field.style.display = isCertifiedUrgent ? 'none' : 'grid';
    });
    document.querySelectorAll('.client-contact-field input').forEach((field) => {
        field.required = false;
    });
    if (remarksField) {
        remarksField.required = false;
    }
    const titleInput = document.querySelector('textarea[name="title"]');
    if (titleInput) {
        titleInput.required = true;
    }
    if (recordTypeHelp) {
        recordTypeHelp.textContent = canCorrectForPlenaryRecord
            ? (isCertifiedUrgent
                ? 'Keep Certified Urgent only when the record is truly for plenary.'
                : 'The reclassified record will return to Received for the appropriate workflow.')
            : (isCertifiedUrgent
                ? 'This record will be saved directly as For Plenary Session.'
                : 'For review. The SP Secretary will finalize this type.');
    }
    document.querySelectorAll('.legislative-field input:not([type="hidden"]), .legislative-field select').forEach((field) => {
        field.required = !isMergeReview
            && isLegislative
            && field.name !== 'committee_id'
            && field.name !== 'committee_ids[]'
            && field.type !== 'checkbox';
    });
    document.querySelectorAll('.admin-field input:not([type="checkbox"]), .admin-field textarea').forEach((field) => {
        field.required = !isMergeReview && isAdministrative && field.name !== 'remarks';
    });
    document.querySelectorAll('.non-committee-field input').forEach((field) => {
        field.required = !isMergeReview && (isAdministrative || isCertifiedUrgent);
    });
    document.querySelectorAll('.plenary-entry-field input:not([type="hidden"]), .plenary-entry-field select').forEach((field) => {
        field.required = !isMergeReview && (isLegislative || isCertifiedUrgent);
    });
    if (mergeTargetInput) {
        mergeTargetInput.required = isMergeReview;
    }
    if (mergeTargetWrap) {
        mergeTargetWrap.style.display = isMergeReview ? 'grid' : 'none';
    }
    if (cityCouncilProposalPanel) {
        cityCouncilProposalPanel.style.display = isLegislative && !isMergeReview ? 'grid' : 'none';
    }
    syncForwardingRecipients();
    syncAssignedChief();
};
if (documentTypeSelect) {
    documentTypeSelect.addEventListener('change', () => {
        syncPartyField(documentTypeSelect.value);
        if (controlInput) {
            if (canCorrectForPlenaryRecord) {
                controlInput.value = administrativeDocumentTypes.includes(documentTypeSelect.value)
                    ? (nextControlNumbers[documentTypeSelect.value] || originalControlNumber)
                    : originalControlNumber;
            } else if (documentTypeSelect.value !== originalDocumentType && nextControlNumbers[documentTypeSelect.value]) {
                controlInput.value = nextControlNumbers[documentTypeSelect.value];
            } else if (documentTypeSelect.value === originalDocumentType && originalControlNumber) {
                controlInput.value = originalControlNumber;
            }
        }
        toggleRecordFields();
        syncAssignedChief();
    });
}
if (committeeInputs.length) {
    committeeInputs.forEach((input) => input.addEventListener('change', syncAssignedChief));
    syncAssignedChief();
} else if (committeeSelect) {
    committeeSelect.addEventListener('change', syncAssignedChief);
    syncAssignedChief();
}
if (leadCommitteeSelect) {
    leadCommitteeSelect.addEventListener('change', syncAssignedChief);
}
reviewActionInputs.forEach((input) => input.addEventListener('change', () => {
    toggleRecordFields();
    if (isMergeReviewSelected()) {
        syncExistingRecordCommittees();
    } else {
        setCommitteeSelection(originalCommitteeIds, originalLeadCommitteeId);
    }
}));
if (mergeTargetInput) {
    mergeTargetInput.addEventListener('input', syncExistingRecordCommittees);
    mergeTargetInput.addEventListener('change', syncExistingRecordCommittees);
    syncExistingRecordCommittees();
}
if (receivingSectionNoteInput) {
    receivingSectionNoteInput.addEventListener('input', syncAssignedChief);
}
const syncForwardingRecipients = () => {
    if (!forwardedToInputs.length) {
        return;
    }

    const selectedRecipients = forwardedToInputs
        .filter((input) => input.checked)
        .map((input) => input.value);
    if (forwardedToSummary) {
        forwardedToSummary.textContent = selectedRecipients.length
            ? selectedRecipients.join(', ')
            : 'Select recipients';
        forwardedToSummary.title = selectedRecipients.join(', ');
    }
    forwardedToInputs[0].required = administrativeDocumentTypes.includes(getSelectedDocumentType())
        && !isMergeReviewSelected()
        && selectedRecipients.length === 0;
    if (remarksField) {
        remarksField.classList.toggle('remarks-required-attention', selectedRecipients.length > 0);
    }
};
forwardedToInputs.forEach((input) => input.addEventListener('change', syncForwardingRecipients));
if (forwardedToInputs.length) {
    forwardedToInputs[0].addEventListener('invalid', () => {
        if (forwardedToDropdown) {
            forwardedToDropdown.open = true;
        }
    });
}
if (forwardedToDropdown) {
    document.addEventListener('click', (event) => {
        if (forwardedToDropdown.open && !forwardedToDropdown.contains(event.target)) {
            forwardedToDropdown.open = false;
        }
    });
}
if (recordForm && recordSaveButton) {
    let recordFormSubmitting = false;
    recordForm.addEventListener('submit', (event) => {
        if (recordFormSubmitting) {
            event.preventDefault();
            return;
        }
        recordFormSubmitting = true;
        recordSaveButton.disabled = true;
        recordSaveButton.textContent = 'Saving...';
    });
}
toggleRecordFields();
</script>
<?php require __DIR__ . '/../app/partials/footer.php'; ?>
