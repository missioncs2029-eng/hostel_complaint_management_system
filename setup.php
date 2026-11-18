<?php
// One-time local installer. Creates the database, tables and demo accounts.
// Safe to re-run: it never drops tables or erases data.
date_default_timezone_set('Asia/Kolkata');
require_once __DIR__ . '/db.php';

$ok = false;
$msg = '';
$notes = [];

try {
    [$pdo, $conn, $err] = hms_connect(false);
    if (!$pdo) {
        throw new RuntimeException(
            'Could not connect to MySQL/MariaDB. Start MySQL in the XAMPP Control Panel and retry. ' .
            'Last error: ' . (string)$err
        );
    }

    $db = HMS_DB_NAME;
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `$db`");

    // schema.sql contains plain CREATE TABLE statements (no semicolons inside values).
    $sql = file_get_contents(__DIR__ . '/database/schema.sql');
    if ($sql === false) {
        throw new RuntimeException('database/schema.sql is missing.');
    }
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
        $pdo->exec($statement);
    }

    // Demo accounts (existing ones are left untouched).
    $s = $pdo->prepare(
        'INSERT INTO users (full_name, email, password_hash, role, phone) VALUES (?,?,?,?,?)
         ON DUPLICATE KEY UPDATE email = email'
    );
    $s->execute(['Hostel Administrator', 'admin@haven.local', password_hash('Admin@12345', PASSWORD_DEFAULT), 'admin', '9999999999']);

    $s = $pdo->prepare(
        'INSERT INTO users (full_name, email, password_hash, role, phone, student_id, course, year_of_study) VALUES (?,?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE email = email'
    );
    $s->execute(['Demo Student', 'student@haven.local', password_hash('Student@12345', PASSWORD_DEFAULT), 'user', '9000000001', 'HMS2026001', 'B.Tech CSE', 2]);

    // Demo rooms (room_number is UNIQUE, so re-running adds nothing new).
    $pdo->exec(
        "INSERT IGNORE INTO rooms (room_number, block_name, floor_number, room_type, capacity, monthly_fee, status) VALUES
         ('A-101','A Block',1,'Double Sharing',2,6500,'available'),
         ('A-102','A Block',1,'Triple Sharing',3,5200,'available'),
         ('B-201','B Block',2,'Single',1,9000,'available'),
         ('B-202','B Block',2,'Double Sharing',2,6800,'available'),
         ('C-101','C Block',1,'Triple Sharing',3,4800,'available')"
    );

    // Welcome notice: only when the notice board is empty, linked to the real admin id.
    if ((int)$pdo->query('SELECT COUNT(*) FROM notices')->fetchColumn() === 0) {
        $adminId = $pdo->query("SELECT id FROM users WHERE email='admin@haven.local' LIMIT 1")->fetchColumn();
        $s = $pdo->prepare('INSERT INTO notices (title, body, created_by) VALUES (?,?,?)');
        $s->execute([
            'Welcome to HavenHostel',
            'Your digital hostel desk is ready. Submit maintenance requests and track updates from your dashboard.',
            $adminId ?: null,
        ]);
    }

    // Remember the working connection so the app and installer always agree.
    $localFile = __DIR__ . '/config.local.php';
    if (!is_file($localFile)) {
        $cfg = [
            'host'  => $conn['host'],
            'port'  => $conn['port'],
            'user'  => $conn['user'],
            'pass'  => $conn['pass'],
            'debug' => false,
        ];
        $written = @file_put_contents($localFile, "<?php\nreturn " . var_export($cfg, true) . ";\n", LOCK_EX);
        if ($written === false) {
            $notes[] = 'Could not save config.local.php (folder not writable). This is fine: the app auto-detects the same connection.';
        }
    }

    $notes[] = 'Connected as ' . $conn['user'] . ' on port ' . $conn['port'] . '.';
    $ok = true;
    $msg = 'Installation completed. Database and demo accounts are ready.';
} catch (Throwable $e) {
    error_log('[HavenHostel setup] ' . $e->getMessage());
    $msg = 'Setup failed: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
}
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>HavenHostel Setup</title><link rel="stylesheet" href="assets/app.css"></head>
<body class="setup-page"><main class="setup-card">
<div class="brand-mark">H</div>
<p class="eyebrow">HOSTEL MANAGEMENT SYSTEM</p>
<h1><?= $ok ? 'You’re all set.' : 'Setup needs attention.' ?></h1>
<p><?= $msg ?></p>
<?php if ($ok): ?>
<div class="credential"><b>Administrator</b><span>admin@haven.local</span><code>Admin@12345</code></div>
<div class="credential"><b>Demo student</b><span>student@haven.local</span><code>Student@12345</code></div>
<?php foreach ($notes as $n): ?><p class="muted small"><?= htmlspecialchars($n, ENT_QUOTES, 'UTF-8') ?></p><?php endforeach; ?>
<p class="notice">For local demonstration only. Change demo passwords before using real student data.</p>
<a class="btn btn-primary btn-wide" href="index.php">Open HavenHostel <span>↗</span></a>
<?php else: ?>
<p>Confirm Apache and MySQL are both running (green) in the XAMPP Control Panel, then retry. If MySQL will not start (usually "Port 3306 in use"), close this page and double-click <b>fix-mysql.bat</b> in the project folder. You can also open <a href="health.php"><b>health.php</b></a> for a diagnosis.</p>
<?php foreach (hms_attempts() as $a): ?><p class="muted small"><?= htmlspecialchars($a[0] . ' &rarr; ' . $a[1], ENT_QUOTES, 'UTF-8') ?></p><?php endforeach; ?>
<a class="btn btn-primary" href="setup.php">Retry setup</a>
<?php endif; ?>
</main></body></html>
