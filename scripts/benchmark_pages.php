<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit; }
ob_start();
require_once __DIR__ . '/../app/auth.php';
$user = db()->query("SELECT id, name, nickname, email, role FROM users WHERE role='admin' AND is_active=1 ORDER BY id LIMIT 1")->fetch();
if (!$user) { throw new RuntimeException('An active administrator is required for local page timing.'); }
$_SESSION['user'] = $user;
$cookie = session_name() . '=' . session_id();
session_write_close();
try {
    foreach (['/', '/legislation.php?search=health', '/dashboard.php', '/records.php', '/messengerial.php'] as $path) {
        $times = [];
        for ($sample = 0; $sample < 3; $sample++) {
            $curl = curl_init('http://localhost:8000' . $path);
            curl_setopt_array($curl, [CURLOPT_COOKIE => $cookie, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30]);
            $body = curl_exec($curl);
            $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $times[] = round(curl_getinfo($curl, CURLINFO_TOTAL_TIME) * 1000, 1);
            if ($body === false || $status !== 200) { throw new RuntimeException("Page timing failed: $path HTTP $status " . curl_error($curl)); }
            curl_close($curl);
        }
        sort($times);
        echo $path . ': median ' . $times[1] . " ms\n";
    }
} finally {
    session_start();
    $_SESSION = [];
    session_destroy();
    ob_end_flush();
}
