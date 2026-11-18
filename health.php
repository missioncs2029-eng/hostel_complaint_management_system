<?php
// Plain-text diagnostics: PHP, extensions, database connection and tables.
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/db.php';

$checks = [];
$checks['PHP version >= 8.0 (' . PHP_VERSION . ')'] = version_compare(PHP_VERSION, '8.0.0', '>=');
$checks['PDO extension'] = extension_loaded('pdo');
$checks['PDO MySQL driver'] = extension_loaded('pdo_mysql');
$optional = ['mbstring extension (optional, recommended)' => extension_loaded('mbstring')];

$conn = null;
$pdo = null;
$checks['MySQL server reachable'] = false;
$checks['Database "' . HMS_DB_NAME . '" exists'] = false;
$checks['All required tables present'] = false;
$checks['Admin account present'] = false;

if (extension_loaded('pdo_mysql')) {
    [$srv, $c] = hms_connect(false);
    if ($srv) {
        $checks['MySQL server reachable'] = true;
        $conn = $c;
        [$pdo] = hms_connect(true);
        if ($pdo) {
            $checks['Database "' . HMS_DB_NAME . '" exists'] = true;
            $need = ['users', 'rooms', 'allocations', 'complaints', 'payments', 'notices', 'visitors', 'activity_logs'];
            $found = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
            $checks['All required tables present'] = count(array_diff($need, $found)) === 0;
            if ($checks['All required tables present']) {
                $checks['Admin account present'] = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn() > 0;
            }
        }
    }
}

echo 'MySQL port scan (127.0.0.1):' . PHP_EOL;
foreach (HMS_DB_PORTS as $port) {
    $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 1);
    echo '  port ' . $port . ': ' . ($fp ? 'something is listening' : 'closed') . PHP_EOL;
    if ($fp) { fclose($fp); }
}
echo PHP_EOL;

foreach ($checks as $name => $ok) {
    echo ($ok ? 'PASS' : 'FAIL') . ' - ' . $name . PHP_EOL;
}
foreach ($optional as $name => $ok) {
    echo ($ok ? 'PASS' : 'WARN') . ' - ' . $name . PHP_EOL;
}
if ($conn) {
    echo PHP_EOL . 'Connection in use: ' . $conn['user'] . '@' . $conn['host'] . ':' . $conn['port'] . PHP_EOL;
}
if (!$checks['MySQL server reachable']) {
    echo PHP_EOL . 'MySQL attempts:' . PHP_EOL;
    foreach (hms_attempts() as $a) { echo '  ' . $a[0] . ' -> ' . $a[1] . PHP_EOL; }
    echo PHP_EOL . 'FIX: if XAMPP says "Port 3306 in use" or MySQL stops right after starting, run fix-mysql.bat (see HANDOVER.md).' . PHP_EOL;
}
echo PHP_EOL . (in_array(false, $checks, true) ? 'HEALTH CHECK: REVIEW FAILURES (run setup.php if the database or tables are missing)' : 'HEALTH CHECK: PASS') . PHP_EOL;
