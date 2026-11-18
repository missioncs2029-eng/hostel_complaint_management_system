# HavenHostel — Handover & "MySQL will not start" guide

Read this first if you received this project from someone else.

## What was actually wrong

XAMPP's log said:

    Port 3306 in use by "Unable to open process"!
    MySQL WILL NOT start without the configured ports free!
    Error: MySQL shutdown unexpectedly.

**This is not a bug in the SQL file or the PHP code.** Another program on the PC
(usually a Windows "MySQL" service, another MariaDB, or an older XAMPP) is already
holding port 3306, so XAMPP's MySQL cannot start. "Unable to open process" means that
program belongs to Windows or another user, so XAMPP is not allowed to look at it.

## The 2-minute fix (no typing)

1. Extract the ZIP. You get a folder named `hostel-management-system`.
2. Double-click **`fix-mysql.bat`** and click **Yes** on the Windows prompt.
3. Choose **1** (move XAMPP MySQL to port 3307). It edits XAMPP's `my.ini` and phpMyAdmin
   for you and keeps a backup of the original file.
4. Double-click **`setup.bat`**. In XAMPP click **Start** on Apache and MySQL. Both turn green.
5. The browser opens `setup.php`, which creates the database and demo accounts.
6. Click **Open HavenHostel**.

The app finds the new port by itself (it tries 3306, 3307, 3308 and 3309).

Optional: in XAMPP open **Config → Service and Port Settings** and set the MySQL port to 3307.
This only hides the red warning in the control panel.

## If MySQL still stops right after "running"

Run `fix-mysql.bat` again and choose **3** (rebuilds XAMPP's MySQL data folder; your old
data is kept in a `data_old_...` folder). Then repeat the 2-minute fix.

## Manual alternatives

* **Stop the blocking program:** press Win+R, type `services.msc`, find a service named
  *MySQL* / *MySQL80* / *MariaDB*, right-click → Stop (and set Startup type to Manual).
* **Import the SQL by hand** (only if `setup.php` cannot be used):
  open `http://localhost/phpmyadmin` →
  **Import** → choose `database/hostel_management.sql` → **Go**. That one file creates the
  database, all tables and the demo accounts. It is safe to run twice.

## Checking everything

Open `http://localhost/hostel-management-system/health.php`. It shows which MySQL ports are
open, which connection was used, and PASS/FAIL for each requirement.

## Demo logins

| Role | Email | Password |
|---|---|---|
| Admin | admin@haven.local | Admin@12345 |
| Resident | student@haven.local | Student@12345 |

Change these before using real data.

## XAMPP in a different folder

`setup.bat` and `run.bat` now search C:, D:, E:, F:, G: and H: for `xampp` / `xamapp`
folders and remember what they found in `xampp-path.txt`. If yours is somewhere else they ask once.
