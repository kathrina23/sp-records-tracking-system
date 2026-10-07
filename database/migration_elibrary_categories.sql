CREATE TABLE IF NOT EXISTS legislation_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    kind ENUM('ordinance','resolution') NOT NULL,
    name VARCHAR(255) NOT NULL,
    UNIQUE KEY legislation_category_kind_name (kind, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO legislation_categories (kind, name)
SELECT kind, TRIM(category) FROM legislation_drafts WHERE TRIM(category) <> ''
UNION SELECT kind, TRIM(category) FROM legislation_publications WHERE TRIM(category) <> '';
