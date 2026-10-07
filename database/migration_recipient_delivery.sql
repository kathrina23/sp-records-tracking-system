ALTER TABLE record_recipients ADD COLUMN IF NOT EXISTS delivered_at DATETIME NULL;
ALTER TABLE record_recipients ADD COLUMN IF NOT EXISTS delivered_by INT NULL;
