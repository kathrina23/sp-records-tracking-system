<?php

require_once __DIR__ . '/../app/auth.php';
require_login();
reject_oversized_attachment_request();
ensure_plenary_number_schema();

const ATTACHMENT_ALLOWED_MIME_TYPES = [
    'application/pdf' => 'pdf',
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/gif' => 'gif',
    'image/webp' => 'webp',
];

function attachment_record(int $recordId): ?array
{
    $stmt = db()->prepare('SELECT * FROM records WHERE id = ?');
    $stmt->execute([$recordId]);
    $record = $stmt->fetch();
    return $record ?: null;
}

function attachment_upload_files(?array $upload): array
{
    if (!$upload || !isset($upload['name'])) {
        return [];
    }

    if (!is_array($upload['name'])) {
        return [$upload];
    }

    $files = [];
    foreach ($upload['name'] as $index => $name) {
        $files[] = [
            'name' => $name,
            'type' => $upload['type'][$index] ?? '',
            'tmp_name' => $upload['tmp_name'][$index] ?? '',
            'error' => $upload['error'][$index] ?? UPLOAD_ERR_NO_FILE,
            'size' => $upload['size'][$index] ?? 0,
        ];
    }

    return $files;
}

function attachment_normalized_title(mixed $value): ?string
{
    $title = trim((string) $value);
    if ($title === '') {
        return null;
    }

    return function_exists('mb_substr')
        ? mb_substr($title, 0, 255)
        : substr($title, 0, 255);
}

function attachment_manager_return_url(int $recordId): string
{
    $defaultUrl = url('/record_attachments_manage.php?record_id=' . $recordId);
    $returnUrl = (string) ($_POST['return_url'] ?? $defaultUrl);
    $parts = parse_url($returnUrl);
    if (
        $returnUrl === ''
        || $parts === false
        || isset($parts['scheme'])
        || isset($parts['host'])
        || url($parts['path'] ?? '') !== url('/record_attachments_manage.php')
    ) {
        return $defaultUrl;
    }

    parse_str((string) ($parts['query'] ?? ''), $query);
    if ((int) ($query['record_id'] ?? 0) !== $recordId) {
        return $defaultUrl;
    }

    return $returnUrl;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $recordId = (int) ($_POST['record_id'] ?? 0);
    $record = attachment_record($recordId);
    if (!$record) {
        http_response_code(404);
        exit('Record not found.');
    }

    $attachmentAction = (string) ($_POST['attachment_action'] ?? 'upload');
    $isPlenaryManagerAction = $attachmentAction === 'manage_plenary_attachments';
    if ($isPlenaryManagerAction && !can_manage_plenary_record_attachments($record)) {
        http_response_code(403);
        exit('Only the Laws and Rules Secretariat can manage Attachments on File for plenary records.');
    }
    if (!$isPlenaryManagerAction && !can_upload_record_attachment($record)) {
        http_response_code(403);
        exit('You are not allowed to upload attachments for this record.');
    }

    $returnUrl = $isPlenaryManagerAction ? attachment_manager_return_url($recordId) : '/record_view.php?id=' . $recordId;
    if ($isPlenaryManagerAction) {
        $existingTitleInput = $_POST['existing_titles'] ?? [];
        if (!is_array($existingTitleInput)) {
            http_response_code(400);
            exit('Invalid attachment title data.');
        }

        $existingStmt = db()->prepare('SELECT id, original_name, title, stored_name FROM record_attachments WHERE record_id = ?');
        $existingStmt->execute([$recordId]);
        $existingById = [];
        foreach ($existingStmt->fetchAll() as $existingAttachment) {
            $existingById[(int) $existingAttachment['id']] = $existingAttachment;
        }

        $titleUpdates = [];
        foreach ($existingTitleInput as $attachmentIdValue => $titleValue) {
            $attachmentIdValue = (int) $attachmentIdValue;
            if ($attachmentIdValue <= 0 || !isset($existingById[$attachmentIdValue])) {
                http_response_code(403);
                exit('An attachment does not belong to this record.');
            }

            $newTitle = attachment_normalized_title($titleValue);
            $currentTitle = attachment_normalized_title($existingById[$attachmentIdValue]['title'] ?? null);
            if ($newTitle === null && trim((string) ($existingById[$attachmentIdValue]['stored_name'] ?? '')) === '') {
                flash('A title-only record entry must keep a title.', 'error');
                redirect($returnUrl);
            }
            if ($newTitle !== $currentTitle) {
                $titleUpdates[] = [
                    'id' => $attachmentIdValue,
                    'title' => $newTitle,
                ];
            }
        }

        $newTitles = $_POST['attachment_titles'] ?? [];
        if (!is_array($newTitles)) {
            $newTitles = [];
        }
        $newFiles = attachment_upload_files($_FILES['attachments'] ?? null);
        $rowCount = max(count($newTitles), count($newFiles));
        $preparedFiles = [];
        $preparedTitleEntries = [];
        $totalBytes = 0;
        $finfo = new finfo(FILEINFO_MIME_TYPE);

        for ($index = 0; $index < $rowCount; $index++) {
            $title = attachment_normalized_title($newTitles[$index] ?? null);
            $file = $newFiles[$index] ?? [
                'name' => '',
                'tmp_name' => '',
                'error' => UPLOAD_ERR_NO_FILE,
                'size' => 0,
            ];
            $uploadError = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

            if ($uploadError === UPLOAD_ERR_NO_FILE) {
                if ($title !== null) {
                    $preparedTitleEntries[] = ['title' => $title];
                }
                continue;
            }

            if (in_array($uploadError, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
                flash('The selected file is larger than the allowed upload size. Maximum 10 MB per file; the server may allow less.', 'error');
                redirect($returnUrl);
            }
            if ($uploadError !== UPLOAD_ERR_OK) {
                flash('One of the selected files could not be read. Please select the file again.', 'error');
                redirect($returnUrl);
            }

            $fileSize = (int) ($file['size'] ?? 0);
            if ($fileSize <= 0 || $fileSize > ATTACHMENT_MAX_BYTES) {
                flash('Each attachment must be 10 MB or smaller.', 'error');
                redirect($returnUrl);
            }
            $totalBytes += $fileSize;
            if ($totalBytes > ATTACHMENT_MAX_TOTAL_BYTES) {
                flash('The combined size of the selected files must be 35 MB or smaller.', 'error');
                redirect($returnUrl);
            }

            $mimeType = (string) $finfo->file((string) ($file['tmp_name'] ?? ''));
            if (!array_key_exists($mimeType, ATTACHMENT_ALLOWED_MIME_TYPES)) {
                flash('Only PDF and image files are allowed.', 'error');
                redirect($returnUrl);
            }

            $originalName = trim((string) ($file['name'] ?? 'attachment.' . ATTACHMENT_ALLOWED_MIME_TYPES[$mimeType]));
            $originalName = substr(basename($originalName !== '' ? $originalName : 'attachment.' . ATTACHMENT_ALLOWED_MIME_TYPES[$mimeType]), 0, 255);
            $preparedFiles[] = [
                'tmp_name' => (string) $file['tmp_name'],
                'name' => $originalName,
                'title' => $title ?? $originalName,
                'mime_type' => $mimeType,
                'extension' => ATTACHMENT_ALLOWED_MIME_TYPES[$mimeType],
                'size' => $fileSize,
            ];
        }

        if (count($preparedFiles) + count($preparedTitleEntries) > ATTACHMENT_MAX_FILES) {
            flash('You may add up to 10 attachments or record titles at a time.', 'error');
            redirect($returnUrl);
        }

        $uploadDir = dirname(__DIR__) . '/storage/attachments';
        if ($preparedFiles && !is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
            flash('The attachment storage folder is not available.', 'error');
            redirect($returnUrl);
        }

        $pdo = db();
        $movedPaths = [];
        try {
            $pdo->beginTransaction();

            if ($titleUpdates) {
                $updateTitleStmt = $pdo->prepare('UPDATE record_attachments SET title = ? WHERE id = ? AND record_id = ?');
                foreach ($titleUpdates as $titleUpdate) {
                    $updateTitleStmt->execute([
                        $titleUpdate['title'],
                        $titleUpdate['id'],
                        $recordId,
                    ]);
                }
            }

            if ($preparedTitleEntries) {
                $insertTitleEntryStmt = $pdo->prepare('INSERT INTO record_attachments (record_id, original_name, title, stored_name, mime_type, file_size, uploaded_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
                foreach ($preparedTitleEntries as $preparedTitleEntry) {
                    $insertTitleEntryStmt->execute([
                        $recordId,
                        '',
                        $preparedTitleEntry['title'],
                        '',
                        '',
                        0,
                        current_user()['id'],
                    ]);
                }
            }
            if ($preparedFiles) {
                $insertStmt = $pdo->prepare('INSERT INTO record_attachments (record_id, original_name, title, stored_name, mime_type, file_size, uploaded_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
                foreach ($preparedFiles as $preparedFile) {
                    $storedName = $recordId . '_' . bin2hex(random_bytes(16)) . '.' . $preparedFile['extension'];
                    $destination = $uploadDir . '/' . $storedName;
                    if (!move_uploaded_file($preparedFile['tmp_name'], $destination)) {
                        throw new RuntimeException('Unable to move an uploaded attachment into storage.');
                    }
                    $movedPaths[] = $destination;

                    $insertStmt->execute([
                        $recordId,
                        $preparedFile['name'],
                        $preparedFile['title'],
                        $storedName,
                        $preparedFile['mime_type'],
                        $preparedFile['size'],
                        current_user()['id'],
                    ]);
                }
            }

            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            foreach ($movedPaths as $movedPath) {
                if (is_file($movedPath)) {
                    unlink($movedPath);
                }
            }
            error_log('Unable to save Attachments on File: ' . $error->getMessage());
            flash('Attachments on File could not be saved. Please try again or ask the administrator to apply the attachment-title migration.', 'error');
            redirect($returnUrl);
        }

        if ($titleUpdates) {
            audit_log(
                'record_attachment_titles_update',
                'Updated ' . count($titleUpdates) . ' attachment title(s) for record #' . $recordId . '.',
                'record',
                $recordId
            );
        }
        foreach ($preparedTitleEntries as $preparedTitleEntry) {
            audit_log(
                'record_attachment_title_entry_add',
                'Added title-only record entry "' . $preparedTitleEntry['title'] . '" for record #' . $recordId . '.',
                'record',
                $recordId
            );
        }
        foreach ($preparedFiles as $preparedFile) {
            audit_log(
                'record_attachment_upload',
                'Uploaded attachment ' . $preparedFile['name'] . ' as "' . $preparedFile['title'] . '" for record #' . $recordId . '.',
                'record',
                $recordId
            );
        }

        $savedParts = [];
        if ($titleUpdates) {
            $savedParts[] = count($titleUpdates) . (count($titleUpdates) === 1 ? ' title updated' : ' titles updated');
        }
        if ($preparedFiles) {
        if ($preparedTitleEntries) {
            $savedParts[] = count($preparedTitleEntries) . (count($preparedTitleEntries) === 1 ? ' record title added' : ' record titles added');
        }
            $savedParts[] = count($preparedFiles) . (count($preparedFiles) === 1 ? ' attachment added' : ' attachments added');
        }
        flash($savedParts ? ucfirst(implode(' and ', $savedParts)) . '.' : 'No attachment changes were needed.');
        redirect($returnUrl);
    }

    $files = attachment_upload_files($_FILES['attachments'] ?? ($_FILES['attachment'] ?? null));
    $files = array_values(array_filter($files, fn ($file) => ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE));
    if (!$files) {
        flash('Please choose one or more PDF or image files to attach.', 'error');
        redirect('/record_view.php?id=' . $recordId);
    }

    if (count($files) > ATTACHMENT_MAX_FILES) {
        flash('You may upload up to 10 files at a time.', 'error');
        redirect('/record_view.php?id=' . $recordId);
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $preparedFiles = [];
    $totalBytes = 0;
    foreach ($files as $file) {
        if (in_array((int) ($file['error'] ?? 0), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            flash('The selected file is larger than the allowed upload size. Maximum 10 MB per file; the server may allow less.', 'error');
            redirect('/record_view.php?id=' . $recordId);
        }
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            flash('One of the selected files could not be read. Please select the files again.', 'error');
            redirect('/record_view.php?id=' . $recordId);
        }

        $fileSize = (int) ($file['size'] ?? 0);
        if ($fileSize <= 0 || $fileSize > ATTACHMENT_MAX_BYTES) {
            flash('Each attachment must be 10 MB or smaller.', 'error');
            redirect('/record_view.php?id=' . $recordId);
        }
        $totalBytes += $fileSize;
        if ($totalBytes > ATTACHMENT_MAX_TOTAL_BYTES) {
            flash('The combined size of the selected files must be 35 MB or smaller.', 'error');
            redirect('/record_view.php?id=' . $recordId);
        }

        $mimeType = (string) $finfo->file((string) $file['tmp_name']);
        if (!array_key_exists($mimeType, ATTACHMENT_ALLOWED_MIME_TYPES)) {
            flash('Only PDF and image files are allowed.', 'error');
            redirect('/record_view.php?id=' . $recordId);
        }

        $originalName = trim((string) ($file['name'] ?? 'attachment.' . ATTACHMENT_ALLOWED_MIME_TYPES[$mimeType]));
        $preparedFiles[] = [
            'tmp_name' => (string) $file['tmp_name'],
            'name' => substr(basename($originalName !== '' ? $originalName : 'attachment.' . ATTACHMENT_ALLOWED_MIME_TYPES[$mimeType]), 0, 255),
            'mime_type' => $mimeType,
            'extension' => ATTACHMENT_ALLOWED_MIME_TYPES[$mimeType],
            'size' => $fileSize,
        ];
    }

    $uploadDir = dirname(__DIR__) . '/storage/attachments';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0775, true);
    }

    $stmt = db()->prepare('INSERT INTO record_attachments (record_id, original_name, stored_name, mime_type, file_size, uploaded_by) VALUES (?, ?, ?, ?, ?, ?)');
    $uploadedCount = 0;
    foreach ($preparedFiles as $file) {
        $storedName = $recordId . '_' . bin2hex(random_bytes(16)) . '.' . $file['extension'];
        if (!move_uploaded_file($file['tmp_name'], $uploadDir . '/' . $storedName)) {
            continue;
        }

        $stmt->execute([
            $recordId,
            $file['name'],
            $storedName,
            $file['mime_type'],
            $file['size'],
            current_user()['id'],
        ]);
        $uploadedCount++;
        audit_log('record_attachment_upload', 'Uploaded attachment ' . $file['name'] . ' for record #' . $recordId . '.', 'record', $recordId);
    }

    if ($uploadedCount !== count($preparedFiles)) {
        flash($uploadedCount . ' of ' . count($preparedFiles) . ' attachments were uploaded. Please try the remaining files again.', 'error');
    } else {
        flash($uploadedCount . ($uploadedCount === 1 ? ' attachment uploaded.' : ' attachments uploaded.'));
    }
    redirect('/record_view.php?id=' . $recordId);
}

$attachmentId = (int) ($_GET['id'] ?? 0);
$stmt = db()->prepare("SELECT a.*, r.document_type, r.committee_id, r.receiving_clerk_id, r.created_by
    FROM record_attachments a
    INNER JOIN records r ON r.id = a.record_id
    WHERE a.id = ?");
$stmt->execute([$attachmentId]);
$attachment = $stmt->fetch();
if (!$attachment) {
    http_response_code(404);
    exit('Attachment not found.');
}

$attachmentRecord = $attachment;
$attachmentRecord['id'] = (int) $attachment['record_id'];
if (!can_view_record_attachments($attachmentRecord)) {
    http_response_code(403);
    exit('You are not allowed to view this attachment.');
}

$path = dirname(__DIR__) . '/storage/attachments/' . basename((string) $attachment['stored_name']);
if (!is_file($path)) {
    http_response_code(404);
    exit('Attachment file not found.');
}

audit_log('record_attachment_view', 'Opened attachment ' . $attachment['original_name'] . ' for record #' . $attachment['record_id'] . '.', 'record', (int) $attachment['record_id']);

header('Content-Type: ' . $attachment['mime_type']);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="' . str_replace('"', '', (string) $attachment['original_name']) . '"');
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;
