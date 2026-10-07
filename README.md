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
- LMIS Data Entry Staff can prepare and review signed ordinances and resolutions before posting to the public E-Library.
- Public Search Legislation searches posted titles, numbers, keywords, categories, authors, co-authors, and folder codes.

## E-Library Setup and Use

Run `php scripts/migrate_legislation_amendments.php` on existing installations to enable amendment tagging. In Ordinances & Resolutions and Post on E-Library, use **Amendment of** to search and select existing published legislation. Tags are saved with the draft and become public after review and confirmation. Each document retains its own details and copy. Open a public legislation entry's **Amendments** tab to follow links to the legislation it amends or to published amendments of that entry. Edit the entry and clear a selection, then review and repost to remove a tag.

LMIS Data Entry Staff can choose **Bulk Import from Excel** in Ordinances & Resolutions. Upload an `.xlsx` workbook or UTF-8 CSV (up to 5 MB and 1,000 rows), select a term, preview the entries and confirm the import. The first visible worksheet must have headers in row 1: Type, Number, Title / Subject and Date Approved. Optional columns are Keywords, Category, Author, Co Author and Folder Code. Download the Excel-ready CSV template from the import page. Dates use MM/DD/YYYY (for example, 08/17/2026) and also accept Excel date cells; numbers should be stored as text to preserve leading zeros. Paste formula results as values before uploading. Older `.xls` files must be saved as `.xlsx` first. The importer uses Python's standard library; set `SP_RECORDS_PYTHON` on deployed servers as for PDF reports.

Each imported row becomes a private legislation draft without a PDF. Blank optional metadata can be completed with **Edit** before posting. Nonempty categories and authors must match saved categories and councilors for the selected term; multiple authors use semicolons. Duplicate type/number/term combinations and invalid rows block the entire import. In **Legislation Entries**, use **Upload PDF** later, then **Review** and **Confirm and Post** to publish the details and copy. Replacing a PDF preserves the currently published copy until confirmation.

Run `php scripts/migrate_historical_legislation.php` to enable **Older Ordinances & Resolutions** for LMIS Data Entry Staff. Choose a term, type, number, approval date, title, keywords, category, and authors; co-authors, committees, Folder Code, and the signed copy are optional. Names come from the selected term's City Councilors, and committees come from its Standing Committee memberships. Save, review, and confirm posting. Historical entries stay separate from tracked records, support editing and reposting, and appear in public search and PDF reports.

Advanced Filters → Download Report generates a landscape PDF of all matching public entries with the SP logo, selected term, and legislation details. PDF reporting requires Python with `reportlab`; set `SP_RECORDS_PYTHON` to the Python executable on a deployment server. Local startup also detects the installed Codex bundled Python runtime. Councilor names, titles, and other Unicode text use Times New Roman on Windows or DejaVu Serif on Linux.

Run `php scripts/migrate_elibrary_categories.php` to enable category dropdowns (existing draft and publication categories are retained). LMIS Data Entry Staff, Administrators, the City Secretary, and Records Officers can add, edit, and delete separate ordinance and resolution categories in **Management → E-Library Data Entry**, beside User Creation for roles with user management access. Editing renames a dropdown choice within its legislation type; deleting removes that choice after confirmation. Existing drafts and published entries keep their saved category until staff edits and reposts them. Author and Co-Author suggest councilor names from City Officials. Enter keywords separated by commas; each item may be a word or phrase and appears separately in review and public results.

For an existing installation, apply `database/migration_elibrary.sql`, or run:

```bash
php scripts/migrate_elibrary.php
```

In User Creation, create or update an account with the **LMIS Data Entry Staff** role. Its dashboard shows the Approved in the Plenary table with **Post on E-Library** actions. An approved ordinance or resolution number is required to post. If a record has both numbers, each type can be posted separately.

Enter Title / Subject, Keywords, Category, and Author, with optional Co-Author, Folder Code, and a final signed PDF, JPEG, or PNG. The title starts with the approved record title; LMIS Data Entry Staff can edit it for the E-Library without changing the tracked record. Select **Post — Review Data**, check the details and any attached file, and either **Edit / Update Data** or **Confirm and Post**. Entries can be published without a copy; public results show “No copy attached” in that case. Staff can return to the same action to update an entry and review it again. Draft edits leave the public copy unchanged until confirmation.

The public main window includes **Search Legislation**. Only confirmed posts and their signed files are public. Signed files are stored in `storage/elibrary`; include this directory in file backups alongside `storage/attachments`. Uploads allow up to 10 MB per file, subject to PHP's `upload_max_filesize` and `post_max_size` limits.

## Default Login

- Email: `admin@example.com`
- Password: `admin123`

Change this account after installation.

## Updating an Existing Installation

Run `php scripts/migrate_term_months.php` to enable start and end month/year for terms. Edit each existing term in **Terms** to specify its months; existing year-only values are preserved until then. E-Library entry, bulk import and public term filters use the configured month boundaries, including both entire boundary months.

Run `php scripts/migrate_city_official_district.php` to add the City Councilors District field (District 1, District 2, or Ex Officio). Existing officials remain unclassified until edited.

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
