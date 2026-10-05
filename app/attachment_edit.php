<?php
declare(strict_types=1);

function attachment_edit_version(array $attachment): string
{
    return hash('sha256', json_encode([
        $attachment['record_id'], $attachment['stored_name'], $attachment['title'],
        $attachment['original_name'], $attachment['file_size'],
    ], JSON_THROW_ON_ERROR));
}

function validate_attachment_replacement(?array $file): ?array
{
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if (in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
        throw new RuntimeException('The replacement exceeds the server upload limit. Maximum 10 MB per file.');
    }
    if ($error !== UPLOAD_ERR_OK) throw new RuntimeException('The replacement could not be uploaded. Please select it again.');
    $path = (string) ($file['tmp_name'] ?? '');
    $size = is_file($path) ? filesize($path) : false;
    if (!$size || $size > ATTACHMENT_MAX_BYTES) throw new RuntimeException('The replacement must contain data and be 10 MB or smaller.');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
    $types = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
    if (!isset($types[$mime])) throw new RuntimeException('Only PDF, JPG, PNG, GIF, and WEBP files are allowed.');
    return ['tmp_name' => $path, 'size' => $size, 'mime' => $mime, 'extension' => $types[$mime],
        'name' => mb_substr(basename(str_replace('\\', '/', (string) ($file['name'] ?? 'attachment.' . $types[$mime]))), 0, 255)];
}
