<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../app/db.php';
if (!db()->query("SHOW COLUMNS FROM city_officials LIKE 'district'")->fetch()) {
    db()->exec(file_get_contents(__DIR__ . '/../database/migration_city_official_district.sql'));
}
echo "City official district migration applied.\n";
