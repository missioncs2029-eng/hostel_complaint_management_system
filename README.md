# HavenHostel — Hostel Management System (Product 20)

A polished, responsive hostel portal built using **PHP 8+, MySQL/MariaDB, HTML, CSS and JavaScript**. It follows the supplied Placement Assistant project's local XAMPP/PHP/MySQL packaging approach, including a database schema, secure session login, role checks, CSRF protection, prepared SQL statements, an installer, smoke checks and a one-click Windows launcher.

> **Tested:** the full workflow (register, login, room request/approval, complaints, payments, visitors, notices, access control) was exercised end-to-end against MariaDB before packaging. Use the acceptance test below after setup on your machine. Payment records are a tracking/demo workflow only; no real money is collected or processed.

## Features

### Resident / user
- Register and sign in with hashed passwords.
- Resident dashboard with room, maintenance, payment and visitor summaries.
- Browse rooms with occupancy and monthly rates; request an allocation.
- Submit maintenance complaints with category and priority; track status and admin notes.
- Submit monthly payment records and transaction references; view admin verification status.
- Request visitor passes and track approval/check-in status.
- Read hostel notices.

### Hostel administrator
- Dedicated admin dashboard and role-protected administration pages.
- Add rooms with block, floor, room type, capacity, monthly fee and availability.
- Review and approve/reject/end room allocations. Approval checks room capacity under a database transaction.
- Triage maintenance tickets and add resident-visible notes.
- Verify payment records as pending/paid/rejected.
- Approve/reject visitor passes and track check-in/check-out.
- Publish announcements to the notice board.

### Technical details
- MySQL/MariaDB schema with foreign keys, indexes and unique constraints.
- PDO prepared statements; `password_hash()` / `password_verify()`.
- Session cookie flags, session ID regeneration on login, CSRF tokens on all POST actions, output escaping and role-based access control.
- Responsive UI, animated cards, CSS illustrations, mobile sidebar and reduced-motion support.

## Requirements
- Windows 10/11 (or any OS with Apache/Nginx + PHP)
- XAMPP with Apache, PHP 8.0+ and MySQL/MariaDB
- A modern browser

**Database connection is automatic.** The app and installer try, in order: the `HMS_DB_HOST/PORT/USER/PASS` environment variables, `config.local.php` (created by `setup.php`), XAMPP's default `root` with a blank password on ports 3306, 3307, 3308 and 3309, then the older `hostel_app` account. Whichever works first is used, so you normally never edit any file. To force specific credentials, create `config.local.php` next to `config.php`:

```php
<?php
return ['host' => '127.0.0.1', 'port' => 3306, 'user' => 'root', 'pass' => '', 'debug' => false];
```

## Quick install — minimal typing

1. Extract the ZIP.
2. Copy the **`hostel-management-system`** folder into `C:\xampp\htdocs\` so this file exists: `C:\xampp\htdocs\hostel-management-system\index.php`.
3. If MySQL will not start (port 3306 in use), first double-click **`fix-mysql.bat`**. Then double-click **`setup.bat`**. It opens the XAMPP Control Panel and the browser installer. In XAMPP, click **Start** for both Apache and MySQL and wait until they are running.
4. The installer at `http://localhost/hostel-management-system/setup.php` finds your MySQL port/credentials automatically, creates the `hostel_management` database/tables and seeds demo accounts/rooms.
5. Click **Open HavenHostel**. Future launches: double-click `run.bat` or open `http://localhost/hostel-management-system/`.

### Demo logins

| Role | Email | Password |
|---|---|---|
| Admin | `admin@haven.local` | `Admin@12345` |
| Resident | `student@haven.local` | `Student@12345` |

These credentials are only for local evaluation. Change the demo passwords and remove demo accounts before any real deployment. **Do not expose this local demo publicly as-is.**

## Manual installation

1. Start Apache and MySQL in XAMPP.
2. Visit `http://localhost/hostel-management-system/setup.php`.
3. After setup completes, use `http://localhost/hostel-management-system/`.

The installer can be re-opened to seed any missing demo account/rooms, but it does not drop existing tables or erase your data. The schema uses `CREATE TABLE IF NOT EXISTS`.

## Suggested acceptance test

1. Sign in as resident; open Rooms & allocation and request an available room.
2. Sign out; sign in as admin and open Allocation requests. Approve the resident request.
3. Sign back in as resident; verify the allocation appears with `active` status.
4. Submit a maintenance complaint; update its status and admin note from the admin account; confirm the resident can see the update.
5. Submit a payment record; verify it as Paid in the admin account; confirm the resident sees Paid. This is a database record only, not payment processing.
6. Submit a visitor request; approve it as admin and check the status as resident.
7. Publish a notice as admin and confirm it appears on the resident Notice board.
8. Try opening an `admin-*` URL as a resident; access should be denied/redirected.
9. Register two users with the same email or student ID; the duplicate should be rejected with a friendly message.

## Troubleshooting

**MySQL will not start / "Port 3306 in use" / "MySQL shutdown unexpectedly":** double-click `fix-mysql.bat` (details in `HANDOVER.md`). The SQL files are not the cause.

First open `http://localhost/hostel-management-system/health.php`. It prints PASS/FAIL for PHP, the MySQL driver, the server connection, the database and the tables.

- **"Database is not ready":** start MySQL in the XAMPP Control Panel (green), then open `setup.php`.
- **"Database not installed yet":** MySQL works but the installer has not run. Open `setup.php` once.
- **404 Not Found:** the folder must be named `hostel-management-system` inside `C:\xampp\htdocs\`.
- **Apache port conflict:** resolve it in the XAMPP Control Panel; don't change application URLs.
- **Setup says access denied:** your MySQL root has a password. Create `config.local.php` (see Requirements) with that password, or set `HMS_DB_PASS`.
- **Blank page / "Something went wrong":** the error is in the Apache error log (XAMPP > Apache > Logs). Set `'debug' => true` in `config.local.php` to show details on screen while developing.
- **Unstyled page:** confirm `assets/app.css` and `assets/app.js` exist and refresh with Ctrl+F5.
- **XAMPP installed elsewhere:** `setup.bat` / `run.bat` search drives C: to H: automatically and ask once if they cannot find it.
- **"Your session has expired":** you were signed in to an account that no longer exists in the database (for example after reinstalling). Sign in again.

## Important security and production notes

This is a local capstone/demo build, not a production-hosted residence system. Before using real resident records, add HTTPS, non-root DB credentials, rate limiting, password reset/email verification, audit review, database backups, stronger admin provisioning, privacy/retention policies, and proper payment gateway integration if online payments are required. Delete or restrict `setup.php` and `health.php` after installation on any non-local deployment. The bundled `.htaccess` blocks direct web access to `config.php`, `config.local.php`, `db.php`, `.sql` and `.bat` files on Apache. Don't upload real personal information to a public demo.
"# hostel_complaint_management_system" 
