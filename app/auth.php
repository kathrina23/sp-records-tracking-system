<?php

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    $sessionPath = dirname(__DIR__) . '/storage/sessions';
    if (!is_dir($sessionPath)) {
        mkdir($sessionPath, 0775, true);
    }
    session_save_path($sessionPath);
    session_start();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function require_login(): void
{
    if (!current_user()) {
        redirect('/login.php');
    }
    if (is_server_maintenance_staff()
        && !maintenance_request_is_allowed($_SERVER['REQUEST_METHOD'] ?? 'GET', $_SERVER['SCRIPT_FILENAME'] ?? '')) {
        http_response_code(403);
        exit('Server Maintenance Staff can only view information and download database backups.');
    }
}

function require_admin(): void
{
    require_user_management();
}

function require_user_management(): void
{
    require_login();
    if (!can_manage_users()) {
        http_response_code(403);
        exit('This page is for authorized management users only.');
    }
}

function require_management_access(): void
{
    require_login();
    if (!can_access_management_pages()) {
        http_response_code(403);
        exit('This page is for authorized management users only.');
    }
}

function attempt_login(string $email, string $password): bool
{
    $stmt = db()->prepare('SELECT * FROM users WHERE email = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        return false;
    }

    session_regenerate_id(true);
    $_SESSION['user'] = [
        'id' => (int) $user['id'],
        'name' => $user['name'],
        'nickname' => $user['nickname'] ?? null,
        'email' => $user['email'],
        'role' => $user['role'],
    ];
    audit_log('login', 'User signed in.', 'user', (int) $user['id']);

    return true;
}
