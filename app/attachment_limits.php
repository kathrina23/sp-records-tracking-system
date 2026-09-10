<?php

declare(strict_types=1);

const ATTACHMENT_MAX_BYTES = 10485760;
const ATTACHMENT_MAX_FILES = 10;
const ATTACHMENT_MAX_TOTAL_BYTES = 36700160;

function reject_oversized_attachment_request(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        return;
    }
    $setting = trim((string) ini_get('post_max_size'));
    $bytes = (float) $setting;
    $unit = strtolower(substr($setting, -1));
    $bytes *= match ($unit) { 'g' => 1073741824, 'm' => 1048576, 'k' => 1024, default => 1 };
    if ($bytes > 0 && (float) ($_SERVER['CONTENT_LENGTH'] ?? 0) > $bytes) {
        http_response_code(413);
        exit('The selected upload exceeds the server request size limit. Go back and select smaller files. Maximum 10 MB per file and 35 MB combined; the server may allow less.');
    }
}
