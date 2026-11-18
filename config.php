<?php
// HavenHostel — local XAMPP configuration and shared helpers.

const APP_NAME = 'HavenHostel';

date_default_timezone_set('Asia/Kolkata');

require_once __DIR__ . '/db.php';

// Optional debug switch: create config.local.php returning [... 'debug' => true]
$hmsLocal = is_file(__DIR__ . '/config.local.php') ? include __DIR__ . '/config.local.php' : [];
define('HMS_DEBUG', is_array($hmsLocal) && !empty($hmsLocal['debug']));
ini_set('display_errors', HMS_DEBUG ? '1' : '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

// Small polyfill for very old PHP builds.
if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool
    {
        return $needle === '' || strpos($haystack, $needle) === 0;
    }
}

set_exception_handler(function (Throwable $e) {
    error_log('[HavenHostel] Uncaught ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
    }
    echo '<h2>Something went wrong</h2><p>The error was logged. Check the Apache/PHP error log (XAMPP &rarr; Apache &rarr; Logs).</p>';
    if (HMS_DEBUG) {
        echo '<pre>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</pre>';
    }
    exit;
});

$secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params([
        'httponly' => true,
        'secure'   => $secure,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// Database connection (auto-detects XAMPP port/credentials, see db.php).
[$pdo, $hmsConn, $hmsDbError, $hmsDbCode] = hms_connect(true);

if (!$pdo) {
    error_log('HavenHostel database connection failed: ' . (string)$hmsDbError);
    http_response_code(503);
    if ($hmsDbCode === 1049) {
        exit(
            '<h2>Database not installed yet</h2>' .
            '<p>MySQL is running, but the <b>hostel_management</b> database does not exist. ' .
            'Open <a href="setup.php">setup.php</a> once to create it.</p>'
        );
    }
    exit(
        '<h2>Database is not ready</h2>' .
        '<p>Start <b>MySQL</b> in the XAMPP Control Panel, then open <a href="setup.php">setup.php</a>. ' .
        'You can also open <a href="health.php">health.php</a> for a diagnosis.</p>'
    );
}

function e($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function text_len($value)
{
    return function_exists('mb_strlen')
        ? mb_strlen((string)$value, 'UTF-8')
        : strlen((string)$value);
}

function text_clip($value, $start, $width, $suffix = '…')
{
    $value = (string)$value;

    if (function_exists('mb_strimwidth')) {
        return mb_strimwidth($value, $start, $width, $suffix, 'UTF-8');
    }

    return strlen($value) > $width
        ? substr($value, $start, max(0, $width - strlen($suffix))) . $suffix
        : $value;
}

function csrf()
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf'];
}

function verify_csrf()
{
    if (!hash_equals((string)($_SESSION['csrf'] ?? ''), (string)($_POST['csrf'] ?? ''))) {
        http_response_code(419);
        exit('Security token expired. <a href="index.php">Go back</a>, refresh the page and try again.');
    }
}

function me()
{
    return $_SESSION['user'] ?? null;
}

function require_auth()
{
    if (!me()) {
        header('Location: index.php?page=login');
        exit;
    }
}

function require_admin()
{
    if (!me() || (me()['role'] ?? '') !== 'admin') {
        http_response_code(403);
        exit('Administrator access required.');
    }
}

function flash($message, $type = 'success')
{
    $_SESSION['flash'] = ['message' => $message, 'type' => $type];
}

function take_flash()
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $flash;
}

function redirect($page = 'dashboard')
{
    header('Location: index.php?page=' . rawurlencode($page));
    exit;
}

function log_activity(PDO $pdo, $uid, $action, $details = '')
{
    try {
        $stmt = $pdo->prepare('INSERT INTO activity_logs (user_id, action, details) VALUES (?, ?, ?)');
        $stmt->execute([$uid, $action, $details]);
    } catch (Throwable $e) {
        // Activity logging must never break the user's action.
        error_log('[HavenHostel] log_activity failed: ' . $e->getMessage());
    }
}
