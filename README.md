# Sangguniang Panlungsod Records Tracking System

A PHP 8 + MySQL record tracking system for managing committee documents, referrals, reports, endorsements, and other legislative records.

## Features

- Secure login with role-based access
- Dashboard with status totals and due-soon records
- Add, edit, search, and filter records
- Track current office, assigned staff, priority, due date, and status
- Record movement/history log for every status update
- Committee management
- 3-year committee term management with Chairperson, Vice Chairperson, and up to 20 members per committee
- User management for administrators
- Printable record detail pages
- MySQL schema with seed data

## Default Login

- Email: `admin@example.com`
- Password: `admin123`

Change this account after installation.

## Updating an Existing Installation

City Secretary and Administrator users can open **Edit / Unmerge Records** from a
record's detail page to restore merged records or edit merged attachment titles.
New merges save the original details and attachment ownership. Restoring a merge
preserves its workflow and history and reuses its number only if still available.
Older merges require manually selecting attachments to separate, because the old
merge process deleted the source record without saving a restorable copy.

The merge-history table is created automatically when this feature is used. For
deployments without schema-creation permissions, import
`database/migration_record_merges.sql` before deploying the updated PHP files.

If you already imported the first version of the database, import this file once:

```bash
database/migration_committee_terms.sql
```

It adds term-based committee rosters without deleting existing records.

Then import this file once to add the office roles and Secretariat committee assignments:

```bash
database/migration_roles_assignments.sql
```

Finally, import this file once to add Administrator audit logs and backup access:

```bash
database/migration_audit_backup.sql
```

Import this file once to refine records into Committee Referrals and Transmittals, Letters and Endorsements:

```bash
database/migration_record_types.sql
```

Import this file once to convert old control numbers to the new yearly formats:

```bash
database/migration_control_numbers.sql
```

Import this file once to add the For Printing workflow state:

```bash
database/migration_for_printing.sql
```

Import this file once to replace the old referral statuses with the official Committee Referral statuses:

```bash
database/migration_committee_referral_statuses.sql
```

Import this file once to assign committees to Division Chiefs:

```bash
database/migration_division_chief_committees.sql
```

Import this file once to assign Secretariat accounts under Division Chiefs:

```bash
database/migration_division_chief_secretariats.sql
```

Import this file once to enforce one Division Chief per committee:

```bash
database/migration_one_chief_per_committee.sql
```

Import this file once to add term-based Vice Mayor and City Councilor entries:

```bash
database/migration_city_officials.sql
```

Import this file once to add term officer roles:

```bash
database/migration_term_officers.sql
```

Import this file once to separate elected position from officer role:

```bash
database/migration_officer_role_column.sql
```

Import this file once to add special representative officer roles:

```bash
database/migration_add_special_officer_roles.sql
```

## Installation

1. Create a MySQL database named `lcd_records`.
2. Import `database/schema.sql`.
3. Open `app/config.php` and update the database credentials.
4. Start a PHP server from the project folder:

```bash
php -S localhost:8000 -t public
```

5. Open `http://localhost:8000`.

## Suggested Production Notes

- Use HTTPS.
- Change the default admin password.
- Restrict database permissions to this database only.
- Keep `app/` and `database/` outside the public web root in production.
- Configure regular database backups.
