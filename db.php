<?php
// HavenHostel — shared database connection helper (used by config.php, setup.php, health.php).
// Connection settings are tried in this order:
//   1. Environment variables HMS_DB_HOST / HMS_DB_PORT / HMS_DB_USER / HMS_DB_PASS
//   2. config.local.php (written automatically by setup.php after a successful install)
//   3. Common XAMPP defaults: root with blank password on 3306 and 3307
//   4. The legacy "hostel_app" account on 3307 and 3306

const HMS_DB_NAME = 'hostel_management';
// Ports probed when no explicit config exists. 3306 = XAMPP default; 3307+ = used when 3306 is blocked.
const HMS_DB_PORTS = [3306, 3307, 3308, 3309];

/** Human-readable list of every connection attempt made in this request (for diagnostics). */
function hms_attempts(?array $add = null): array
{
    static $log = [];
    if ($add !== null) {
        $log[] = $add;
    }
    return $log;
}

function hms_db_candidates(): array
{
    $list = [];

    $envHost = getenv('HMS_DB_HOST');
    $envPort = getenv('HMS_DB_PORT');
    $envUser = getenv('HMS_DB_USER');
    $envPass = getenv('HMS_DB_PASS');
    if ($envHost !== false || $envPort !== false || $envUser !== false || $envPass !== false) {
        $list[] = [
            'host' => $envHost !== false && $envHost !== '' ? $envHost : '127.0.0.1',
            'port' => $envPort !== false && $envPort !== '' ? (int)$envPort : 3306,
            'user' => $envUser !== false && $envUser !== '' ? $envUser : 'root',
            'pass' => $envPass !== false ? $envPass : '',
        ];
    }

    $local = __DIR__ . '/config.local.php';
    if (is_file($local)) {
        $cfg = include $local;
        if (is_array($cfg) && isset($cfg['user'])) {
            $list[] = [
                'host' => (string)($cfg['host'] ?? '127.0.0.1'),
                'port' => (int)($cfg['port'] ?? 3306),
                'user' => (string)$cfg['user'],
                'pass' => (string)($cfg['pass'] ?? ''),
            ];
        }
    }

    foreach (HMS_DB_PORTS as $port) {
        $list[] = ['host' => '127.0.0.1', 'port' => $port, 'user' => 'root', 'pass' => ''];
    }
    foreach (array_reverse(HMS_DB_PORTS) as $port) {
        $list[] = ['host' => '127.0.0.1', 'port' => $port, 'user' => 'hostel_app', 'pass' => 'HavenLocal_2026!'];
    }

    return $list;
}

function hms_pdo_options(): array
{
    return [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_TIMEOUT            => 3,
    ];
}

/**
 * Connect to the first working candidate.
 * $withDatabase = true  -> connect to the hostel_management database (normal app use)
 * $withDatabase = false -> connect to the server only (used by the installer)
 * Returns [PDO|null, candidateUsed|null, lastError|null, lastErrorCode|null]
 */
function hms_connect(bool $withDatabase = true): array
{
    $lastError = null;
    $lastCode  = null;

    foreach (hms_db_candidates() as $c) {
        try {
            $dsn = "mysql:host={$c['host']};port={$c['port']};charset=utf8mb4";
            if ($withDatabase) {
                $dsn .= ';dbname=' . HMS_DB_NAME;
            }
            $pdo = new PDO($dsn, $c['user'], $c['pass'], hms_pdo_options());
            $pdo->exec("SET time_zone = '+05:30'");
            return [$pdo, $c, null, null];
        } catch (PDOException $e) {
            $lastError = $e->getMessage();
            $lastCode  = (int)($e->errorInfo[1] ?? 0);
            hms_attempts([$c['user'] . '@' . $c['host'] . ':' . $c['port'], $lastError]);
            // 1049 = unknown database (server reachable, credentials fine, installer not run yet).
            if ($withDatabase && $lastCode === 1049) {
                return [null, $c, $lastError, 1049];
            }
        }
    }

    return [null, null, $lastError, $lastCode];
}
