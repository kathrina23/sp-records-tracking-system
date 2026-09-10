CREATE DATABASE IF NOT EXISTS lcd_records CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE lcd_records;

CREATE TABLE committees (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    committee_code VARCHAR(20) NULL,
    chairperson VARCHAR(150) NULL,
    description TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE committee_terms (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    start_year YEAR NOT NULL,
    end_year YEAR NOT NULL,
    is_current TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE committee_members (
    id INT AUTO_INCREMENT PRIMARY KEY,
    committee_id INT NOT NULL,
    term_id INT NOT NULL,
    name VARCHAR(150) NOT NULL,
    position ENUM('Chairperson', 'Vice Chairperson', 'Member') NOT NULL DEFAULT 'Member',
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_committee_members_committee FOREIGN KEY (committee_id) REFERENCES committees(id) ON DELETE CASCADE,
    CONSTRAINT fk_committee_members_term FOREIGN KEY (term_id) REFERENCES committee_terms(id) ON DELETE CASCADE
);

CREATE TABLE city_officials (
    id INT AUTO_INCREMENT PRIMARY KEY,
    term_id INT NOT NULL,
    name VARCHAR(150) NOT NULL,
    position ENUM('Vice Mayor', 'City Councilor') NOT NULL DEFAULT 'City Councilor',
    officer_role ENUM('Presiding Officer', 'Presiding Officer Pro-Tempore', 'Majority Floor Leader', 'Assistant Majority Floor Leader', 'Minority Floor Leader', 'Assistant Minority Floor Leader', 'SK Federation President', 'IPMR Representative', 'Association of Barangay Captain President') NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_city_officials_term FOREIGN KEY (term_id) REFERENCES committee_terms(id) ON DELETE CASCADE
);

CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    nickname VARCHAR(80) NULL,
    division_name VARCHAR(160) NULL,
    email VARCHAR(160) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin', 'city_secretary', 'division_chief', 'receiving_clerk', 'secretariat', 'division_staff', 'administrative_support', 'others', 'records_officer', 'staff', 'server_maintenance_staff') NOT NULL DEFAULT 'secretariat',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE audit_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    action VARCHAR(80) NOT NULL,
    entity_type VARCHAR(80) NULL,
    entity_id INT NULL,
    description TEXT NOT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_audit_logs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE committee_secretariats (
    id INT AUTO_INCREMENT PRIMARY KEY,
    committee_id INT NOT NULL,
    user_id INT NOT NULL,
    assigned_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_committee_secretariat (committee_id, user_id),
    CONSTRAINT fk_committee_secretariats_committee FOREIGN KEY (committee_id) REFERENCES committees(id) ON DELETE CASCADE,
    CONSTRAINT fk_committee_secretariats_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_committee_secretariats_assigned_by FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE committee_division_chiefs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    committee_id INT NOT NULL,
    user_id INT NOT NULL,
    assigned_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_committee_division_chief (committee_id, user_id),
    UNIQUE KEY uq_committee_one_division_chief (committee_id),
    CONSTRAINT fk_committee_division_chiefs_committee FOREIGN KEY (committee_id) REFERENCES committees(id) ON DELETE CASCADE,
    CONSTRAINT fk_committee_division_chiefs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_committee_division_chiefs_assigned_by FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE division_chief_secretariats (
    id INT AUTO_INCREMENT PRIMARY KEY,
    division_chief_user_id INT NOT NULL,
    secretariat_user_id INT NOT NULL,
    assigned_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_division_chief_secretariat (division_chief_user_id, secretariat_user_id),
    CONSTRAINT fk_division_chief_secretariats_chief FOREIGN KEY (division_chief_user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_division_chief_secretariats_secretariat FOREIGN KEY (secretariat_user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_division_chief_secretariats_assigned_by FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE records (
    id INT AUTO_INCREMENT PRIMARY KEY,
    control_number VARCHAR(60) NOT NULL,
    title VARCHAR(1000) NOT NULL,
    plenary_print_title VARCHAR(1000) NULL,
    document_type VARCHAR(80) NOT NULL,
    origin VARCHAR(180) NOT NULL,
    client_name VARCHAR(180) NULL,
    contact_number VARCHAR(80) NULL,
    client_email VARCHAR(180) NULL,
    committee_id INT NULL,
    assigned_user_id INT NULL,
    receiving_clerk_id INT NULL,
    priority ENUM('Low', 'Normal', 'High', 'Urgent') NOT NULL DEFAULT 'Normal',
    status ENUM('Received', 'Assigned to the Committee', 'Pending to the Committee', 'For Meeting', 'For Inspection', 'Recommending Approval', 'Deferred', 'Tabled', 'Noted', 'Referred To', 'Referred Back to Committee', 'Perusal', 'Endorsement', 'For Plenary Session', 'Scheduled for Plenary', 'Disapproved', 'Approved in the Plenary', 'For Vice Mayor''s Signature', 'Returned from The Vice Mayor', 'Forwarded for Admin/Mayor Signature', 'Returned from Admin/Mayor', 'Veto', 'Lapse into Ordinance', 'Forwarded to the Messengerial Services', 'For Transmittal', 'Others', 'Completed', 'Archived') NOT NULL DEFAULT 'Received',
    received_date DATE NOT NULL,
    due_date DATE NULL,
    current_location VARCHAR(180) NOT NULL DEFAULT 'Legislative Committees Division',
    remarks TEXT NULL,
    proposed_by_city_council_member TINYINT(1) NOT NULL DEFAULT 0,
    proposed_ordinance_number VARCHAR(80) NULL,
    proposed_resolution_number VARCHAR(80) NULL,
    approved_ordinance_number VARCHAR(80) NULL,
    approved_resolution_number VARCHAR(80) NULL,
    plenary_session_date DATE NULL,
    plenary_approved_date DATE NULL,
    created_by INT NULL,
    updated_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_records_control_number (control_number),
    CONSTRAINT fk_records_committee FOREIGN KEY (committee_id) REFERENCES committees(id) ON DELETE SET NULL,
    CONSTRAINT fk_records_assigned_user FOREIGN KEY (assigned_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_records_receiving_clerk FOREIGN KEY (receiving_clerk_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_records_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_records_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE record_committees (
    id INT AUTO_INCREMENT PRIMARY KEY,
    record_id INT NOT NULL,
    committee_id INT NOT NULL,
    sequence_no INT NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_record_committee (record_id, committee_id),
    KEY idx_record_committees_record (record_id),
    KEY idx_record_committees_committee (committee_id),
    CONSTRAINT fk_record_committees_record FOREIGN KEY (record_id) REFERENCES records(id) ON DELETE CASCADE,
    CONSTRAINT fk_record_committees_committee FOREIGN KEY (committee_id) REFERENCES committees(id) ON DELETE CASCADE
);

CREATE TABLE division_chief_notes (
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
);

CREATE TABLE record_movements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    record_id INT NOT NULL,
    from_status VARCHAR(80) NULL,
    to_status VARCHAR(80) NOT NULL,
    from_location VARCHAR(180) NULL,
    to_location VARCHAR(180) NOT NULL,
    notes TEXT NULL,
    report_title VARCHAR(1000) NULL,
    record_title VARCHAR(1000) NULL,
    previous_title VARCHAR(1000) NULL,
    report_remarks TEXT NULL,
    chief_remarks TEXT NULL,
    chief_remarks_updated_at DATETIME NULL,
    updated_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_movements_record FOREIGN KEY (record_id) REFERENCES records(id) ON DELETE CASCADE,
    CONSTRAINT fk_movements_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE record_attachments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    record_id INT NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    title VARCHAR(255) NULL,
    stored_name VARCHAR(255) NOT NULL,
    mime_type VARCHAR(120) NOT NULL,
    file_size INT NOT NULL,
    uploaded_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_record_attachments_record FOREIGN KEY (record_id) REFERENCES records(id) ON DELETE CASCADE,
    CONSTRAINT fk_record_attachments_user FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE record_division_receipts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    record_id INT NOT NULL,
    division_name VARCHAR(160) NOT NULL,
    received_by INT NULL,
    received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_record_division_receipt (record_id, division_name),
    KEY idx_division_receipts_division (division_name, received_at),
    CONSTRAINT fk_division_receipts_record FOREIGN KEY (record_id) REFERENCES records(id) ON DELETE CASCADE,
    CONSTRAINT fk_division_receipts_user FOREIGN KEY (received_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE record_recipients (
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
);

CREATE TABLE committee_report_numbers (
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
);

INSERT INTO committees (name, chairperson, description) VALUES
('Rules and Privileges', 'Committee Chair', 'Handles rules, privileges, and committee procedures.'),
('Appropriations', 'Committee Chair', 'Handles budgetary and appropriation matters.'),
('Health and Social Services', 'Committee Chair', 'Handles health, welfare, and social service referrals.'),
('Public Works and Infrastructure', 'Committee Chair', 'Handles infrastructure-related records and endorsements.');

INSERT INTO committee_terms (name, start_year, end_year, is_current) VALUES
('2026-2029 Term', 2026, 2029, 1);

INSERT INTO committee_members (committee_id, term_id, name, position, sort_order) VALUES
(1, 1, 'Committee Chair', 'Chairperson', 1),
(1, 1, 'Vice Chairperson', 'Vice Chairperson', 2),
(2, 1, 'Committee Chair', 'Chairperson', 1),
(2, 1, 'Vice Chairperson', 'Vice Chairperson', 2),
(3, 1, 'Committee Chair', 'Chairperson', 1),
(3, 1, 'Vice Chairperson', 'Vice Chairperson', 2),
(4, 1, 'Committee Chair', 'Chairperson', 1),
(4, 1, 'Vice Chairperson', 'Vice Chairperson', 2);

INSERT INTO city_officials (term_id, name, position, sort_order) VALUES
(1, 'Vice Mayor', 'Vice Mayor', 1),
(1, 'City Councilor 1', 'City Councilor', 2),
(1, 'City Councilor 2', 'City Councilor', 3),
(1, 'City Councilor 3', 'City Councilor', 4);

INSERT INTO users (name, email, password_hash, role) VALUES
('Administrator', 'administrator@example.com', '$2y$10$vmyX5Wo63HwMpU33O2653uZm0dy4fQrainq4l4vif5HK59QvinrmK', 'admin'),
('City Secretary', 'admin@example.com', '$2y$10$vmyX5Wo63HwMpU33O2653uZm0dy4fQrainq4l4vif5HK59QvinrmK', 'city_secretary'),
('Division Chief', 'chief@example.com', '$2y$10$vmyX5Wo63HwMpU33O2653uZm0dy4fQrainq4l4vif5HK59QvinrmK', 'division_chief'),
('Admin Receiving Section', 'receiving@example.com', '$2y$10$vmyX5Wo63HwMpU33O2653uZm0dy4fQrainq4l4vif5HK59QvinrmK', 'receiving_clerk'),
('Committee Secretariat', 'secretariat@example.com', '$2y$10$vmyX5Wo63HwMpU33O2653uZm0dy4fQrainq4l4vif5HK59QvinrmK', 'secretariat');

INSERT INTO committee_secretariats (committee_id, user_id, assigned_by) VALUES
(1, 5, 3),
(2, 5, 3),
(3, 5, 3),
(4, 5, 3);

INSERT INTO committee_division_chiefs (committee_id, user_id, assigned_by) VALUES
(1, 3, 2),
(2, 3, 2),
(3, 3, 2),
(4, 3, 2);

INSERT INTO division_chief_secretariats (division_chief_user_id, secretariat_user_id, assigned_by) VALUES
(3, 5, 2);

INSERT INTO records (
    control_number, title, document_type, origin, client_name, committee_id, assigned_user_id, receiving_clerk_id,
    priority, status, received_date, due_date, current_location, remarks, created_by
) VALUES
('L-00001-2026', 'Sample Committee Referral on Local Infrastructure', 'Committee Referrals', '', 'Sample Client', 4, 5, 4, 'High', 'Pending to the Committee', CURRENT_DATE, DATE_ADD(CURRENT_DATE, INTERVAL 7 DAY), 'Records Receiving Desk', 'Seed record for testing.', 1);

INSERT INTO record_movements (record_id, from_status, to_status, from_location, to_location, notes, updated_by)
VALUES (1, NULL, 'Pending to the Committee', NULL, 'Records Receiving Desk', 'Initial record received and encoded.', 1);
