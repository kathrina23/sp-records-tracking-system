ALTER TABLE users MODIFY role ENUM('admin', 'city_secretary', 'division_chief', 'receiving_clerk', 'secretariat', 'division_staff', 'administrative_support', 'others', 'records_officer', 'staff', 'server_maintenance_staff', 'messengerial_support', 'lmis_data_entry') NOT NULL DEFAULT 'secretariat';

CREATE TABLE IF NOT EXISTS legislation_drafts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    record_id INT NOT NULL,
    kind ENUM('ordinance','resolution') NOT NULL,
    number VARCHAR(255) NOT NULL,
    title TEXT NOT NULL,
    approved_date DATE NULL,
    keywords VARCHAR(1000) NOT NULL,
    category VARCHAR(255) NOT NULL,
    author VARCHAR(1000) NOT NULL,
    co_author VARCHAR(1000) NOT NULL DEFAULT '',
    folder_code VARCHAR(255) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    stored_name VARCHAR(255) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    file_size INT NOT NULL,
    updated_by INT NOT NULL,
    revision INT NOT NULL DEFAULT 1,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY legislation_record_kind (record_id, kind)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS legislation_publications LIKE legislation_drafts;
