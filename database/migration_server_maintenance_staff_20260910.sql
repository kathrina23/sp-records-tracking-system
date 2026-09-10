-- Add the role without changing existing accounts or their permissions.
ALTER TABLE users MODIFY role ENUM('admin', 'city_secretary', 'division_chief', 'receiving_clerk', 'secretariat', 'division_staff', 'administrative_support', 'others', 'records_officer', 'staff', 'server_maintenance_staff') NOT NULL DEFAULT 'secretariat';
