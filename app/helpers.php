<?php

declare(strict_types=1);

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function display_record_title(?string $value): string
{
    $title = (string) $value;
    return function_exists('mb_strtoupper')
        ? mb_strtoupper($title, 'UTF-8')
        : strtoupper($title);
}

function record_title_for_current_user(array $record): string
{
    static $canViewLawsAndRulesTitle = null;
    if ($canViewLawsAndRulesTitle === null) {
        $role = (string) ($_SESSION['user']['role'] ?? '');
        $canViewLawsAndRulesTitle = in_array($role, ['admin', 'city_secretary'], true)
            || ($role === 'secretariat' && is_laws_and_rules_secretariat());
    }

    $lawsAndRulesTitle = trim((string) ($record['plenary_print_title'] ?? ''));
    if ($canViewLawsAndRulesTitle && $lawsAndRulesTitle !== '') {
        return $lawsAndRulesTitle;
    }

    return (string) ($record['title'] ?? '');
}

function url(string $path): string
{
    return BASE_PATH . $path;
}

function redirect(string $path): never
{
    header('Location: ' . url($path));
    exit;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function verify_csrf(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(419);
        exit('Invalid security token. Please go back and try again.');
    }
}

function flash(?string $message = null, string $type = 'success'): ?array
{
    if ($message !== null) {
        $_SESSION['flash'] = ['message' => $message, 'type' => $type];
        return null;
    }

    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $flash;
}

function is_admin(): bool
{
    return in_array($_SESSION['user']['role'] ?? '', ['admin', 'city_secretary', 'division_chief'], true);
}

function role_label(string $role): string
{
    $labels = [
        'admin' => 'Administrator',
        'city_secretary' => 'City Secretary',
        'division_chief' => 'Division Chief',
        'receiving_clerk' => 'Admin Receiving Section',
        'secretariat' => 'Secretariat',
        'division_staff' => 'Division Staff',
        'administrative_support' => 'LMIS & Records Staff',
        'others' => 'Others',
        'server_maintenance_staff' => 'Server Maintenance Staff',
        'records_officer' => 'Records Officer',
        'staff' => 'Staff',
    ];

    return $labels[$role] ?? ucwords(str_replace('_', ' ', $role));
}

function display_date(?string $value): string
{
    if (!$value) {
        return '';
    }

    $timestamp = strtotime($value);
    return $timestamp ? date('m/d/Y', $timestamp) : $value;
}

function display_datetime(?string $value): string
{
    if (!$value) {
        return '';
    }

    $timestamp = strtotime($value);
    return $timestamp ? date('m/d/Y h:i A', $timestamp) : $value;
}

function is_today_date(?string $value): bool
{
    if (!$value) {
        return false;
    }

    $timestamp = strtotime($value);
    return $timestamp ? date('Y-m-d', $timestamp) === date('Y-m-d') : false;
}

function update_age_marker(?string $value): ?array
{
    if (!$value) {
        return null;
    }

    $timestamp = strtotime($value);
    if (!$timestamp) {
        return null;
    }

    $today = strtotime(date('Y-m-d'));
    $updateDate = strtotime(date('Y-m-d', $timestamp));
    $daysOld = (int) floor(($today - $updateDate) / 86400);
    if ($daysOld < 0) {
        return null;
    }

    if ($daysOld === 0) {
        return ['label' => 'Today', 'class' => 'age-today'];
    }

    if ($daysOld === 1) {
        return ['label' => '2 Days', 'class' => 'age-two-days'];
    }

    return ['label' => ($daysOld + 1) . ' Days', 'class' => 'age-three-days'];
}

function can_manage_users(): bool
{
    // User creation and division assignment for accounts is limited to these two roles.
    return in_array($_SESSION['user']['role'] ?? '', ['admin', 'city_secretary'], true);
}

function can_manage_assignments(): bool
{
    return in_array($_SESSION['user']['role'] ?? '', ['admin', 'city_secretary', 'division_chief'], true);
}

function can_access_management_pages(): bool
{
    return in_array($_SESSION['user']['role'] ?? '', ['admin', 'city_secretary', 'secretariat'], true);
}

function can_manage_division_chief_assignments(): bool
{
    return in_array($_SESSION['user']['role'] ?? '', ['admin', 'city_secretary'], true);
}

function division_chief_committee_ids(?int $userId = null): array
{
    $userId = $userId ?? (int) ($_SESSION['user']['id'] ?? 0);
    try {
        $stmt = db()->prepare('SELECT committee_id FROM committee_division_chiefs WHERE user_id = ?');
        $stmt->execute([$userId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $error) {
        return [];
    }
}

function user_division_name(?int $userId = null): string
{
    $userId = $userId ?? (int) ($_SESSION['user']['id'] ?? 0);
    if ($userId <= 0) {
        return '';
    }

    try {
        $stmt = db()->prepare('SELECT division_name FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        return trim((string) ($stmt->fetchColumn() ?: ''));
    } catch (Throwable $error) {
        return '';
    }
}

function division_chief_secretariat_ids(?int $divisionChiefId = null): array
{
    $divisionChiefId = $divisionChiefId ?? (int) ($_SESSION['user']['id'] ?? 0);
    try {
        $stmt = db()->prepare('SELECT secretariat_user_id FROM division_chief_secretariats WHERE division_chief_user_id = ?');
        $stmt->execute([$divisionChiefId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $error) {
        return [];
    }
}

function division_chief_for_committee(int $committeeId): ?array
{
    try {
        $stmt = db()->prepare("SELECT u.id, COALESCE(NULLIF(u.division_name, ''), u.name) name FROM committee_division_chiefs a INNER JOIN users u ON u.id = a.user_id WHERE a.committee_id = ? AND u.is_active = 1 LIMIT 1");
        $stmt->execute([$committeeId]);
        $chief = $stmt->fetch();
        return $chief ?: null;
    } catch (Throwable $error) {
        return null;
    }
}

function secretariat_names_for_committee(?int $committeeId): string
{
    if (!$committeeId) {
        return '';
    }

    try {
        $stmt = db()->prepare("SELECT u.name FROM committee_secretariats a INNER JOIN users u ON u.id = a.user_id WHERE a.committee_id = ? AND u.is_active = 1 ORDER BY u.name");
        $stmt->execute([$committeeId]);
        return implode(', ', $stmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $error) {
        return '';
    }
}

function record_assignment_names(int $recordId, ?int $fallbackCommitteeId = null): array
{
    $divisionNames = [];
    $secretariatNames = [];

    foreach (record_committee_rows($recordId, $fallbackCommitteeId) as $committeeRow) {
        $committeeId = (int) $committeeRow['committee_id'];
        $chief = division_chief_for_committee($committeeId);
        if ($chief && !in_array($chief['name'], $divisionNames, true)) {
            $divisionNames[] = $chief['name'];
        }

        try {
            $stmt = db()->prepare("SELECT u.name
                FROM committee_secretariats a
                INNER JOIN users u ON u.id = a.user_id
                WHERE a.committee_id = ? AND u.is_active = 1
                ORDER BY u.name");
            $stmt->execute([$committeeId]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $name) {
                if (!in_array($name, $secretariatNames, true)) {
                    $secretariatNames[] = $name;
                }
            }
        } catch (Throwable $error) {
            // Keep the remaining assignment details available.
        }
    }

    return [
        'divisions' => $divisionNames,
        'secretariats' => $secretariatNames,
    ];
}

function record_committee_rows(int $recordId, ?int $fallbackCommitteeId = null): array
{
    try {
        $stmt = db()->prepare("SELECT rc.committee_id, rc.sequence_no, c.name committee_name
            FROM record_committees rc
            INNER JOIN committees c ON c.id = rc.committee_id
            WHERE rc.record_id = ?
            ORDER BY rc.sequence_no, c.name");
        $stmt->execute([$recordId]);
        $rows = $stmt->fetchAll();
        if ($rows) {
            return $rows;
        }
    } catch (Throwable $error) {
        // Older installs may not have record_committees yet.
    }

    if ($fallbackCommitteeId) {
        try {
            $stmt = db()->prepare('SELECT id committee_id, 1 sequence_no, name committee_name FROM committees WHERE id = ?');
            $stmt->execute([$fallbackCommitteeId]);
            $row = $stmt->fetch();
            return $row ? [$row] : [];
        } catch (Throwable $error) {
            return [];
        }
    }

    return [];
}

function save_record_committees(int $recordId, array $committeeIds): void
{
    $committeeIds = array_values(array_unique(array_filter(array_map('intval', $committeeIds))));

    try {
        $pdo = db();
        $delete = $pdo->prepare('DELETE FROM record_committees WHERE record_id = ?');
        $delete->execute([$recordId]);
        if (!$committeeIds) {
            return;
        }

        $insert = $pdo->prepare('INSERT INTO record_committees (record_id, committee_id, sequence_no) VALUES (?, ?, ?)');
        foreach ($committeeIds as $index => $committeeId) {
            $insert->execute([$recordId, $committeeId, $index + 1]);
        }
    } catch (Throwable $error) {
        // Do not block existing single-committee workflow on older databases.
    }
}

function lead_committee_name_from_rows(array $committeeRows): string
{
    if (count($committeeRows) < 2) {
        return '';
    }

    return (string) ($committeeRows[0]['committee_name'] ?? '');
}

function current_secretariat_is_lead_committee(array $record): bool
{
    if (($_SESSION['user']['role'] ?? '') !== 'secretariat') {
        return false;
    }

    $committeeRows = record_committee_rows((int) ($record['id'] ?? 0), !empty($record['committee_id']) ? (int) $record['committee_id'] : null);
    if (count($committeeRows) < 2) {
        return false;
    }

    $leadCommitteeId = (int) ($committeeRows[0]['committee_id'] ?? 0);
    return $leadCommitteeId > 0 && in_array($leadCommitteeId, secretariat_committee_ids(), true);
}

function referral_group_label(int $total): string
{
    return [
        2 => 'Joint',
        3 => 'Tripartite',
        4 => 'Quadripartite',
        5 => 'Quintpartite',
        6 => 'Sextpartite',
        7 => 'Septpartite',
        8 => 'Octopartite',
        9 => 'Nonpartite',
        10 => 'Decapartite',
    ][$total] ?? 'Multi-Committee';
}

function can_access_record_committees(array $record): bool
{
    $rows = record_committee_rows((int) ($record['id'] ?? 0), !empty($record['committee_id']) ? (int) $record['committee_id'] : null);
    foreach ($rows as $row) {
        if (can_access_committee((int) $row['committee_id'])) {
            return true;
        }
    }

    return false;
}

function can_print_record_update(array $record, array $movement): bool
{
    if (($record['document_type'] ?? '') !== 'Committee Referrals' || empty($record['committee_id'])) {
        return false;
    }

    if (!in_array(($movement['updated_by_role'] ?? ''), ['secretariat', 'division_chief'], true)) {
        return false;
    }

    if (!empty($movement['is_division_receipt'])) {
        return false;
    }

    if (($movement['notes'] ?? '') === 'Committee Referral print timestamp generated.') {
        return false;
    }

    $role = $_SESSION['user']['role'] ?? '';
    if (!in_array($role, ['admin', 'division_chief', 'secretariat'], true)) {
        return false;
    }

    return can_access_record_committees($record);
}

function can_edit_secretariat_update(array $record, array $movement): bool
{
    $role = $_SESSION['user']['role'] ?? '';
    if (!in_array($role, ['admin', 'division_chief'], true)) {
        return false;
    }

    if (!can_print_record_update($record, $movement)) {
        return false;
    }

    if (($movement['updated_by_role'] ?? '') !== 'secretariat') {
        return false;
    }

    $movementId = (int) ($movement['id'] ?? $movement['movement_id'] ?? 0);
    if ($movementId <= 0) {
        return false;
    }

    try {
        $latestStmt = db()->prepare("SELECT CASE WHEN m.id = (
                SELECT MAX(m_latest.id)
                FROM record_movements m_latest
                INNER JOIN users u_latest ON u_latest.id = m_latest.updated_by
                WHERE m_latest.record_id = m.record_id
                AND u_latest.role = 'secretariat'
                AND COALESCE(u_latest.division_name, '') = COALESCE(u.division_name, '')
            ) THEN 1 ELSE 0 END
            FROM record_movements m
            INNER JOIN users u ON u.id = m.updated_by
            WHERE m.id = ?
            LIMIT 1");
        $latestStmt->execute([$movementId]);
        if ((int) $latestStmt->fetchColumn() !== 1) {
            return false;
        }
    } catch (Throwable $error) {
        return false;
    }

    if ($role === 'admin') {
        return true;
    }

    $staffId = (int) ($movement['updated_by'] ?? 0);
    if ($staffId <= 0) {
        return false;
    }

    try {
        $stmt = db()->prepare('SELECT division_name, role FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$staffId]);
        $staff = $stmt->fetch();

        $chiefStmt = db()->prepare('SELECT division_name FROM users WHERE id = ? LIMIT 1');
        $chiefStmt->execute([(int) ($_SESSION['user']['id'] ?? 0)]);
        $chiefDivision = (string) ($chiefStmt->fetchColumn() ?: '');

        return $staff
            && in_array($staff['role'], ['secretariat', 'division_staff', 'receiving_clerk'], true)
            && $chiefDivision !== ''
            && (string) ($staff['division_name'] ?? '') === $chiefDivision;
    } catch (Throwable $error) {
        return false;
    }
}

function secretariat_committee_ids(?int $userId = null): array
{
    $userId = $userId ?? (int) ($_SESSION['user']['id'] ?? 0);
    try {
        $stmt = db()->prepare('SELECT DISTINCT committee_id FROM committee_secretariats WHERE user_id = ?');
        $stmt->execute([$userId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $error) {
        return [];
    }
}

function division_staff_committee_ids(?int $userId = null): array
{
    $userId = $userId ?? (int) ($_SESSION['user']['id'] ?? 0);
    try {
        $stmt = db()->prepare("SELECT DISTINCT cdc.committee_id
            FROM users staff
            INNER JOIN users chief
                ON chief.role = 'division_chief'
                AND chief.is_active = 1
                AND chief.division_name = staff.division_name
            INNER JOIN committee_division_chiefs cdc ON cdc.user_id = chief.id
            WHERE staff.id = ?
            AND staff.role = 'division_staff'
            AND staff.is_active = 1
            AND COALESCE(NULLIF(staff.division_name, ''), '') <> ''");
        $stmt->execute([$userId]);
        $committeeIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

        if ($committeeIds) {
            return $committeeIds;
        }

        // Preserve older assignments while accounts are being moved to division-based access.
        $legacyStmt = db()->prepare("SELECT DISTINCT cdc.committee_id
            FROM division_chief_secretariats dcs
            INNER JOIN committee_division_chiefs cdc ON cdc.user_id = dcs.division_chief_user_id
            WHERE dcs.secretariat_user_id = ?");
        $legacyStmt->execute([$userId]);
        return array_map('intval', $legacyStmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $error) {
        return [];
    }
}

function can_attest_division_receipt(array $record): bool
{
    return ($_SESSION['user']['role'] ?? '') === 'division_staff'
        && ($record['document_type'] ?? '') === 'Committee Referrals'
        && ($record['status'] ?? '') !== 'Received'
        && !empty($record['committee_id'])
        && user_division_name() !== ''
        && can_access_record_committees($record);
}

function division_receipts_for_records(array $recordIds, ?string $divisionName = null): array
{
    $recordIds = array_values(array_unique(array_filter(array_map('intval', $recordIds))));
    $divisionName = trim($divisionName ?? user_division_name());
    if (!$recordIds || $divisionName === '') {
        return [];
    }

    try {
        $placeholders = implode(',', array_fill(0, count($recordIds), '?'));
        $stmt = db()->prepare("SELECT rr.record_id, rr.received_at,
                COALESCE(NULLIF(u.nickname, ''), u.name) received_by_name
            FROM record_division_receipts rr
            LEFT JOIN users u ON u.id = rr.received_by
            WHERE rr.division_name = ?
            AND rr.record_id IN ($placeholders)");
        $stmt->execute(array_merge([$divisionName], $recordIds));

        $receipts = [];
        foreach ($stmt->fetchAll() as $receipt) {
            $receipts[(int) $receipt['record_id']] = $receipt;
        }
        return $receipts;
    } catch (Throwable $error) {
        return [];
    }
}

function committee_ids_for_staff_user(int $userId, string $role): array
{
    if ($role === 'secretariat') {
        return secretariat_committee_ids($userId);
    }

    if ($role === 'division_staff') {
        return division_staff_committee_ids($userId);
    }

    return [];
}

function scoped_committee_ids_for_current_user(): array
{
    $role = $_SESSION['user']['role'] ?? '';
    if ($role === 'division_chief') {
        return division_chief_committee_ids();
    }
    if ($role === 'secretariat') {
        return secretariat_committee_ids();
    }
    if ($role === 'division_staff') {
        return division_staff_committee_ids();
    }

    return [];
}

function can_access_committee(int $committeeId): bool
{
    $role = $_SESSION['user']['role'] ?? '';
    if (in_array($role, ['admin', 'city_secretary'], true)) {
        return true;
    }

    if ($role === 'division_chief') {
        return in_array($committeeId, division_chief_committee_ids(), true);
    }

    if ($role === 'secretariat') {
        return in_array($committeeId, secretariat_committee_ids(), true);
    }

    if ($role === 'division_staff') {
        return in_array($committeeId, division_staff_committee_ids(), true);
    }

    return false;
}

function is_laws_and_rules_secretariat(?int $userId = null): bool
{
    $userId = $userId ?? (int) ($_SESSION['user']['id'] ?? 0);
    if ($userId <= 0) {
        return false;
    }

    if ($userId === (int) ($_SESSION['user']['id'] ?? 0)
        && ($_SESSION['user']['role'] ?? '') !== 'secretariat') {
        return false;
    }

    try {
        $stmt = db()->prepare("SELECT COUNT(*)
            FROM users u
            INNER JOIN committee_secretariats cs ON cs.user_id = u.id
            INNER JOIN committees c ON c.id = cs.committee_id
            WHERE u.id = ?
            AND u.role = 'secretariat'
            AND u.is_active = 1
            AND LOWER(TRIM(c.name)) = 'laws and rules'");
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn() > 0;
    } catch (Throwable $error) {
        return false;
    }
}

function can_manage_plenary_scheduling(): bool
{
    return in_array($_SESSION['user']['role'] ?? '', ['admin', 'city_secretary'], true)
        || is_laws_and_rules_secretariat();
}

function can_manage_for_plenary_record(array $record): bool
{
    return can_manage_plenary_scheduling()
        && in_array($record['document_type'] ?? '', ['Committee Referrals', 'Certified Urgent'], true)
        && in_array($record['status'] ?? '', ['For Plenary Session', 'Scheduled for Plenary'], true);
}

function can_assign_plenary_numbers(array $record): bool
{
    return can_manage_for_plenary_record($record);
}

function normalize_proposed_number_input(string $number, ?string $year = null): string
{
    $number = trim($number);
    if ($number === '') {
        return '';
    }

    $year = $year ?: date('Y');
    if (!preg_match('/^\d{4}\s*-\s*/', $number)) {
        $number = $year . '-' . ltrim($number, " \t\n\r\0\x0B-");
    }

    return preg_replace('/^(\d{4})\s*-\s*/', '$1-', $number) ?: $number;
}

function is_server_maintenance_staff(): bool
{
    return ($_SESSION['user']['role'] ?? '') === 'server_maintenance_staff';
}

function maintenance_request_is_allowed(string $method, string $script): bool
{
    return in_array($method, ['GET', 'HEAD'], true)
        || ($method === 'POST' && basename($script) === 'backup.php');
}

function can_create_records(): bool
{
    return in_array($_SESSION['user']['role'] ?? '', ['admin', 'city_secretary', 'receiving_clerk'], true);
}

function others_user_document_types(): array
{
    return [
        'Committee Referrals',
        'Transmittals, Letters and Endorsements',
        'Memorandum, Executive Order, Directive Order and Etc.',
    ];
}

function can_view_record(array $record): bool
{
    $role = $_SESSION['user']['role'] ?? '';
    if (in_array($role, ['others', 'server_maintenance_staff'], true)) {
        return in_array($record['document_type'] ?? '', others_user_document_types(), true);
    }

    if ($role === 'secretariat'
        && is_laws_and_rules_secretariat()
        && in_array($record['document_type'] ?? '', ['Committee Referrals', 'Certified Urgent'], true)
        && in_array($record['status'] ?? '', ['For Plenary Session', 'Scheduled for Plenary'], true)) {
        return true;
    }

    if (in_array($role, ['division_chief', 'secretariat', 'division_staff'], true)) {
        if (($record['document_type'] ?? '') === 'Certified Urgent') {
            return true;
        }

        return ($record['document_type'] ?? '') === 'Committee Referrals'
            && ($record['status'] ?? '') !== 'Received'
            && !empty($record['committee_id'])
            && can_access_record_committees($record);
    }

    return true;
}

function personal_note_record_suggestions(string $query, int $limit = 10): array
{
    $query = trim($query);
    $queryLength = function_exists('mb_strlen') ? mb_strlen($query, 'UTF-8') : strlen($query);
    if ($queryLength < 2) {
        return [];
    }

    if ($queryLength > 120) {
        $query = function_exists('mb_substr')
            ? mb_substr($query, 0, 120, 'UTF-8')
            : substr($query, 0, 120);
    }

    $limit = max(1, min(20, $limit));
    $role = (string) ($_SESSION['user']['role'] ?? '');
    $where = [];
    $params = [];

    if (in_array($role, ['division_chief', 'secretariat', 'division_staff'], true)) {
        $recordScopes = ["r.document_type = 'Certified Urgent'"];
        $committeeIds = scoped_committee_ids_for_current_user();
        if ($committeeIds) {
            $placeholders = implode(',', array_fill(0, count($committeeIds), '?'));
            $recordScopes[] = "(r.document_type = 'Committee Referrals'
                AND r.status <> 'Received'
                AND r.committee_id IS NOT NULL
                AND (r.committee_id IN ($placeholders)
                    OR EXISTS (
                        SELECT 1
                        FROM record_committees rc_personal_note
                        WHERE rc_personal_note.record_id = r.id
                        AND rc_personal_note.committee_id IN ($placeholders)
                    )))";
            $params = array_merge($params, $committeeIds, $committeeIds);
        }
        if ($role === 'secretariat' && is_laws_and_rules_secretariat()) {
            $recordScopes[] = "(r.document_type IN ('Committee Referrals', 'Certified Urgent')
                AND r.status IN ('For Plenary Session', 'Scheduled for Plenary'))";
        }
        $where[] = '(' . implode(' OR ', $recordScopes) . ')';
    } elseif (in_array($role, ['others', 'server_maintenance_staff'], true)) {
        $documentTypes = others_user_document_types();
        $placeholders = implode(',', array_fill(0, count($documentTypes), '?'));
        $where[] = "r.document_type IN ($placeholders)";
        $params = array_merge($params, $documentTypes);
    }

    $like = '%' . $query . '%';
    $prefix = $query . '%';
    $where[] = "(r.control_number LIKE ?
        OR r.title LIKE ?
        OR COALESCE(r.origin, '') LIKE ?
        OR COALESCE(r.client_name, '') LIKE ?)";
    $params = array_merge($params, [$like, $like, $like, $like, $prefix, $prefix]);

    $sql = "SELECT r.id, r.control_number, r.title, r.document_type, r.status,
            COALESCE(NULLIF(r.client_name, ''), r.origin) party_name,
            c.name committee_name
        FROM records r
        LEFT JOIN committees c ON c.id = r.committee_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY CASE
                WHEN r.control_number LIKE ? THEN 0
                WHEN r.title LIKE ? THEN 1
                ELSE 2
            END,
            r.updated_at DESC,
            r.id DESC
        LIMIT $limit";
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function pending_receiving_staff_comment_sql(string $recordAlias = 'records'): string
{
    return "EXISTS (
        SELECT 1
        FROM record_movements m_receiving_comment
        INNER JOIN users u_receiving_comment ON u_receiving_comment.id = m_receiving_comment.updated_by
        WHERE m_receiving_comment.record_id = $recordAlias.id
        AND u_receiving_comment.role IN ('admin', 'city_secretary')
        AND (
            m_receiving_comment.notes LIKE '%Note to Receiving Staff:%'
            OR m_receiving_comment.notes LIKE '%Note to Admin Receiving Section:%'
        )
        AND NOT EXISTS (
            SELECT 1
            FROM record_movements m_receiving_response
            INNER JOIN users u_receiving_response ON u_receiving_response.id = m_receiving_response.updated_by
            WHERE m_receiving_response.record_id = m_receiving_comment.record_id
            AND m_receiving_response.id > m_receiving_comment.id
            AND u_receiving_response.role = 'receiving_clerk'
            AND m_receiving_response.notes LIKE 'Receiving Staff updated the record in response to the City Secretary comment.%'
        )
    )";
}

function record_has_pending_receiving_staff_comment(array $record): bool
{
    if (array_key_exists('has_receiving_comment', $record)) {
        return (bool) $record['has_receiving_comment'];
    }

    $recordId = (int) ($record['id'] ?? 0);
    if ($recordId <= 0) {
        return false;
    }

    $stmt = db()->prepare('SELECT CASE WHEN ' . pending_receiving_staff_comment_sql('r') . ' THEN 1 ELSE 0 END FROM records r WHERE r.id = ?');
    $stmt->execute([$recordId]);
    return (bool) $stmt->fetchColumn();
}

function pending_receiving_staff_comment(int $recordId): ?array
{
    if ($recordId <= 0) {
        return null;
    }

    $stmt = db()->prepare("SELECT m.notes, m.created_at,
            COALESCE(NULLIF(u.nickname, ''), u.name) author_name
        FROM record_movements m
        INNER JOIN users u ON u.id = m.updated_by
        WHERE m.record_id = ?
        AND u.role IN ('admin', 'city_secretary')
        AND (
            m.notes LIKE '%Note to Receiving Staff:%'
            OR m.notes LIKE '%Note to Admin Receiving Section:%'
        )
        AND NOT EXISTS (
            SELECT 1
            FROM record_movements m_response
            INNER JOIN users u_response ON u_response.id = m_response.updated_by
            WHERE m_response.record_id = m.record_id
            AND m_response.id > m.id
            AND u_response.role = 'receiving_clerk'
            AND m_response.notes LIKE 'Receiving Staff updated the record in response to the City Secretary comment.%'
        )
        ORDER BY m.id DESC
        LIMIT 1");
    $stmt->execute([$recordId]);
    $comment = $stmt->fetch();
    if (!$comment) {
        return null;
    }

    $notes = (string) ($comment['notes'] ?? '');
    if (preg_match('/Note to (?:Receiving Staff|Admin Receiving Section):\s*(.+)$/is', $notes, $matches)) {
        $comment['comment'] = trim($matches[1]);
    } else {
        $comment['comment'] = trim($notes);
    }

    return $comment;
}

function can_edit_record(?array $record = null): bool
{
    $role = $_SESSION['user']['role'] ?? '';
    if ($record && ($record['status'] ?? '') === 'Approved in the Plenary' && !in_array($role, ['admin', 'city_secretary'], true)) {
        return false;
    }
    if ($record && can_manage_for_plenary_record($record)) {
        return true;
    }


    if (in_array($role, ['admin', 'city_secretary', 'records_officer'], true)) {
        return true;
    }

    if ($role === 'receiving_clerk' && $record) {
        if (is_administrative_document_type($record['document_type'] ?? '')) {
            return true;
        }

        return receiving_clerk_can_edit_unreviewed_record($record)
            || record_has_pending_receiving_staff_comment($record);
    }

    return false;
}

function receiving_clerk_can_edit_unreviewed_record(array $record): bool
{
    return ($_SESSION['user']['role'] ?? '') === 'receiving_clerk'
        && ($record['status'] ?? '') === 'Received';
}

function receiving_clerk_can_manage_unassigned_record(array $record): bool
{
    if (($_SESSION['user']['role'] ?? '') !== 'receiving_clerk') {
        return false;
    }

    if (($record['status'] ?? '') !== 'Received') {
        return false;
    }

    $userId = (int) ($_SESSION['user']['id'] ?? 0);
    return (int) ($record['receiving_clerk_id'] ?? 0) === $userId
        || (int) ($record['created_by'] ?? 0) === $userId;
}

function receiving_clerk_can_upload_to_record(array $record): bool
{
    if (($_SESSION['user']['role'] ?? '') !== 'receiving_clerk') {
        return false;
    }

    if (($record['status'] ?? '') === 'Received') {
        return true;
    }

    $userId = (int) ($_SESSION['user']['id'] ?? 0);
    return empty($record['receiving_clerk_id'])
        || (int) $record['receiving_clerk_id'] === $userId
        || (int) ($record['created_by'] ?? 0) === $userId;
}

function division_chief_first_action_done(array $record): bool
{
    $recordId = (int) ($record['id'] ?? 0);
    if ($recordId <= 0) {
        return false;
    }

    if (!in_array($record['status'] ?? '', ['Assigned to the Committee', 'Pending to the Committee'], true)) {
        return true;
    }

    try {
        $stmt = db()->prepare("SELECT COUNT(*)
            FROM record_movements m
            INNER JOIN users u ON u.id = m.updated_by
            WHERE m.record_id = ?
            AND u.role = 'division_chief'");
        $stmt->execute([$recordId]);
        return (int) $stmt->fetchColumn() > 0;
    } catch (Throwable $error) {
        return false;
    }
}

function can_update_record_status(array $record): bool
{
    if (($_SESSION['user']['role'] ?? '') === 'receiving_clerk') {
        return false;
    }

    $role = $_SESSION['user']['role'] ?? '';
    if ($role === 'secretariat'
        && is_laws_and_rules_secretariat()
        && in_array($record['document_type'] ?? '', ['Committee Referrals', 'Certified Urgent'], true)
        && in_array($record['status'] ?? '', ['For Plenary Session', 'Scheduled for Plenary'], true)) {
        return true;
    }

    if (($record['document_type'] ?? '') === 'Certified Urgent') {
        if (($record['status'] ?? '') === 'Approved in the Plenary'
            || in_array($record['status'] ?? '', post_plenary_statuses(), true)) {
            return $role === 'administrative_support';
        }

        return in_array($role, ['admin', 'city_secretary'], true);
    }

    if (($record['document_type'] ?? '') === 'Committee Referrals' && !empty($record['committee_id'])) {
        if (($record['status'] ?? '') === 'Received') {
            return false;
        }
        if (($record['status'] ?? '') === 'Approved in the Plenary') {
            return $role === 'administrative_support';
        }

        if (in_array($record['status'] ?? '', post_plenary_statuses(), true)) {
            return $role === 'administrative_support';
        }

        if (($record['status'] ?? '') === 'Approved in the Plenary'
            && in_array($role, ['division_chief', 'secretariat', 'receiving_clerk'], true)) {
            return false;
        }

        if ($role === 'admin') {
            return true;
        }

        if ($role === 'city_secretary') {
            return in_array($record['status'] ?? '', ['For Plenary Session', 'Scheduled for Plenary', 'Approved in the Plenary'], true);
        }

        if ($role === 'division_chief') {
            return can_access_record_committees($record);
        }

        if ($role === 'secretariat') {
            return can_access_record_committees($record) && division_chief_first_action_done($record);
        }

        return false;
    }

    return can_edit_record($record);
}

function can_view_record_history(array $record): bool
{
    return can_view_record($record);
}

function can_view_record_materials(array $record): bool
{
    $role = $_SESSION['user']['role'] ?? '';
    if (in_array($role, ['others', 'server_maintenance_staff'], true)) {
        return can_view_record($record);
    }

    if ($role === 'secretariat'
        && is_laws_and_rules_secretariat()
        && in_array($record['document_type'] ?? '', ['Committee Referrals', 'Certified Urgent'], true)
        && in_array($record['status'] ?? '', ['For Plenary Session', 'Scheduled for Plenary'], true)) {
        return true;
    }

    if (($record['document_type'] ?? '') === 'Certified Urgent') {
        return true;
    }

    if (in_array($role, ['admin', 'city_secretary'], true)) {
        return true;
    }

    if ($role === 'administrative_support') {
        return ($record['document_type'] ?? '') === 'Committee Referrals'
            && in_array($record['status'] ?? '', array_merge(['Approved in the Plenary'], post_plenary_statuses()), true);
    }

    if (!empty($record['committee_id']) && in_array($role, ['division_chief', 'secretariat', 'division_staff'], true)) {
        if (($record['status'] ?? '') === 'Received') {
            return false;
        }

        return can_access_record_committees($record);
    }

    if ($role === 'receiving_clerk') {
        return true;
    }

    return false;
}

function can_view_record_attachments(array $record): bool
{
    return can_view_record_materials($record);
}

function can_manage_plenary_record_attachments(array $record): bool
{
    return ($_SESSION['user']['role'] ?? '') === 'secretariat'
        && is_laws_and_rules_secretariat()
        && in_array($record['document_type'] ?? '', ['Committee Referrals', 'Certified Urgent'], true)
        && in_array($record['status'] ?? '', ['For Plenary Session', 'Scheduled for Plenary'], true);
}

function record_attachment_display_title(array $attachment): string
{
    $title = trim((string) ($attachment['title'] ?? ''));
    if ($title !== '') {
        return $title;
    }

    return trim((string) ($attachment['original_name'] ?? ''));
}

function can_view_transmittal_contact_details(?array $record = null): bool
{
    $role = $_SESSION['user']['role'] ?? '';
    return in_array($role, ['admin', 'administrative_support'], true);
}

function record_has_plenary_approval(array $record): bool
{
    return in_array($record['document_type'] ?? '', ['Committee Referrals', 'Certified Urgent'], true)
        && (
            trim((string) ($record['plenary_approved_date'] ?? '')) !== ''
            || trim((string) ($record['approved_ordinance_number'] ?? '')) !== ''
            || trim((string) ($record['approved_resolution_number'] ?? '')) !== ''
        );
}

function can_manage_transmittal_recipients(array $record): bool
{
    return ($record['status'] ?? '') === 'For Transmittal'
        && record_has_plenary_approval($record)
        && can_view_transmittal_contact_details($record);
}

function can_upload_record_attachment(array $record): bool
{
    $role = $_SESSION['user']['role'] ?? '';
    if ($role === 'admin') {
        return true;
    }

    if ($role === 'receiving_clerk') {
        return receiving_clerk_can_upload_to_record($record);
    }

    if (in_array($role, ['division_chief', 'secretariat'], true)) {
        return ($record['document_type'] ?? '') === 'Committee Referrals'
            && !empty($record['committee_id'])
            && ($record['status'] ?? '') !== 'Received'
            && can_access_record_committees($record);
    }

    return false;
}

function can_city_secretary_action(): bool
{
    return in_array($_SESSION['user']['role'] ?? '', ['admin', 'city_secretary'], true);
}

function can_assign_referral_committee(array $record): bool
{
    return can_city_secretary_action() && ($record['document_type'] ?? '') === 'Committee Referrals';
}

function can_act_on_administrative_document(array $record): bool
{
    return can_city_secretary_action() && is_administrative_document_type($record['document_type'] ?? '');
}

function can_view_audit_logs(): bool
{
    return in_array($_SESSION['user']['role'] ?? '', ['admin', 'server_maintenance_staff'], true);
}

function can_backup_system(): bool
{
    return in_array($_SESSION['user']['role'] ?? '', ['admin', 'server_maintenance_staff'], true);
}

function can_view_reports(): bool
{
    return !in_array($_SESSION['user']['role'] ?? '', ['others', 'server_maintenance_staff'], true);
}

function can_delete_records(): bool
{
    return in_array($_SESSION['user']['role'] ?? '', ['admin', 'city_secretary'], true);
}

function can_delete_record(?array $record = null): bool
{
    if (can_delete_records()) {
        return true;
    }

    return $record ? receiving_clerk_can_manage_unassigned_record($record) : false;
}

function committee_referral_printed(int $recordId): bool
{
    try {
        $stmt = db()->prepare("SELECT COUNT(*) FROM record_movements WHERE record_id = ? AND notes LIKE 'Committee Referral print%'");
        $stmt->execute([$recordId]);
        return (int) $stmt->fetchColumn() > 0;
    } catch (Throwable $error) {
        return false;
    }
}

function record_needs_user_action(array $record): bool
{
    $role = $_SESSION['user']['role'] ?? '';
    $status = $record['status'] ?? '';
    $documentType = $record['document_type'] ?? '';

    if ($role === 'admin') {
        return (
            $documentType === 'Committee Referrals'
            && $status === 'Received'
        ) || (
            is_administrative_document_type($documentType)
            && !in_array($status, ['Completed', 'Archived'], true)
        );
    }

    if ($role === 'city_secretary') {
        return (
            $documentType === 'Committee Referrals'
            && $status === 'Received'
            && !record_has_pending_receiving_staff_comment($record)
        ) || (
            is_administrative_document_type($documentType)
            && !in_array($status, ['Completed', 'Archived'], true)
        );
    }

    if ($role === 'receiving_clerk') {
        return record_has_pending_receiving_staff_comment($record)
            || ($documentType === 'Committee Referrals'
            && in_array($status, ['Assigned to the Committee', 'Pending to the Committee'], true)
            && !committee_referral_printed((int) ($record['id'] ?? 0))
            && (
                empty($record['receiving_clerk_id'])
                || (int) $record['receiving_clerk_id'] === (int) ($_SESSION['user']['id'] ?? 0)
            ));
    }

    if ($role === 'division_chief') {
        return $documentType === 'Committee Referrals'
            && !empty($record['committee_id'])
            && can_access_record_committees($record)
            && record_needs_division_chief_action($record);
    }

    if ($role === 'secretariat') {
        return record_needs_secretariat_action($record);
    }

    return false;
}

function record_needs_division_chief_action(array $record): bool
{
    return ($record['document_type'] ?? '') === 'Committee Referrals'
        && !empty($record['committee_id'])
        && in_array($record['status'] ?? '', ['Assigned to the Committee', 'Pending to the Committee'], true)
        && !division_chief_first_action_done($record);
}

function record_needs_division_chief_action_sql(string $alias = 'records'): string
{
    return "$alias.document_type = 'Committee Referrals'
        AND $alias.committee_id IS NOT NULL
        AND $alias.status IN ('Assigned to the Committee', 'Pending to the Committee')
        AND NOT EXISTS (
            SELECT 1
            FROM record_movements m_chief_action
            INNER JOIN users u_chief_action ON u_chief_action.id = m_chief_action.updated_by
            WHERE m_chief_action.record_id = $alias.id
            AND u_chief_action.role = 'division_chief'
        )";
}

function record_needs_secretariat_action_sql(string $alias = 'records'): string
{
    return "$alias.document_type = 'Committee Referrals'
        AND $alias.committee_id IS NOT NULL
        AND (
            (
                SELECT COALESCE(MAX(m_chief_action.id), 0)
                FROM record_movements m_chief_action
                INNER JOIN users u_chief_action ON u_chief_action.id = m_chief_action.updated_by
                WHERE m_chief_action.record_id = $alias.id
                AND u_chief_action.role = 'division_chief'
            ) > (
                SELECT COALESCE(MAX(m_secretariat_action.id), 0)
                FROM record_movements m_secretariat_action
                INNER JOIN users u_secretariat_action ON u_secretariat_action.id = m_secretariat_action.updated_by
                WHERE m_secretariat_action.record_id = $alias.id
                AND u_secretariat_action.role = 'secretariat'
            )
            OR (
                $alias.status = 'Referred Back to Committee'
                AND (
                    SELECT COALESCE(MAX(m_returned_action.id), 0)
                    FROM record_movements m_returned_action
                    WHERE m_returned_action.record_id = $alias.id
                    AND m_returned_action.to_status = 'Referred Back to Committee'
                ) >= (
                    SELECT COALESCE(MAX(m_secretariat_return_action.id), 0)
                    FROM record_movements m_secretariat_return_action
                    INNER JOIN users u_secretariat_return_action ON u_secretariat_return_action.id = m_secretariat_return_action.updated_by
                    WHERE m_secretariat_return_action.record_id = $alias.id
                    AND u_secretariat_return_action.role = 'secretariat'
                )
            )
        )";
}

function record_needs_secretariat_action(array $record): bool
{
    if (($_SESSION['user']['role'] ?? '') !== 'secretariat'
        || ($record['document_type'] ?? '') !== 'Committee Referrals'
        || empty($record['committee_id'])
        || !can_access_record_committees($record)) {
        return false;
    }

    if (array_key_exists('needs_secretariat_action', $record)) {
        return (int) $record['needs_secretariat_action'] === 1;
    }

    try {
        $stmt = db()->prepare('SELECT CASE WHEN ' . record_needs_secretariat_action_sql('r') . ' THEN 1 ELSE 0 END FROM records r WHERE r.id = ?');
        $stmt->execute([(int) ($record['id'] ?? 0)]);
        return (int) $stmt->fetchColumn() === 1;
    } catch (Throwable $error) {
        return false;
    }
}

function record_action_priority_sql(string $alias = 'records'): string
{
    $role = $_SESSION['user']['role'] ?? '';

    if ($role === 'admin') {
        return "CASE WHEN (
            ($alias.document_type = 'Committee Referrals' AND $alias.status = 'Received')
            OR ($alias.document_type IN ('Transmittals, Letters and Endorsements', 'Memorandum, Executive Order, Directive Order and Etc.') AND $alias.status NOT IN ('Completed', 'Archived'))
        ) THEN 0 ELSE 1 END";
    }

    if ($role === 'city_secretary') {
        return "CASE WHEN (
            ($alias.document_type = 'Committee Referrals'
                AND $alias.status = 'Received'
                AND NOT (" . pending_receiving_staff_comment_sql($alias) . "))
            OR ($alias.document_type IN ('Transmittals, Letters and Endorsements', 'Memorandum, Executive Order, Directive Order and Etc.') AND $alias.status NOT IN ('Completed', 'Archived'))
        ) THEN 0 ELSE 1 END";
    }

    if ($role === 'receiving_clerk') {
        $userId = (int) ($_SESSION['user']['id'] ?? 0);
        return "CASE WHEN (
            (
                $alias.document_type = 'Committee Referrals'
                AND $alias.status IN ('Assigned to the Committee', 'Pending to the Committee')
                AND ($alias.receiving_clerk_id IS NULL OR $alias.receiving_clerk_id = $userId)
                AND NOT EXISTS (
                    SELECT 1 FROM record_movements m_action
                    WHERE m_action.record_id = $alias.id
                    AND m_action.notes LIKE 'Committee Referral print%'
                )
            )
            OR (" . pending_receiving_staff_comment_sql($alias) . ")
        ) THEN 0 ELSE 1 END";
    }

    if ($role === 'division_chief') {
        return 'CASE WHEN (' . record_needs_division_chief_action_sql($alias) . ') THEN 0 ELSE 1 END';
    }

    if ($role === 'secretariat') {
        return 'CASE WHEN (' . record_needs_secretariat_action_sql($alias) . ') THEN 0 ELSE 1 END';
    }

    return '1';
}

function action_required_count(): int
{
    $role = $_SESSION['user']['role'] ?? '';
    $params = [];

    if ($role === 'admin') {
        $where = "(document_type = 'Committee Referrals' AND status = 'Received')
            OR (document_type IN ('Transmittals, Letters and Endorsements', 'Memorandum, Executive Order, Directive Order and Etc.') AND status NOT IN ('Completed', 'Archived'))";
    } elseif ($role === 'city_secretary') {
        $where = "(document_type = 'Committee Referrals'
                AND status = 'Received'
                AND NOT (" . pending_receiving_staff_comment_sql('records') . "))
            OR (document_type IN ('Transmittals, Letters and Endorsements', 'Memorandum, Executive Order, Directive Order and Etc.') AND status NOT IN ('Completed', 'Archived'))";
    } elseif ($role === 'receiving_clerk') {
        $where = "(
            (
                document_type = 'Committee Referrals'
                AND status IN ('Assigned to the Committee', 'Pending to the Committee')
                AND (receiving_clerk_id IS NULL OR receiving_clerk_id = ?)
                AND NOT EXISTS (
                    SELECT 1 FROM record_movements m
                    WHERE m.record_id = records.id
                    AND m.notes LIKE 'Committee Referral print%'
                )
            )
            OR (" . pending_receiving_staff_comment_sql('records') . ")
        )";
        $params[] = (int) ($_SESSION['user']['id'] ?? 0);
    } elseif ($role === 'division_chief') {
        $committeeIds = division_chief_committee_ids();
        if (!$committeeIds) {
            return 0;
        }
        $where = "document_type = 'Committee Referrals'
            AND (committee_id IN (" . implode(',', array_fill(0, count($committeeIds), '?')) . ")
                OR EXISTS (
                    SELECT 1 FROM record_committees rc_scope
                    WHERE rc_scope.record_id = records.id
                    AND rc_scope.committee_id IN (" . implode(',', array_fill(0, count($committeeIds), '?')) . ")
                ))
            AND " . record_needs_division_chief_action_sql('records');
        $params = array_merge($committeeIds, $committeeIds);
    } elseif ($role === 'secretariat') {
        $committeeIds = secretariat_committee_ids();
        if (!$committeeIds) {
            return 0;
        }
        $where = "document_type = 'Committee Referrals'
            AND (committee_id IN (" . implode(',', array_fill(0, count($committeeIds), '?')) . ")
                OR EXISTS (
                    SELECT 1 FROM record_committees rc_scope
                    WHERE rc_scope.record_id = records.id
                    AND rc_scope.committee_id IN (" . implode(',', array_fill(0, count($committeeIds), '?')) . ")
                ))
            AND " . record_needs_secretariat_action_sql('records');
        $params = array_merge($committeeIds, $committeeIds);
    } else {
        return 0;
    }

    try {
        $stmt = db()->prepare('SELECT COUNT(*) FROM records WHERE ' . $where);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    } catch (Throwable $error) {
        return 0;
    }
}

function dashboard_action_required_count(): int
{
    $role = $_SESSION['user']['role'] ?? '';

    if ($role === 'city_secretary') {
        try {
            $pendingReceivingCommentSql = pending_receiving_staff_comment_sql('records');
            $stmt = db()->query("SELECT
                SUM(CASE
                    WHEN document_type = 'Committee Referrals' AND status = 'Received'
                        AND NOT ($pendingReceivingCommentSql)
                    THEN 1 ELSE 0
                END)
                + SUM(CASE
                    WHEN document_type IN ('Transmittals, Letters and Endorsements', 'Memorandum, Executive Order, Directive Order and Etc.')
                        AND status NOT IN ('Completed', 'Archived')
                    THEN 1 ELSE 0
                END)
                + SUM(CASE
                    WHEN document_type IN ('Committee Referrals', 'Certified Urgent')
                        AND status IN ('For Plenary Session', 'Scheduled for Plenary')
                        AND (status = 'For Plenary Session'
                            OR COALESCE(
                                NULLIF(TRIM(proposed_ordinance_number), ''),
                                NULLIF(TRIM(proposed_resolution_number), '')
                            ) IS NULL)
                    THEN 1 ELSE 0
                END)
                + SUM(CASE
                    WHEN document_type IN ('Committee Referrals', 'Certified Urgent')
                        AND status = 'Approved in the Plenary'
                        AND COALESCE(
                            NULLIF(TRIM(approved_ordinance_number), ''),
                            NULLIF(TRIM(approved_resolution_number), '')
                        ) IS NULL
                    THEN 1 ELSE 0
                END) total
                FROM records");
            return (int) ($stmt->fetchColumn() ?: 0);
        } catch (Throwable $error) {
            return 0;
        }
    }

    if ($role === 'secretariat') {
        $committeeIds = secretariat_committee_ids();
        if (!$committeeIds) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($committeeIds), '?'));
        try {
            $stmt = db()->prepare("SELECT COUNT(DISTINCT r.id)
                FROM records r
                WHERE (" . record_needs_secretariat_action_sql('r') . ")
                AND (r.committee_id IN ($placeholders)
                    OR EXISTS (
                        SELECT 1 FROM record_committees rc_scope
                        WHERE rc_scope.record_id = r.id
                        AND rc_scope.committee_id IN ($placeholders)
                    ))");
            $stmt->execute(array_merge($committeeIds, $committeeIds));
            return (int) $stmt->fetchColumn();
        } catch (Throwable $error) {
            return 0;
        }
    }

    if ($role === 'division_chief') {
        $chiefId = (int) ($_SESSION['user']['id'] ?? 0);
        $committeeIds = division_chief_committee_ids($chiefId);
        if (!$committeeIds) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($committeeIds), '?'));

        try {
            $newReferralStmt = db()->prepare("SELECT COUNT(DISTINCT r.id)
                FROM records r
                WHERE r.document_type = 'Committee Referrals'
                AND r.status IN ('Assigned to the Committee', 'Pending to the Committee')
                AND NOT EXISTS (
                    SELECT 1 FROM record_movements m_first
                    INNER JOIN users u_first ON u_first.id = m_first.updated_by
                    WHERE m_first.record_id = r.id
                    AND u_first.role = 'division_chief'
                )
                AND (r.committee_id IN ($placeholders)
                    OR EXISTS (
                        SELECT 1 FROM record_committees rc_scope
                        WHERE rc_scope.record_id = r.id
                        AND rc_scope.committee_id IN ($placeholders)
                    ))");
            $newReferralStmt->execute(array_merge($committeeIds, $committeeIds));
            $newReferralCount = (int) $newReferralStmt->fetchColumn();

            $chiefStmt = db()->prepare('SELECT division_name FROM users WHERE id = ? LIMIT 1');
            $chiefStmt->execute([$chiefId]);
            $chiefDivision = (string) ($chiefStmt->fetchColumn() ?: '');
            if ($chiefDivision === '') {
                return $newReferralCount;
            }

            $reviewNeededStmt = db()->prepare("SELECT COUNT(*)
                FROM record_movements m
                INNER JOIN records r ON r.id = m.record_id
                INNER JOIN users u ON u.id = m.updated_by
                WHERE u.division_name = ?
                AND u.id <> ?
                AND u.role = 'secretariat'
                AND COALESCE(NULLIF(m.chief_remarks, ''), '') = ''
                AND m.id = (
                    SELECT MAX(m_latest.id)
                    FROM record_movements m_latest
                    INNER JOIN users u_latest ON u_latest.id = m_latest.updated_by
                    WHERE m_latest.record_id = m.record_id
                    AND u_latest.role = 'secretariat'
                    AND COALESCE(u_latest.division_name, '') = COALESCE(u.division_name, '')
                )
                AND (r.committee_id IN ($placeholders)
                    OR EXISTS (
                        SELECT 1 FROM record_committees rc_scope
                        WHERE rc_scope.record_id = r.id
                        AND rc_scope.committee_id IN ($placeholders)
                    ))");
            $reviewNeededStmt->execute(array_merge(
                [$chiefDivision, $chiefId],
                $committeeIds,
                $committeeIds
            ));

            return $newReferralCount + (int) $reviewNeededStmt->fetchColumn();
        } catch (Throwable $error) {
            return 0;
        }
    }

    if ($role === 'administrative_support') {
        $activeStatuses = array_values(array_filter(
            array_merge(['Approved in the Plenary'], post_plenary_statuses()),
            fn ($status) => $status !== 'Completed'
        ));
        $placeholders = implode(',', array_fill(0, count($activeStatuses), '?'));

        try {
            $stmt = db()->prepare("SELECT COUNT(*)
                FROM records
                WHERE document_type = 'Committee Referrals'
                AND status IN ($placeholders)
                AND (plenary_approved_date IS NOT NULL
                    OR COALESCE(NULLIF(approved_ordinance_number, ''), NULLIF(approved_resolution_number, '')) IS NOT NULL)");
            $stmt->execute($activeStatuses);
            return (int) $stmt->fetchColumn();
        } catch (Throwable $error) {
            return 0;
        }
    }

    return action_required_count();
}

function action_required_count_for_type(string $documentType): int
{
    $role = $_SESSION['user']['role'] ?? '';
    $params = [];

    if (in_array($role, ['admin', 'city_secretary'], true)) {
        if ($documentType === 'Committee Referrals') {
            $where = "document_type = 'Committee Referrals' AND status = 'Received'";
            if ($role === 'city_secretary') {
                $where .= " AND NOT (" . pending_receiving_staff_comment_sql('records') . ")";
            }
        } elseif (is_administrative_document_type($documentType)) {
            $where = "document_type IN ('Transmittals, Letters and Endorsements', 'Memorandum, Executive Order, Directive Order and Etc.') AND status NOT IN ('Completed', 'Archived')";
        } else {
            return 0;
        }
    } elseif ($role === 'receiving_clerk' && $documentType === 'Committee Referrals') {
        $where = "(
            (
                document_type = 'Committee Referrals'
                AND status IN ('Assigned to the Committee', 'Pending to the Committee')
                AND (receiving_clerk_id IS NULL OR receiving_clerk_id = ?)
                AND NOT EXISTS (
                    SELECT 1 FROM record_movements m
                    WHERE m.record_id = records.id
                    AND m.notes LIKE 'Committee Referral print%'
                )
            )
            OR (" . pending_receiving_staff_comment_sql('records') . ")
        )";
        $params[] = (int) ($_SESSION['user']['id'] ?? 0);
    } elseif ($role === 'division_chief' && $documentType === 'Committee Referrals') {
        $committeeIds = division_chief_committee_ids();
        if (!$committeeIds) {
            return 0;
        }
        $where = "document_type = 'Committee Referrals'
            AND (committee_id IN (" . implode(',', array_fill(0, count($committeeIds), '?')) . ")
                OR EXISTS (
                    SELECT 1 FROM record_committees rc_scope
                    WHERE rc_scope.record_id = records.id
                    AND rc_scope.committee_id IN (" . implode(',', array_fill(0, count($committeeIds), '?')) . ")
                ))
            AND " . record_needs_division_chief_action_sql('records');
        $params = array_merge($committeeIds, $committeeIds);
    } elseif ($role === 'secretariat' && $documentType === 'Committee Referrals') {
        $committeeIds = secretariat_committee_ids();
        if (!$committeeIds) {
            return 0;
        }
        $where = "document_type = 'Committee Referrals'
            AND (committee_id IN (" . implode(',', array_fill(0, count($committeeIds), '?')) . ")
                OR EXISTS (
                    SELECT 1 FROM record_committees rc_scope
                    WHERE rc_scope.record_id = records.id
                    AND rc_scope.committee_id IN (" . implode(',', array_fill(0, count($committeeIds), '?')) . ")
                ))
            AND " . record_needs_secretariat_action_sql('records');
        $params = array_merge($committeeIds, $committeeIds);
    } else {
        return 0;
    }

    try {
        $stmt = db()->prepare('SELECT COUNT(*) FROM records WHERE ' . $where);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    } catch (Throwable $error) {
        return 0;
    }
}

function control_number_link(array $record, ?string $url = null): string
{
    $url ??= url('/record_view.php?id=' . (int) $record['id']);
    $dot = record_needs_user_action($record)
        ? '<span class="action-dot" title="Needs your action" aria-label="Needs your action"></span>'
        : '';

    return '<a class="control-link" href="' . e($url) . '">' . $dot . '<span>' . e($record['control_number'] ?? '') . '</span></a>';
}

function public_record_status_url(int $recordId): string
{
    if ($recordId <= 0) {
        return '';
    }

    $httpsValue = strtolower(trim((string) ($_SERVER['HTTPS'] ?? '')));
    $scheme = $httpsValue !== '' && !in_array($httpsValue, ['off', '0'], true) ? 'https' : 'http';
    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '' || !preg_match('/^(?:[a-z0-9.-]+|\[[a-f0-9:]+\])(?::[0-9]{1,5})?$/i', $host)) {
        $host = 'localhost';
    }

    return $scheme . '://' . $host . url('/public_status.php?record_id=' . $recordId);
}

function audit_log(string $action, string $description, ?string $entityType = null, ?int $entityId = null): void
{
    try {
        $stmt = db()->prepare('INSERT INTO audit_logs (user_id, action, entity_type, entity_id, description, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $_SESSION['user']['id'] ?? null,
            $action,
            $entityType,
            $entityId,
            $description,
            $_SERVER['REMOTE_ADDR'] ?? null,
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
        ]);
    } catch (Throwable $error) {
        // Older installations may not have audit_logs yet. Do not block office work.
    }
}

function status_class(string $status): string
{
    return strtolower(str_replace(' ', '-', $status));
}

function referral_statuses(): array
{
    return [
        'Received',
        'Assigned to the Committee',
        'Pending to the Committee',
        'For Meeting',
        'For Inspection',
        'Recommending Approval',
        'Deferred',
        'Tabled',
        'Noted',
        'Referred To',
        'Referred Back to Committee',
        'Perusal',
        'Endorsement',
        'For Plenary Session',
        'Scheduled for Plenary',
        'Disapproved',
        'Approved in the Plenary',
        'Others',
    ];
}

function post_plenary_statuses(): array
{
    return [
        "For Vice Mayor's Signature",
        'Returned from The Vice Mayor',
        'Forwarded for Admin/Mayor Signature',
        'Returned from Admin/Mayor',
        'Veto',
        'Lapse into Ordinance',
        'Forwarded to the Messengerial Services',
        'For Transmittal',
        'Completed',
    ];
}

function administrative_statuses(): array
{
    return ['Received', 'Completed', 'Archived'];
}

function administrative_document_types(): array
{
    return [
        'Transmittals, Letters and Endorsements',
        'Memorandum, Executive Order, Directive Order and Etc.',
    ];
}

function is_administrative_document_type(string $documentType): bool
{
    return in_array($documentType, administrative_document_types(), true);
}

function all_statuses(): array
{
    return array_values(array_unique(array_merge(referral_statuses(), post_plenary_statuses(), administrative_statuses())));
}

function ensure_committee_reporting_schema(): void
{
    try {
        db()->exec("ALTER TABLE committees ADD COLUMN committee_code VARCHAR(20) NULL AFTER name");
    } catch (Throwable $error) {
        // Column already exists or the current database user cannot alter it here.
    }

    try {
        db()->exec("CREATE TABLE IF NOT EXISTS committee_report_numbers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            record_id INT NOT NULL,
            committee_id INT NOT NULL,
            report_year INT NOT NULL,
            sequence_no INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_committee_report_record (record_id, committee_id, report_year),
            KEY idx_committee_report_counter (committee_id, report_year, sequence_no),
            CONSTRAINT fk_committee_report_record FOREIGN KEY (record_id) REFERENCES records(id) ON DELETE CASCADE,
            CONSTRAINT fk_committee_report_committee FOREIGN KEY (committee_id) REFERENCES committees(id) ON DELETE CASCADE
        )");
    } catch (Throwable $error) {
        // Report numbers are optional until the migration is available.
    }
}

function ensure_division_chief_notes_schema(): bool
{
    static $available = null;

    if ($available !== null) {
        return $available;
    }

    try {
        db()->exec("CREATE TABLE IF NOT EXISTS division_chief_notes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            record_id INT NULL,
            note_text TEXT NOT NULL,
            reminder_at DATETIME NULL,
            completed_at DATETIME NULL,
            archived_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_division_chief_notes_user_updated (user_id, updated_at),
            KEY idx_division_chief_notes_user_reminder (user_id, reminder_at),
            KEY idx_division_chief_notes_record (record_id),
            CONSTRAINT fk_division_chief_notes_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_division_chief_notes_record FOREIGN KEY (record_id) REFERENCES records(id) ON DELETE CASCADE
        )");
        $available = true;
    } catch (Throwable $error) {
        error_log('Division Chief notes schema is unavailable: ' . $error->getMessage());
        $available = false;
    }

    if ($available) {
        try {
            db()->exec("ALTER TABLE division_chief_notes ADD COLUMN reminder_at DATETIME NULL AFTER note_text");
        } catch (Throwable $error) {
            // The reminder column already exists or can be added through the migration.
        }

        try {
            db()->exec("ALTER TABLE division_chief_notes ADD KEY idx_division_chief_notes_user_reminder (user_id, reminder_at)");
        } catch (Throwable $error) {
            // The reminder index already exists or can be added through the migration.
        }

        try {
            $noteColumns = db()->query('SHOW COLUMNS FROM division_chief_notes')->fetchAll(PDO::FETCH_UNIQUE);
            if (($noteColumns['record_id']['Null'] ?? '') !== 'YES') {
                db()->exec('ALTER TABLE division_chief_notes MODIFY record_id INT NULL');
            }
            foreach (['completed_at', 'archived_at'] as $column) {
                if (!isset($noteColumns[$column])) {
                    db()->exec('ALTER TABLE division_chief_notes ADD COLUMN ' . $column . ' DATETIME NULL');
                }
            }
            db()->query('SELECT reminder_at, completed_at, archived_at FROM division_chief_notes LIMIT 0');
        } catch (Throwable $error) {
            error_log('Division Chief note reminders are unavailable: ' . $error->getMessage());
            $available = false;
        }
    }

    return $available;
}

function ensure_plenary_number_schema(): void
{
    try {
        db()->exec("ALTER TABLE users MODIFY role ENUM('admin', 'city_secretary', 'division_chief', 'receiving_clerk', 'secretariat', 'division_staff', 'administrative_support', 'others', 'records_officer', 'staff', 'server_maintenance_staff') NOT NULL DEFAULT 'secretariat'");
    } catch (Throwable $error) {
        // Existing databases may already have this role list, or the user may apply SQL manually.
    }

    try {
        db()->exec("ALTER TABLE records MODIFY status ENUM('Received', 'Assigned to the Committee', 'Pending to the Committee', 'For Meeting', 'For Inspection', 'Recommending Approval', 'Deferred', 'Tabled', 'Noted', 'Referred', 'Referred To', 'Referred Back to Committee', 'Perusal', 'Endorsement', 'For Plenary Session', 'Scheduled for Plenary', 'Disapproved', 'Approved in the Plenary', 'For Vice Mayor''s Signature', 'Returned from The Vice Mayor', 'Forwarded for Admin/Mayor Signature', 'Returned from Admin/Mayor', 'Veto', 'Lapse into Ordinance', 'Forwarded to the Messengerial Services', 'For Transmittal', 'Others', 'Completed', 'Archived') NOT NULL DEFAULT 'Received'");
        db()->exec("UPDATE records SET status = 'Referred To' WHERE status = 'Referred'");
        db()->exec("UPDATE record_movements SET from_status = 'Referred To' WHERE from_status = 'Referred'");
        db()->exec("UPDATE record_movements SET to_status = 'Referred To' WHERE to_status = 'Referred'");
        db()->exec("ALTER TABLE records MODIFY status ENUM('Received', 'Assigned to the Committee', 'Pending to the Committee', 'For Meeting', 'For Inspection', 'Recommending Approval', 'Deferred', 'Tabled', 'Noted', 'Referred To', 'Referred Back to Committee', 'Perusal', 'Endorsement', 'For Plenary Session', 'Scheduled for Plenary', 'Disapproved', 'Approved in the Plenary', 'For Vice Mayor''s Signature', 'Returned from The Vice Mayor', 'Forwarded for Admin/Mayor Signature', 'Returned from Admin/Mayor', 'Veto', 'Lapse into Ordinance', 'Forwarded to the Messengerial Services', 'For Transmittal', 'Others', 'Completed', 'Archived') NOT NULL DEFAULT 'Received'");
    } catch (Throwable $error) {
        // Existing databases may already have this status list, or the user may apply SQL manually.
    }

    try {
        db()->exec("ALTER TABLE records ADD COLUMN proposed_ordinance_number VARCHAR(80) NULL AFTER remarks");
    } catch (Throwable $error) {
        // Column already exists or the current database user cannot alter it here.
    }

    try {
        db()->exec("ALTER TABLE records ADD COLUMN proposed_resolution_number VARCHAR(80) NULL AFTER proposed_ordinance_number");
    } catch (Throwable $error) {
        // Column already exists or the current database user cannot alter it here.
    }

    try {
        db()->exec("ALTER TABLE records ADD COLUMN proposed_by_city_council_member TINYINT(1) NOT NULL DEFAULT 0 AFTER remarks");
    } catch (Throwable $error) {
        // Column already exists or the current database user cannot alter it here.
    }

    try {
        db()->exec("ALTER TABLE records ADD COLUMN approved_ordinance_number VARCHAR(80) NULL AFTER proposed_resolution_number");
    } catch (Throwable $error) {
        // Column already exists or the current database user cannot alter it here.
    }

    try {
        db()->exec("ALTER TABLE records ADD COLUMN approved_resolution_number VARCHAR(80) NULL AFTER approved_ordinance_number");
    } catch (Throwable $error) {
        // Column already exists or the current database user cannot alter it here.
    }

    try {
        db()->exec("ALTER TABLE records ADD COLUMN plenary_session_date DATE NULL AFTER approved_resolution_number");
    } catch (Throwable $error) {
        // Column already exists or the current database user cannot alter it here.
    }

    try {
        db()->exec("ALTER TABLE records ADD COLUMN plenary_approved_date DATE NULL AFTER plenary_session_date");
    } catch (Throwable $error) {
        // Column already exists or the current database user cannot alter it here.
    }

    try {
        db()->exec("UPDATE records r
            SET r.plenary_session_date = (
                SELECT STR_TO_DATE(
                    SUBSTRING(REGEXP_SUBSTR(m.notes, 'Date: [0-9]{4}-[0-9]{2}-[0-9]{2}'), 7),
                    '%Y-%m-%d'
                )
                FROM record_movements m
                WHERE m.record_id = r.id
                AND m.to_status = 'For Plenary Session'
                AND m.notes REGEXP 'Date: [0-9]{4}-[0-9]{2}-[0-9]{2}'
                ORDER BY m.created_at DESC, m.id DESC
                LIMIT 1
            )
            WHERE r.plenary_session_date IS NULL
            AND EXISTS (
                SELECT 1
                FROM record_movements m_existing
                WHERE m_existing.record_id = r.id
                AND m_existing.to_status = 'For Plenary Session'
                AND m_existing.notes REGEXP 'Date: [0-9]{4}-[0-9]{2}-[0-9]{2}'
            )");
    } catch (Throwable $error) {
        // Historical plenary dates remain available in movement notes until migration is applied.
    }

    try {
        db()->exec("UPDATE records
            SET status = 'Scheduled for Plenary'
            WHERE status = 'For Plenary Session'
            AND plenary_session_date IS NOT NULL");
    } catch (Throwable $error) {
        // The new status may not be available until the migration is applied.
    }

    try {
        db()->exec("ALTER TABLE records ADD COLUMN contact_number VARCHAR(80) NULL AFTER client_name");
    } catch (Throwable $error) {
        // Column already exists or the current database user cannot alter it here.
    }

    try {
        db()->exec("ALTER TABLE records ADD COLUMN client_email VARCHAR(180) NULL AFTER contact_number");
    } catch (Throwable $error) {
        // Column already exists or the current database user cannot alter it here.
    }

    try {
        db()->exec("ALTER TABLE record_movements ADD COLUMN chief_remarks_updated_at DATETIME NULL AFTER chief_remarks");
    } catch (Throwable $error) {
        // Column already exists or the current database user cannot alter it here.
    }

    try {
        db()->exec("ALTER TABLE record_movements ADD COLUMN record_title VARCHAR(1000) NULL AFTER report_title");
    } catch (Throwable $error) {
        // Column already exists or the current database user cannot alter it here.
    }

    try {
        db()->exec("ALTER TABLE record_movements ADD COLUMN previous_title VARCHAR(1000) NULL AFTER record_title");
    } catch (Throwable $error) {
        // Column already exists or the current database user cannot alter it here.
    }

    try {
        db()->exec("UPDATE record_movements m
            JOIN records r ON r.id = m.record_id
            SET m.record_title = COALESCE(NULLIF(TRIM(m.report_title), ''), r.title),
                m.previous_title = CASE
                    WHEN NULLIF(TRIM(m.report_title), '') IS NOT NULL
                        AND TRIM(m.report_title) <> TRIM(r.title)
                    THEN r.title
                    ELSE m.previous_title
                END
            WHERE m.record_title IS NULL");
    } catch (Throwable $error) {
        // Existing movements remain readable until the title-history migration is applied.
    }

    try {
        db()->exec("UPDATE record_movements
            SET chief_remarks_updated_at = created_at
            WHERE COALESCE(NULLIF(chief_remarks, ''), '') <> ''
            AND chief_remarks_updated_at IS NULL");
    } catch (Throwable $error) {
        // Existing comments remain readable if the timestamp column is unavailable.
    }

    try {
        db()->exec("CREATE TABLE IF NOT EXISTS record_recipients (
            id INT AUTO_INCREMENT PRIMARY KEY,
            record_id INT NOT NULL,
            title VARCHAR(80) NULL,
            name VARCHAR(180) NOT NULL,
            position VARCHAR(180) NULL,
            address TEXT NULL,
            contact_number VARCHAR(80) NULL,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_record_recipients_record (record_id),
            CONSTRAINT fk_record_recipients_record FOREIGN KEY (record_id) REFERENCES records(id) ON DELETE CASCADE,
            CONSTRAINT fk_record_recipients_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        )");
    } catch (Throwable $error) {
        // Recipient storage can be created manually if the database account cannot create tables here.
    }

    try {
        db()->exec("CREATE TABLE IF NOT EXISTS record_division_receipts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            record_id INT NOT NULL,
            division_name VARCHAR(160) NOT NULL,
            received_by INT NULL,
            received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_record_division_receipt (record_id, division_name),
            KEY idx_division_receipts_division (division_name, received_at),
            CONSTRAINT fk_division_receipts_record FOREIGN KEY (record_id) REFERENCES records(id) ON DELETE CASCADE,
            CONSTRAINT fk_division_receipts_user FOREIGN KEY (received_by) REFERENCES users(id) ON DELETE SET NULL
        )");
    } catch (Throwable $error) {
        // Physical-copy receipts can be created manually if the database account cannot create tables here.
    }

    try {
        db()->exec("ALTER TABLE records ADD COLUMN plenary_print_title VARCHAR(1000) NULL AFTER title");
    } catch (Throwable $error) {
        // Column already exists or the current database user can apply the plenary print-title migration manually.
    }

    try {
        db()->exec("ALTER TABLE records ADD UNIQUE KEY uq_records_control_number (control_number)");
    } catch (Throwable $error) {
        // The unique key already exists, or existing duplicates must be resolved before it can be added.
    }


    try {
        db()->exec("ALTER TABLE record_attachments ADD COLUMN title VARCHAR(255) NULL AFTER original_name");
    } catch (Throwable $error) {
        // Column already exists or the current database user can apply the attachment-title migration manually.
    }
}

function normalize_committee_code(string $code): string
{
    $code = strtoupper(trim($code));
    $code = preg_replace('/[^A-Z0-9-]/', '', $code) ?: '';
    return substr($code, 0, 20);
}

function committee_report_number(int $recordId, int $committeeId, string $committeeCode, ?int $year = null): string
{
    ensure_committee_reporting_schema();
    $year = $year ?: (int) date('Y');
    $committeeCode = normalize_committee_code($committeeCode) ?: ('COM' . $committeeId);

    try {
        $pdo = db();
        $pdo->beginTransaction();

        $existing = $pdo->prepare('SELECT sequence_no FROM committee_report_numbers WHERE record_id = ? AND committee_id = ? AND report_year = ? LIMIT 1');
        $existing->execute([$recordId, $committeeId, $year]);
        $sequenceNo = $existing->fetchColumn();

        if (!$sequenceNo) {
            $counter = $pdo->prepare('SELECT COALESCE(MAX(sequence_no), 0) + 1 FROM committee_report_numbers WHERE committee_id = ? AND report_year = ? FOR UPDATE');
            $counter->execute([$committeeId, $year]);
            $sequenceNo = (int) $counter->fetchColumn();

            $insert = $pdo->prepare('INSERT INTO committee_report_numbers (record_id, committee_id, report_year, sequence_no) VALUES (?, ?, ?, ?)');
            $insert->execute([$recordId, $committeeId, $year, $sequenceNo]);
        }

        $pdo->commit();
        return sprintf('%s-%04d-%02d', $committeeCode, (int) $sequenceNo, $year % 100);
    } catch (Throwable $error) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return sprintf('%s-%04d-%02d', $committeeCode, 1, $year % 100);
    }
}

function next_control_number(string $documentType = 'Committee Referrals'): string
{
    $year = date('Y');
    $prefix = is_administrative_document_type($documentType) ? 'A' : 'L';
    $stmt = db()->prepare("SELECT CAST(SUBSTRING(control_number, 3, 5) AS UNSIGNED) AS sequence_no
        FROM records
        WHERE control_number REGEXP ?
        ORDER BY sequence_no");
    $stmt->execute(['^' . $prefix . '-[0-9]{5}-' . $year . '$']);

    $next = 0;
    while (($sequenceNo = $stmt->fetchColumn()) !== false) {
        $sequenceNo = (int) $sequenceNo;
        if ($sequenceNo < $next) {
            continue;
        }
        if ($sequenceNo > $next) {
            break;
        }
        $next++;
    }

    if ($next > 99999) {
        throw new RuntimeException('No Communication Numbers are available for this type and year.');
    }

    return sprintf('%s-%05d-%s', $prefix, $next, $year);
}

function acquire_control_number_lock(string $documentType): string
{
    $year = date('Y');
    $prefix = is_administrative_document_type($documentType) ? 'A' : 'L';
    $lockName = 'lcd_records_control_number_' . $prefix . '_' . $year;
    $stmt = db()->prepare('SELECT GET_LOCK(?, 10)');
    $stmt->execute([$lockName]);
    if ((int) $stmt->fetchColumn() !== 1) {
        throw new RuntimeException('Unable to reserve the next Communication Number.');
    }

    return $lockName;
}

function release_control_number_lock(string $lockName): void
{
    $stmt = db()->prepare('SELECT RELEASE_LOCK(?)');
    $stmt->execute([$lockName]);
}

function control_number_exists(string $controlNumber, ?int $excludeRecordId = null): bool
{
    $sql = 'SELECT 1 FROM records WHERE control_number = ?';
    $params = [$controlNumber];
    if ($excludeRecordId) {
        $sql .= ' AND id <> ?';
        $params[] = $excludeRecordId;
    }
    $sql .= ' LIMIT 1';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return (bool) $stmt->fetchColumn();
}

function is_duplicate_control_number_error(Throwable $error): bool
{
    return $error instanceof PDOException
        && ((int) ($error->errorInfo[1] ?? 0) === 1062 || (string) $error->getCode() === '23000');
}
