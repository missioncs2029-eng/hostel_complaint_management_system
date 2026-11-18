<?php
require __DIR__ . '/config.php';

/* ---------- Helpers ---------- */
function count_sql($pdo, $sql, $args = [])
{
    $s = $pdo->prepare($sql);
    $s->execute($args);
    return (int)$s->fetchColumn();
}
function rows($pdo, $sql, $args = [])
{
    $s = $pdo->prepare($sql);
    $s->execute($args);
    return $s->fetchAll();
}
function status_badge($s)
{
    $c = strtolower(str_replace(' ', '-', (string)$s));
    return '<span class="status status-' . e($c) . '">' . e($s) . '</span>';
}
function post_str($key, $max = 0)
{
    $v = $_POST[$key] ?? '';
    $v = is_string($v) ? trim($v) : '';
    return ($max > 0 && text_len($v) > $max) ? (function_exists('mb_substr') ? mb_substr($v, 0, $max, 'UTF-8') : substr($v, 0, $max)) : $v;
}
function post_enum($key, array $allowed, $default = null)
{
    $v = $_POST[$key] ?? '';
    return is_string($v) && in_array($v, $allowed, true) ? $v : $default;
}
function valid_date($d)
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string)$d, $m)) {
        return false;
    }
    return checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
}

/* ---------- Keep the session in sync with the database ---------- */
// Prevents stale sessions (e.g. after the database was re-created) from causing
// foreign-key errors, and always applies the current role/name.
if (me()) {
    $s = $pdo->prepare('SELECT id, full_name, email, role FROM users WHERE id = ? AND is_active = 1');
    $s->execute([(int)(me()['id'] ?? 0)]);
    $fresh = $s->fetch();
    if (!$fresh) {
        unset($_SESSION['user']);
        flash('Your session has expired. Please sign in again.', 'error');
    } else {
        $_SESSION['user'] = [
            'id'        => (int)$fresh['id'],
            'full_name' => $fresh['full_name'],
            'email'     => $fresh['email'],
            'role'      => $fresh['role'],
        ];
    }
}

$currentUser = me() ?? [];
$displayName = trim((string)($currentUser['full_name'] ?? $currentUser['email'] ?? 'Resident'));
if ($displayName === '') {
    $displayName = 'Resident';
}
$page = $_GET['page'] ?? (me() ? 'dashboard' : 'home');
if (!is_string($page)) {
    $page = 'home';
}

/* ---------- Form actions (POST) ---------- */
$COMPLAINT_CATS = ['Maintenance', 'Housekeeping', 'Wi-Fi', 'Electrical', 'Plumbing', 'Food', 'Security', 'Other'];
$PRIORITIES     = ['Low', 'Normal', 'High', 'Urgent'];
$PAY_METHODS    = ['UPI', 'Cash', 'Bank Transfer', 'Card', 'Other'];
$ROOM_TYPES     = ['Single', 'Double Sharing', 'Triple Sharing', 'Dormitory'];
$ROOM_STATUSES  = ['available', 'maintenance', 'inactive'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = post_str('action');
    try {
        if ($action === 'logout') {
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $p = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
            }
            session_destroy();
            header('Location: index.php');
            exit;
        }

        if ($action === 'register') {
            $name     = post_str('full_name');
            $email    = strtolower(post_str('email'));
            $password = (string)($_POST['password'] ?? '');
            $phone    = post_str('phone');
            $student  = post_str('student_id');
            $course   = post_str('course');
            $year     = (int)($_POST['year_of_study'] ?? 0);
            if (text_len($name) < 2 || text_len($name) > 120) {
                throw new Exception('Enter your full name (2–120 characters).');
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 180) {
                throw new Exception('Enter a valid email address.');
            }
            if (strlen($password) < 8 || strlen($password) > 72) {
                throw new Exception('Choose a password between 8 and 72 characters.');
            }
            if (text_len($phone) > 25 || text_len($student) > 40 || text_len($course) > 100) {
                throw new Exception('One of the optional fields is too long.');
            }
            if ($year < 1 || $year > 8) {
                $year = 0;
            }
            if (count_sql($pdo, 'SELECT COUNT(*) FROM users WHERE email = ?', [$email])) {
                throw new Exception('That email is already registered. Try signing in instead.');
            }
            if ($student !== '' && count_sql($pdo, 'SELECT COUNT(*) FROM users WHERE student_id = ?', [$student])) {
                throw new Exception('That student ID is already registered.');
            }
            $s = $pdo->prepare('INSERT INTO users (full_name, email, password_hash, phone, student_id, course, year_of_study) VALUES (?,?,?,?,?,?,?)');
            $s->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $phone ?: null, $student ?: null, $course ?: null, $year ?: null]);
            $uid = (int)$pdo->lastInsertId();
            session_regenerate_id(true);
            $_SESSION['user'] = ['id' => $uid, 'full_name' => $name, 'email' => $email, 'role' => 'user'];
            log_activity($pdo, $uid, 'account.created');
            flash('Welcome to HavenHostel, ' . $name . '!');
            redirect();
        }

        if ($action === 'login') {
            $email = strtolower(post_str('email'));
            $s = $pdo->prepare('SELECT * FROM users WHERE email = ? AND is_active = 1');
            $s->execute([$email]);
            $u = $s->fetch();
            if (!$u || !password_verify((string)($_POST['password'] ?? ''), $u['password_hash'])) {
                throw new Exception('Email or password is incorrect.');
            }
            session_regenerate_id(true);
            $_SESSION['user'] = ['id' => (int)$u['id'], 'full_name' => $u['full_name'], 'email' => $u['email'], 'role' => $u['role']];
            log_activity($pdo, (int)$u['id'], 'auth.login');
            flash('Welcome back, ' . $u['full_name'] . '.');
            redirect();
        }

        require_auth();
        $uid = (int)me()['id'];

        if ($action === 'room_request') {
            $room = (int)($_POST['room_id'] ?? 0);
            $r = rows($pdo, "SELECT * FROM rooms WHERE id = ? AND status = 'available'", [$room])[0] ?? null;
            if (!$r) {
                throw new Exception('That room is no longer available.');
            }
            if (count_sql($pdo, "SELECT COUNT(*) FROM allocations WHERE room_id = ? AND status = 'active'", [$room]) >= (int)$r['capacity']) {
                throw new Exception('That room has reached capacity.');
            }
            if (count_sql($pdo, "SELECT COUNT(*) FROM allocations WHERE user_id = ? AND status IN ('pending','active')", [$uid])) {
                throw new Exception('You already have a pending or active room allocation.');
            }
            $s = $pdo->prepare('INSERT INTO allocations (user_id, room_id, start_date) VALUES (?,?,?)');
            $s->execute([$uid, $room, date('Y-m-d')]);
            log_activity($pdo, $uid, 'room.requested', 'Room ' . $r['room_number']);
            flash('Room request submitted. The hostel admin will review it.');
            redirect('rooms');
        }

        if ($action === 'complaint') {
            $title    = post_str('title', 150);
            $desc     = post_str('description', 5000);
            $category = post_enum('category', $COMPLAINT_CATS, 'Other');
            $priority = post_enum('priority', $PRIORITIES, 'Normal');
            if (text_len($title) < 4 || text_len($desc) < 10) {
                throw new Exception('Add a clear title and at least 10 characters of detail.');
            }
            $s = $pdo->prepare('INSERT INTO complaints (user_id, category, title, description, priority) VALUES (?,?,?,?,?)');
            $s->execute([$uid, $category, $title, $desc, $priority]);
            log_activity($pdo, $uid, 'complaint.created', $title);
            flash('Maintenance request submitted successfully.');
            redirect('complaints');
        }

        if ($action === 'payment') {
            $month  = post_str('billing_month') ?: date('Y-m');
            $amount = round((float)($_POST['amount'] ?? 0), 2);
            if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
                throw new Exception('Choose a valid billing month.');
            }
            if ($amount <= 0 || $amount > 1000000) {
                throw new Exception('Enter a valid amount.');
            }
            $method = post_enum('payment_method', $PAY_METHODS, 'UPI');
            $ref    = post_str('transaction_ref', 100) ?: null;
            $existing = rows($pdo, 'SELECT id, status FROM payments WHERE user_id = ? AND billing_month = ?', [$uid, $month])[0] ?? null;
            if ($existing && $existing['status'] !== 'Rejected') {
                throw new Exception('You already submitted a payment record for that billing month (' . $existing['status'] . ').');
            }
            if ($existing) { // a rejected record may be re-submitted
                $s = $pdo->prepare("UPDATE payments SET amount=?, payment_method=?, transaction_ref=?, status='Pending', paid_at=NULL WHERE id=? AND user_id=?");
                $s->execute([$amount, $method, $ref, $existing['id'], $uid]);
            } else {
                $s = $pdo->prepare("INSERT INTO payments (user_id, amount, billing_month, payment_method, transaction_ref, status) VALUES (?,?,?,?,?,'Pending')");
                $s->execute([$uid, $amount, $month, $method, $ref]);
            }
            log_activity($pdo, $uid, 'payment.submitted', $month);
            flash('Payment record submitted for admin verification. No money was collected by this demo app.', 'info');
            redirect('payments');
        }

        if ($action === 'visitor') {
            $name = post_str('visitor_name', 120);
            $date = post_str('visit_date');
            $time = post_str('visit_time');
            if (text_len($name) < 2 || !valid_date($date) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) || $date < date('Y-m-d')) {
                throw new Exception('Enter a visitor name and a valid current/future visit date and time.');
            }
            $s = $pdo->prepare('INSERT INTO visitors (user_id, visitor_name, relation, visit_date, visit_time, purpose) VALUES (?,?,?,?,?,?)');
            $s->execute([$uid, $name, post_str('relation', 80) ?: null, $date, $time, post_str('purpose', 255) ?: null]);
            log_activity($pdo, $uid, 'visitor.requested', $name);
            flash('Visitor pass request sent to the admin.');
            redirect('visitors');
        }

        require_admin();

        if ($action === 'room_save') {
            $num      = post_str('room_number', 20);
            $block    = post_str('block_name', 60);
            $capacity = max(1, min(20, (int)($_POST['capacity'] ?? 2)));
            $fee      = max(0, (float)($_POST['monthly_fee'] ?? 0));
            $floor    = max(0, min(30, (int)($_POST['floor_number'] ?? 0)));
            if ($num === '' || $block === '' || $fee > 1000000) {
                throw new Exception('Provide valid room details.');
            }
            $s = $pdo->prepare('INSERT INTO rooms (room_number, block_name, floor_number, room_type, capacity, monthly_fee, status) VALUES (?,?,?,?,?,?,?)');
            $s->execute([$num, $block, $floor, post_enum('room_type', $ROOM_TYPES, 'Double Sharing'), $capacity, $fee, post_enum('status', $ROOM_STATUSES, 'available')]);
            log_activity($pdo, $uid, 'room.created', $num);
            flash('Room added to inventory.');
            redirect('admin-rooms');
        }

        if ($action === 'allocation_status') {
            $id     = (int)($_POST['id'] ?? 0);
            $status = post_enum('status', ['active', 'rejected', 'ended']);
            if ($status === null) {
                throw new Exception('Choose an action for this allocation.');
            }
            $pdo->beginTransaction();
            $s = $pdo->prepare('SELECT * FROM allocations WHERE id = ? FOR UPDATE');
            $s->execute([$id]);
            $a = $s->fetch();
            if (!$a) {
                throw new Exception('Allocation not found.');
            }
            $allowed = ['pending' => ['active', 'rejected'], 'active' => ['ended'], 'rejected' => [], 'ended' => []];
            if (!in_array($status, $allowed[$a['status']] ?? [], true)) {
                throw new Exception('That change is not allowed for a ' . $a['status'] . ' allocation.');
            }
            if ($status === 'active') {
                $s = $pdo->prepare('SELECT capacity, status FROM rooms WHERE id = ? FOR UPDATE');
                $s->execute([$a['room_id']]);
                $room = $s->fetch();
                if (!$room || $room['status'] !== 'available') {
                    throw new Exception('This room is not available (maintenance/inactive); cannot approve.');
                }
                if (count_sql($pdo, "SELECT COUNT(*) FROM allocations WHERE room_id = ? AND status = 'active' AND id <> ?", [$a['room_id'], $id]) >= (int)$room['capacity']) {
                    throw new Exception('Room is full; cannot approve this allocation.');
                }
                if (count_sql($pdo, "SELECT COUNT(*) FROM allocations WHERE user_id = ? AND status = 'active' AND id <> ?", [$a['user_id'], $id])) {
                    throw new Exception('This resident already has an active room.');
                }
                $s = $pdo->prepare("UPDATE allocations SET status='active', start_date=CURDATE(), end_date=NULL WHERE id=?");
                $s->execute([$id]);
            } elseif ($status === 'ended') {
                $s = $pdo->prepare("UPDATE allocations SET status='ended', end_date=CURDATE() WHERE id=?");
                $s->execute([$id]);
            } else {
                $s = $pdo->prepare("UPDATE allocations SET status='rejected' WHERE id=?");
                $s->execute([$id]);
            }
            $pdo->commit();
            log_activity($pdo, $uid, 'allocation.' . $status, '#' . $id);
            flash('Allocation updated to ' . $status . '.');
            redirect('admin-allocations');
        }

        if ($action === 'complaint_status') {
            $status = post_enum('status', ['Open', 'In Progress', 'Resolved', 'Closed']);
            if ($status === null) {
                throw new Exception('Invalid complaint status.');
            }
            $s = $pdo->prepare('UPDATE complaints SET status = ?, admin_note = ? WHERE id = ?');
            $s->execute([$status, post_str('admin_note', 2000) ?: null, (int)($_POST['id'] ?? 0)]);
            flash('Complaint status updated.');
            redirect('admin-complaints');
        }

        if ($action === 'payment_status') {
            $status = post_enum('status', ['Paid', 'Rejected', 'Pending']);
            if ($status === null) {
                throw new Exception('Invalid payment status.');
            }
            $paidAt = $status === 'Paid' ? date('Y-m-d H:i:s') : null;
            $s = $pdo->prepare('UPDATE payments SET status = ?, paid_at = ? WHERE id = ?');
            $s->execute([$status, $paidAt, (int)($_POST['id'] ?? 0)]);
            flash('Payment record updated.');
            redirect('admin-payments');
        }

        if ($action === 'visitor_status') {
            $status = post_enum('status', ['Pending', 'Approved', 'Rejected', 'Checked In', 'Checked Out']);
            if ($status === null) {
                throw new Exception('Invalid visitor status.');
            }
            $s = $pdo->prepare('UPDATE visitors SET status = ? WHERE id = ?');
            $s->execute([$status, (int)($_POST['id'] ?? 0)]);
            flash('Visitor request updated.');
            redirect('admin-visitors');
        }

        if ($action === 'notice') {
            $title = post_str('title', 160);
            $body  = post_str('body', 5000);
            if (text_len($title) < 3 || text_len($body) < 5) {
                throw new Exception('Enter a title and notice details.');
            }
            $s = $pdo->prepare('INSERT INTO notices (title, body, audience, created_by) VALUES (?,?,?,?)');
            $s->execute([$title, $body, 'All', $uid]);
            flash('Notice published to the hostel community.');
            redirect('admin-notices');
        }

        throw new Exception('Unknown action. Refresh the page and try again.');
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (get_class($ex) !== 'Exception') { // plain Exceptions are user-facing validation messages
            error_log('[HavenHostel] action=' . ($action ?? 'unknown') . '; ' . get_class($ex) . '; code=' . $ex->getCode() . '; ' . $ex->getMessage());
        }

        if ($ex instanceof PDOException) {
            $dbCode = (int)($ex->errorInfo[1] ?? 0);
            if ($dbCode === 1062) {
                $message = [
                    'register' => 'That email or student ID is already registered.',
                    'room_save' => 'That room number already exists. Use a different room number.',
                    'payment' => 'A payment record already exists for that billing month.',
                ][$action ?? ''] ?? 'This conflicts with an existing record.';
            } elseif ($dbCode === 1452) {
                $message = 'Your account could not be matched in the database. Please sign out and sign in again.';
            } else {
                $message = 'A database error occurred. Check the PHP/Apache error log for details.';
            }
        } else {
            $message = $ex->getMessage();
        }

        flash($message, 'error');
        $back = ['login' => 'login', 'register' => 'register'][$action ?? ''] ?? $page;
        redirect($back);
    }
}

/* ---------- Page routing ---------- */
$flash = take_flash();
$admin = me() && me()['role'] === 'admin';

$pages = [
    'home' => 'Overview', 'login' => 'Sign in', 'register' => 'Create account', 'dashboard' => 'Dashboard',
    'rooms' => 'Rooms & allocation', 'complaints' => 'Maintenance', 'payments' => 'Payments',
    'visitors' => 'Visitor passes', 'notices' => 'Notice board',
    'admin-rooms' => 'Room inventory', 'admin-allocations' => 'Allocation requests',
    'admin-complaints' => 'Maintenance desk', 'admin-payments' => 'Payment review',
    'admin-visitors' => 'Visitor approvals', 'admin-notices' => 'Publish notice',
];
if (!isset($pages[$page])) {
    $page = me() ? 'dashboard' : 'home';
}
if (!me() && !in_array($page, ['home', 'login', 'register'], true)) {
    $page = 'login';
}
if (me() && in_array($page, ['login', 'register'], true)) {
    redirect('dashboard');
}
if (str_starts_with($page, 'admin-') && !$admin) {
    flash('Administrator access is required.', 'error');
    redirect('dashboard');
}
if ($admin) { // administrators use the admin versions of resident pages
    $adminMap = ['rooms' => 'admin-rooms', 'complaints' => 'admin-complaints', 'payments' => 'admin-payments', 'visitors' => 'admin-visitors'];
    if (isset($adminMap[$page])) {
        redirect($adminMap[$page]);
    }
}
$uid = (int)(me()['id'] ?? 0);
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#101d35"><title><?=e($pages[$page])?> · HavenHostel</title><link rel="stylesheet" href="assets/app.css"><script defer src="assets/app.js"></script></head><body>
<?php if(in_array($page,['login','register'])): ?><div class="auth-shell"><a class="brand auth-brand" href="index.php"><span class="brand-mark">H</span><span>haven<span class="brand-light">hostel</span></span></a><section class="auth-visual"><div class="orb orb-one"></div><div class="orb orb-two"></div><p class="eyebrow">YOUR HOSTEL. IN HARMONY.</p><h1>A better stay<br><em>starts here.</em></h1><p class="hero-copy">A thoughtful, connected space to manage your hostel life — from room requests to everyday support.</p><div class="mini-stats"><div><b>01</b><span>One simple portal</span></div><div><b>24/7</b><span>Your digital desk</span></div></div><div class="auth-art"><div class="art-building"><div class="building-roof"></div><div class="building-body"><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i></div><div class="building-door"></div></div><div class="art-plant">♧</div></div></section><section class="auth-panel"><div class="auth-form-wrap"><a class="back-link" href="index.php">← Back to overview</a><?php if($flash):?><div class="alert alert-<?=e($flash['type'])?>"><?=e($flash['message'])?></div><?php endif;?><?php if($page==='login'):?><p class="eyebrow">WELCOME BACK</p><h2>Sign in to your space</h2><p class="muted">Pick up right where you left off.</p><form method="post" class="form-stack"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="login"><label>Email address<input required type="email" name="email" placeholder="you@example.com" autocomplete="username"></label><label>Password<input required type="password" name="password" placeholder="Your password" autocomplete="current-password"></label><button class="btn btn-primary btn-wide">Sign in <span>→</span></button></form><p class="auth-switch">New to HavenHostel? <a href="index.php?page=register">Create an account</a></p><?php else:?><p class="eyebrow">GET STARTED</p><h2>Create your account</h2><p class="muted">Join your hostel community in less than a minute.</p><form method="post" class="form-stack"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="register"><label>Full name<input required name="full_name" maxlength="120" placeholder="Your full name"></label><label>Email address<input required type="email" name="email" placeholder="you@example.com"></label><div class="form-row"><label>Student ID <span class="optional">optional</span><input name="student_id" maxlength="40" placeholder="e.g. 24CSE102"></label><label>Phone <span class="optional">optional</span><input name="phone" maxlength="25" placeholder="Phone number"></label></div><div class="form-row"><label>Course <span class="optional">optional</span><input name="course" maxlength="100" placeholder="B.Tech CSE"></label><label>Year <span class="optional">optional</span><select name="year_of_study"><option value="">Select year</option><?php for($y=1;$y<=8;$y++):?><option value="<?=$y?>">Year <?=$y?></option><?php endfor;?></select></label></div><label>Password<input required type="password" name="password" minlength="8" placeholder="At least 8 characters" autocomplete="new-password"></label><button class="btn btn-primary btn-wide">Create account <span>→</span></button></form><p class="auth-switch">Already registered? <a href="index.php?page=login">Sign in</a></p><?php endif;?></div></section></div>
<?php else: ?><div class="app-shell"><?php if(me()):?><aside class="sidebar" id="sidebar"><a class="brand" href="index.php"><span class="brand-mark">H</span><span>haven<span class="brand-light">hostel</span></span></a><div class="side-label">WORKSPACE</div><nav class="nav-list"><?php $nav=$admin?[['dashboard','◫','Overview'],['admin-rooms','▦','Room inventory'],['admin-allocations','⇄','Allocations'],['admin-complaints','✳','Maintenance'],['admin-payments','₹','Payments'],['admin-visitors','♧','Visitor approvals'],['admin-notices','▤','Notice board']]:[['dashboard','◫','Overview'],['rooms','▦','Rooms & allocation'],['complaints','✳','Maintenance'],['payments','₹','Payments'],['visitors','♧','Visitor passes'],['notices','▤','Notice board']];foreach($nav as $n):?><a class="nav-link <?=$page===$n[0]?'active':''?>" href="index.php?page=<?=$n[0]?>"><span class="nav-icon"><?=$n[1]?></span><?=$n[2]?><?php if($n[0]==='admin-allocations'){$c=count_sql($pdo,"SELECT COUNT(*) FROM allocations WHERE status='pending'");if($c)echo '<span class="nav-count">'.$c.'</span>';}?></a><?php endforeach;?></nav><div class="sidebar-bottom"><div class="help-card"><span class="help-icon">✦</span><b>Need a hand?</b><p>Our hostel team is here to help.</p><a href="index.php?page=<?=$admin?'admin-complaints':'complaints'?>"><?=$admin?'Open maintenance desk':'Contact support'?> ↗</a></div><div class="profile-mini"><div class="avatar"><?=e(strtoupper(substr($displayName,0,1)))?></div><div class="profile-copy"><b><?=e($displayName)?></b><span><?= $admin?'Hostel administrator':'Resident account' ?></span></div><form method="post" class="logout-form"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="logout"><button class="logout" title="Sign out" aria-label="Sign out">↗</button></form></div></div></aside><?php endif;?><main class="main-area<?=me()?'':' guest'?>"><?php if(me()):?><header class="topbar"><button class="menu-toggle" id="menuToggle" aria-label="Toggle navigation">☰</button><div class="breadcrumb">HavenHostel <span>/</span> <b><?=e($pages[$page])?></b></div><div class="topbar-right"><span class="live-dot"></span><span class="muted small">System online</span><div class="avatar avatar-small"><?=e(strtoupper(substr($displayName,0,1)))?></div></div></header><?php else:?><header class="topbar"><a class="brand brand-dark" href="index.php"><span class="brand-mark">H</span><span>haven<span class="brand-light">hostel</span></span></a><div class="topbar-right"><a class="btn btn-outline btn-small" href="index.php?page=login">Sign in</a><a class="btn btn-primary btn-small" href="index.php?page=register">Create account</a></div></header><?php endif;?><div class="page-content"><?php if($flash):?><div class="alert alert-<?=e($flash['type'])?>" data-dismiss><?=e($flash['message'])?><button aria-label="Dismiss" class="alert-close">×</button></div><?php endif;?>
<?php if($page==='home'):?><section class="landing-hero"><div class="hero-copy-col"><p class="eyebrow">THE NEW WAY TO HOSTEL</p><h1>Feel at home.<br><em>Even away.</em></h1><p>Everything that makes hostel life easier, in one calm, connected place.</p><div class="hero-actions"><a class="btn btn-primary" href="index.php?page=register">Get started <span>→</span></a><a class="text-link" href="index.php?page=login">I have an account ↗</a></div><div class="hero-proof"><div class="proof-avatars"><i>A</i><i>H</i><i>+</i></div><span>One portal for your everyday hostel needs</span></div></div><div class="landing-illustration"><div class="sun"></div><div class="floating-chip chip-top">✦ Easy room requests</div><div class="campus-building"><div class="roof"></div><div class="building-grid"><?php for($i=0;$i<12;$i++):?><i></i><?php endfor;?></div><div class="front-door"></div></div><div class="tree tree-left">♧</div><div class="tree tree-right">♧</div><div class="floating-chip chip-bottom"><span class="check-round">✓</span><span><b>All in one place</b><small>Rooms · Payments · Support</small></span></div></div></section><section class="feature-section"><div><p class="eyebrow">MADE FOR HOSTEL LIFE</p><h2>Less admin. More living.</h2></div><div class="feature-grid"><article class="feature-card"><span class="feature-icon purple">▦</span><h3>Find your room</h3><p>Explore room options and send allocation requests without the paperwork.</p></article><article class="feature-card"><span class="feature-icon orange">✳</span><h3>Get things fixed</h3><p>Raise maintenance issues and follow every update in one timeline.</p></article><article class="feature-card"><span class="feature-icon green">₹</span><h3>Stay on top of dues</h3><p>Keep a clear record of monthly fee submissions and payment status.</p></article></div></section>
<?php elseif($page==='dashboard'):
 if($admin){$stats=[['Residents',count_sql($pdo,"SELECT COUNT(*) FROM users WHERE role='user' AND is_active=1"),'◉','purple'],['Available rooms',count_sql($pdo,"SELECT COUNT(*) FROM rooms r WHERE r.status='available' AND r.capacity>(SELECT COUNT(*) FROM allocations a WHERE a.room_id=r.id AND a.status='active')"),'▦','green'],['Open complaints',count_sql($pdo,"SELECT COUNT(*) FROM complaints WHERE status IN ('Open','In Progress')"),'✳','orange'],['Pending requests',count_sql($pdo,"SELECT COUNT(*) FROM allocations WHERE status='pending'"),'⇄','blue']];}else{$stats=[['Room status',count_sql($pdo,"SELECT COUNT(*) FROM allocations WHERE user_id=? AND status='active'",[$uid])?'Allocated':(count_sql($pdo,"SELECT COUNT(*) FROM allocations WHERE user_id=? AND status='pending'",[$uid])?'Request pending':'Not allocated'),'▦','purple'],['Open requests',count_sql($pdo,"SELECT COUNT(*) FROM complaints WHERE user_id=? AND status IN ('Open','In Progress')",[$uid]),'✳','orange'],['Pending payments',count_sql($pdo,"SELECT COUNT(*) FROM payments WHERE user_id=? AND status='Pending'",[$uid]),'₹','blue'],['Visitor passes',count_sql($pdo,"SELECT COUNT(*) FROM visitors WHERE user_id=? AND visit_date>=CURDATE() AND status IN ('Pending','Approved')",[$uid]),'♧','green']];}
 ?><div class="welcome-row"><div><p class="eyebrow"><?= $admin?'ADMINISTRATION OVERVIEW':'YOUR HOSTEL AT A GLANCE'?></p><h1><?= $admin?'Good day, administrator':'Hello, '.e(explode(' ', $displayName)[0])?> <span class="wave">✦</span></h1><p class="muted"><?=date('l, d F Y')?> <span class="dot-sep">·</span> Here's what is happening today.</p></div><div class="welcome-actions"><?php if(!$admin):?><a class="btn btn-primary" href="index.php?page=complaints">+ New request</a><?php else:?><a class="btn btn-primary" href="index.php?page=admin-rooms">+ Add a room</a><?php endif;?></div></div><div class="stats-grid"><?php foreach($stats as $s):?><article class="stat-card"><div class="stat-top"><span><?=e($s[0])?></span><span class="stat-icon <?=e($s[3])?>"><?=$s[2]?></span></div><div class="stat-value"><?=e($s[1])?></div><div class="stat-foot"><span class="stat-foot-dot"></span><?= $admin?'Live database count':'Your account summary'?></div></article><?php endforeach;?></div><div class="dashboard-grid"><section class="panel"><div class="panel-heading"><div><h2><?= $admin?'Recent maintenance requests':'Recent maintenance'?></h2><p>Latest updates from the hostel desk</p></div><a class="subtle-link" href="index.php?page=<?=$admin?'admin-complaints':'complaints'?>">View all ↗</a></div><?php $list=$admin?rows($pdo,'SELECT c.*,u.full_name FROM complaints c JOIN users u ON u.id=c.user_id ORDER BY c.created_at DESC LIMIT 5',[]):rows($pdo,'SELECT c.*,u.full_name FROM complaints c JOIN users u ON u.id=c.user_id WHERE c.user_id=? ORDER BY c.created_at DESC LIMIT 5',[me()['id']]);if(!$list):?><div class="empty-state"><span>✳</span><b>Nothing to show yet</b><p>New maintenance requests will appear here.</p></div><?php else:?><div class="table-wrap"><table><thead><tr><th>Request</th><?php if($admin):?><th>Resident</th><?php endif;?><th>Priority</th><th>Status</th><th>Created</th></tr></thead><tbody><?php foreach($list as $c):?><tr><td><b><?=e($c['title'])?></b><small><?=e($c['category'])?></small></td><?php if($admin):?><td><?=e($c['full_name'])?></td><?php endif;?><td><?=status_badge($c['priority'])?></td><td><?=status_badge($c['status'])?></td><td><?=date('d M',strtotime($c['created_at']))?></td></tr><?php endforeach;?></tbody></table></div><?php endif;?></section><section class="panel notice-panel"><div class="panel-heading"><div><h2>Community noticeboard</h2><p>Important updates, all in one place</p></div><a class="subtle-link" href="index.php?page=notices">See all ↗</a></div><?php $notices=rows($pdo,'SELECT * FROM notices ORDER BY created_at DESC LIMIT 3');if(!$notices):?><p class="muted">No notices published yet.</p><?php else:foreach($notices as $n):?><article class="notice-item"><span class="notice-pin">✦</span><div><b><?=e($n['title'])?></b><p><?=e(text_clip($n['body'],0,115,'…'))?></p><small><?=date('d M Y',strtotime($n['created_at']))?></small></div></article><?php endforeach;endif;?></section></div>
<?php elseif($page==='rooms'||$page==='admin-rooms'):
 $isAdmin=$page==='admin-rooms';$rooms=rows($pdo,"SELECT r.*, (SELECT COUNT(*) FROM allocations a WHERE a.room_id=r.id AND a.status='active') AS occupied FROM rooms r ORDER BY r.block_name,r.room_number");$myAlloc=!$isAdmin?rows($pdo,'SELECT a.*,r.room_number,r.block_name,r.room_type,r.monthly_fee FROM allocations a JOIN rooms r ON r.id=a.room_id WHERE a.user_id=? ORDER BY a.created_at DESC',[me()['id']]):[];$hasCurrentAllocation=!$isAdmin?(bool)count_sql($pdo,"SELECT COUNT(*) FROM allocations WHERE user_id=? AND status IN ('pending','active')",[$uid]):false;?><div class="welcome-row"><div><p class="eyebrow"><?=$isAdmin?'HOSTEL OPERATIONS':'YOUR LIVING SPACE'?></p><h1><?=$isAdmin?'Room inventory':'Rooms & allocation'?></h1><p class="muted"><?=$isAdmin?'Manage room capacity, pricing and availability.':'Explore available rooms and request a place to call home.'?></p></div></div><?php if(!$isAdmin&&$myAlloc):?><section class="panel section-gap"><div class="panel-heading"><div><h2>Your allocation requests</h2><p>Current and previous room assignments</p></div></div><div class="table-wrap"><table><thead><tr><th>Room</th><th>Type</th><th>Monthly fee</th><th>Start date</th><th>Status</th></tr></thead><tbody><?php foreach($myAlloc as $a):?><tr><td><b><?=e($a['room_number'])?></b><small><?=e($a['block_name'])?></small></td><td><?=e($a['room_type'])?></td><td>₹<?=number_format($a['monthly_fee'])?></td><td><?=e($a['start_date'])?></td><td><?=status_badge($a['status'])?></td></tr><?php endforeach;?></tbody></table></div></section><?php endif;?><?php if($isAdmin):?><section class="panel section-gap"><div class="panel-heading"><div><h2>Add a room</h2><p>Room numbers must be unique.</p></div></div><form method="post" class="inline-form"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="room_save"><label>Room number<input required name="room_number" placeholder="A-103"></label><label>Block<input required name="block_name" placeholder="A Block"></label><label>Floor<input required type="number" name="floor_number" value="1" min="0" max="30"></label><label>Room type<select name="room_type"><option>Single</option><option selected>Double Sharing</option><option>Triple Sharing</option><option>Dormitory</option></select></label><label>Capacity<input required type="number" name="capacity" value="2" min="1" max="20"></label><label>Monthly fee (₹)<input required type="number" name="monthly_fee" value="6500" min="0" step="100"></label><label>Status<select name="status"><option value="available">Available</option><option value="maintenance">Maintenance</option><option value="inactive">Inactive</option></select></label><button class="btn btn-primary">Add room +</button></form></section><?php endif;?><div class="room-grid"><?php foreach($rooms as $r):$available=max(0,(int)$r['capacity']-(int)$r['occupied']);?><article class="room-card"><div class="room-art <?=e(strtolower(str_replace(' ','-', $r['room_type'])))?>"><span class="room-tag"><?=e($r['room_type'])?></span><div class="room-window"><i></i><i></i></div><div class="room-bed bed-one"></div><div class="room-bed bed-two"></div><div class="room-plant">♧</div><div class="room-number"><?=e($r['room_number'])?></div></div><div class="room-details"><div class="room-title"><div><p class="eyebrow"><?=e($r['block_name'])?> · FLOOR <?=e($r['floor_number'])?></p><h3>Room <?=e($r['room_number'])?></h3></div><?= $available>0&&$r['status']==='available'?'<span class="availability"><i></i> Available</span>':'<span class="availability unavailable">'.e(ucfirst($r['status']==='available'?'Full':$r['status'])).'</span>'?></div><div class="room-meta"><span>♙ <?=$r['occupied']?> / <?=$r['capacity']?> occupied</span><b>₹<?=number_format($r['monthly_fee'])?><small>/ month</small></b></div><?php if(!$isAdmin):?><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="room_request"><input type="hidden" name="room_id" value="<?=$r['id']?>"><button class="btn <?=$available>0&&$r['status']==='available'?'btn-outline':'btn-disabled'?> btn-wide" <?=$available<=0||$r['status']!=='available'||$hasCurrentAllocation?'disabled':''?>><?= $hasCurrentAllocation?'Request already active':($available>0&&$r['status']==='available'?'Request this room':'Currently unavailable')?> <span>→</span></button></form><?php else:?><p class="room-admin-note">Room inventory record · <?=e($r['status'])?></p><?php endif;?></div></article><?php endforeach;?></div>
<?php elseif($page==='complaints'||$page==='admin-complaints'):$isAdmin=$page==='admin-complaints';$items=$isAdmin?rows($pdo,"SELECT c.*,u.full_name,u.student_id FROM complaints c JOIN users u ON u.id=c.user_id ORDER BY FIELD(c.status,'Open','In Progress','Resolved','Closed'),c.created_at DESC"):rows($pdo,'SELECT * FROM complaints WHERE user_id=? ORDER BY created_at DESC',[me()['id']]);?><div class="welcome-row"><div><p class="eyebrow"><?=$isAdmin?'SERVICE OPERATIONS':'HOSTEL SUPPORT'?></p><h1><?=$isAdmin?'Maintenance desk':'Maintenance & support'?></h1><p class="muted"><?=$isAdmin?'Review resident issues and keep the hostel running smoothly.':'Tell us what needs attention. We’ll keep you updated as it gets resolved.'?></p></div></div><?php if(!$isAdmin):?><section class="panel section-gap"><div class="panel-heading"><div><h2>Raise a maintenance request</h2><p>Include enough detail to help the team resolve it quickly.</p></div><span class="panel-icon">✳</span></div><form method="post" class="form-grid"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="complaint"><label>Request title<input required name="title" maxlength="150" placeholder="e.g. Bathroom tap is leaking"></label><label>Category<select name="category"><?php foreach(['Maintenance','Housekeeping','Wi-Fi','Electrical','Plumbing','Food','Security','Other'] as $x):?><option><?=$x?></option><?php endforeach;?></select></label><label>Priority<select name="priority"><option>Low</option><option selected>Normal</option><option>High</option><option>Urgent</option></select></label><label class="span-2">What happened?<textarea required name="description" minlength="10" rows="4" placeholder="Describe the issue and where it is happening…"></textarea></label><div class="span-2 form-actions"><button class="btn btn-primary">Submit request <span>→</span></button><span class="muted small">Urgent safety issues should also be reported directly to hostel staff.</span></div></form></section><?php endif;?><section class="panel section-gap"><div class="panel-heading"><div><h2><?=$isAdmin?'All resident requests':'Your request history'?></h2><p><?=$isAdmin?count($items).' request(s) in the system':count($items).' request(s) submitted'?></p></div></div><?php if(!$items):?><div class="empty-state"><span>✳</span><b>No maintenance requests yet</b><p>When a request is submitted, it will show up here.</p></div><?php else:?><div class="table-wrap"><table><thead><tr><th>Request</th><?php if($isAdmin):?><th>Resident</th><?php endif;?><th>Priority</th><th>Status</th><th>Submitted</th><?php if($isAdmin):?><th>Update</th><?php endif;?></tr></thead><tbody><?php foreach($items as $c):?><tr><td><b><?=e($c['title'])?></b><small><?=e($c['category'])?> · <?=e(text_clip($c['description'],0,100,'…'))?></small><?php if(!$isAdmin&&$c['admin_note']):?><small class="admin-note">Admin note: <?=e($c['admin_note'])?></small><?php endif;?></td><?php if($isAdmin):?><td><?=e($c['full_name'])?><small><?=e($c['student_id']?:'Resident')?></small></td><?php endif;?><td><?=status_badge($c['priority'])?></td><td><?=status_badge($c['status'])?></td><td><?=date('d M Y',strtotime($c['created_at']))?></td><?php if($isAdmin):?><td><form method="post" class="row-form"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="complaint_status"><input type="hidden" name="id" value="<?=$c['id']?>"><select name="status"><?php foreach(['Open','In Progress','Resolved','Closed'] as $st):?><option <?=$st===$c['status']?'selected':''?>><?=$st?></option><?php endforeach;?></select><input name="admin_note" placeholder="Optional note" value="<?=e($c['admin_note'])?>"><button class="btn btn-small btn-outline">Save</button></form></td><?php endif;?></tr><?php endforeach;?></tbody></table></div><?php endif;?></section>
<?php elseif($page==='payments'||$page==='admin-payments'):$isAdmin=$page==='admin-payments';$items=$isAdmin?rows($pdo,'SELECT p.*,u.full_name,u.student_id FROM payments p JOIN users u ON u.id=p.user_id ORDER BY p.created_at DESC'):rows($pdo,'SELECT * FROM payments WHERE user_id=? ORDER BY created_at DESC',[me()['id']]);?><div class="welcome-row"><div><p class="eyebrow">FEE MANAGEMENT</p><h1><?=$isAdmin?'Payment verification':'Payments & fee records'?></h1><p class="muted">Keep a transparent record of monthly hostel fee submissions.</p></div></div><?php if(!$isAdmin):?><section class="panel section-gap"><div class="panel-heading"><div><h2>Submit a payment record</h2><p>This demo records a payment reference; it does not process or collect money.</p></div><span class="panel-icon">₹</span></div><form method="post" class="form-grid"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="payment"><label>Amount (₹)<input required type="number" name="amount" min="1" max="1000000" step="0.01" placeholder="6500"></label><label>Billing month<input required type="month" name="billing_month" value="<?=date('Y-m')?>"></label><label>Payment method<select name="payment_method"><?php foreach(['UPI','Cash','Bank Transfer','Card','Other'] as $m):?><option><?=$m?></option><?php endforeach;?></select></label><label>Transaction reference <span class="optional">optional</span><input name="transaction_ref" maxlength="100" placeholder="UPI reference / receipt no."></label><div class="span-2 form-actions"><button class="btn btn-primary">Submit for verification →</button></div></form></section><?php endif;?><section class="panel section-gap"><div class="panel-heading"><div><h2><?=$isAdmin?'All payment records':'Payment history'?></h2><p><?=count($items)?> record(s)</p></div></div><?php if(!$items):?><div class="empty-state"><span>₹</span><b>No payment records</b><p>Submitted records will be listed here.</p></div><?php else:?><div class="table-wrap"><table><thead><tr><th>Billing month</th><?php if($isAdmin):?><th>Resident</th><?php endif;?><th>Amount</th><th>Method</th><th>Reference</th><th>Status</th><?php if($isAdmin):?><th>Review</th><?php endif;?></tr></thead><tbody><?php foreach($items as $p):?><tr><td><b><?=e(date('F Y',strtotime($p['billing_month'].'-01')))?></b><small><?=date('d M Y',strtotime($p['created_at']))?></small></td><?php if($isAdmin):?><td><?=e($p['full_name'])?><small><?=e($p['student_id']?:'Resident')?></small></td><?php endif;?><td><b>₹<?=number_format($p['amount'],2)?></b></td><td><?=e($p['payment_method'])?></td><td><?=e($p['transaction_ref']?:'—')?></td><td><?=status_badge($p['status'])?></td><?php if($isAdmin):?><td><form method="post" class="row-form"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="payment_status"><input type="hidden" name="id" value="<?=$p['id']?>"><select name="status"><?php foreach(['Pending','Paid','Rejected'] as $st):?><option <?=$st===$p['status']?'selected':''?>><?=$st?></option><?php endforeach;?></select><button class="btn btn-small btn-outline">Save</button></form></td><?php endif;?></tr><?php endforeach;?></tbody></table></div><?php endif;?></section>
<?php elseif($page==='visitors'||$page==='admin-visitors'):$isAdmin=$page==='admin-visitors';$items=$isAdmin?rows($pdo,'SELECT v.*,u.full_name,u.student_id FROM visitors v JOIN users u ON u.id=v.user_id ORDER BY v.visit_date DESC,v.visit_time DESC'):rows($pdo,'SELECT * FROM visitors WHERE user_id=? ORDER BY visit_date DESC,visit_time DESC',[me()['id']]);?><div class="welcome-row"><div><p class="eyebrow">SAFE & SIMPLE VISITS</p><h1><?=$isAdmin?'Visitor approvals':'Visitor passes'?></h1><p class="muted">Submit and manage visitor requests before guests arrive.</p></div></div><?php if(!$isAdmin):?><section class="panel section-gap"><div class="panel-heading"><div><h2>Request a visitor pass</h2><p>Submit requests in advance for hostel approval.</p></div><span class="panel-icon">♧</span></div><form method="post" class="form-grid"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="visitor"><label>Visitor full name<input required name="visitor_name" maxlength="120" placeholder="Guest's full name"></label><label>Relationship<input name="relation" maxlength="80" placeholder="Parent, sibling, friend…"></label><label>Visit date<input required type="date" name="visit_date" min="<?=date('Y-m-d')?>" value="<?=date('Y-m-d')?>"></label><label>Arrival time<input required type="time" name="visit_time" value="15:00"></label><label class="span-2">Purpose of visit <span class="optional">optional</span><input name="purpose" maxlength="255" placeholder="Brief reason for the visit"></label><div class="span-2 form-actions"><button class="btn btn-primary">Request visitor pass →</button></div></form></section><?php endif;?><section class="panel section-gap"><div class="panel-heading"><div><h2><?=$isAdmin?'All visitor requests':'Your visitor history'?></h2><p><?=count($items)?> request(s)</p></div></div><?php if(!$items):?><div class="empty-state"><span>♧</span><b>No visitor requests</b><p>Requests will appear here once submitted.</p></div><?php else:?><div class="table-wrap"><table><thead><tr><th>Visitor</th><?php if($isAdmin):?><th>Resident</th><?php endif;?><th>Visit date</th><th>Purpose</th><th>Status</th><?php if($isAdmin):?><th>Action</th><?php endif;?></tr></thead><tbody><?php foreach($items as $v):?><tr><td><b><?=e($v['visitor_name'])?></b><small><?=e($v['relation']?:'Guest')?> · <?=e($v['visit_time'])?></small></td><?php if($isAdmin):?><td><?=e($v['full_name'])?><small><?=e($v['student_id']?:'Resident')?></small></td><?php endif;?><td><?=date('d M Y',strtotime($v['visit_date']))?></td><td><?=e($v['purpose']?:'—')?></td><td><?=status_badge($v['status'])?></td><?php if($isAdmin):?><td><form method="post" class="row-form"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="visitor_status"><input type="hidden" name="id" value="<?=$v['id']?>"><select name="status"><?php foreach(['Pending','Approved','Rejected','Checked In','Checked Out'] as $st):?><option <?=$st===$v['status']?'selected':''?>><?=$st?></option><?php endforeach;?></select><button class="btn btn-small btn-outline">Save</button></form></td><?php endif;?></tr><?php endforeach;?></tbody></table></div><?php endif;?></section>
<?php elseif($page==='notices'||$page==='admin-notices'):$isAdmin=$page==='admin-notices';$items=rows($pdo,'SELECT n.*,u.full_name FROM notices n LEFT JOIN users u ON u.id=n.created_by ORDER BY n.created_at DESC');?><div class="welcome-row"><div><p class="eyebrow">HOSTEL COMMUNITY</p><h1><?=$isAdmin?'Notice board management':'Notice board'?></h1><p class="muted">Important updates and announcements from your hostel team.</p></div></div><?php if($isAdmin):?><section class="panel section-gap"><div class="panel-heading"><div><h2>Publish a notice</h2><p>Keep residents informed about important changes.</p></div></div><form method="post" class="form-grid"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="notice"><label class="span-2">Notice title<input required name="title" maxlength="160" placeholder="e.g. Water maintenance on Saturday"></label><label class="span-2">Details<textarea required name="body" minlength="5" rows="4" placeholder="Share all important details…"></textarea></label><div class="span-2 form-actions"><button class="btn btn-primary">Publish notice ↗</button></div></form></section><?php endif;?><div class="notice-grid"><?php foreach($items as $n):?><article class="notice-card"><div class="notice-card-top"><span class="notice-pin">✦</span><span class="muted small"><?=date('d M Y',strtotime($n['created_at']))?></span></div><h3><?=e($n['title'])?></h3><p><?=nl2br(e($n['body']))?></p><div class="notice-author"><span class="avatar avatar-tiny">H</span> <?=e($n['full_name']?:'HavenHostel team')?></div></article><?php endforeach;?></div><?php if(!$items):?><div class="empty-state"><b>No notices published yet.</b></div><?php endif;?>
<?php elseif($page==='admin-allocations'):$items=rows($pdo,"SELECT a.*,u.full_name,u.email,u.student_id,r.room_number,r.block_name,r.room_type,r.capacity,(SELECT COUNT(*) FROM allocations ax WHERE ax.room_id=r.id AND ax.status='active') occupied FROM allocations a JOIN users u ON u.id=a.user_id JOIN rooms r ON r.id=a.room_id ORDER BY FIELD(a.status,'pending','active','rejected','ended'),a.created_at DESC");?><div class="welcome-row"><div><p class="eyebrow">ROOM ASSIGNMENT</p><h1>Allocation requests</h1><p class="muted">Review resident requests and confirm room assignments.</p></div></div><section class="panel section-gap"><div class="table-wrap"><table><thead><tr><th>Resident</th><th>Room</th><th>Request date</th><th>Status</th><th>Decision</th></tr></thead><tbody><?php foreach($items as $a):?><tr><td><b><?=e($a['full_name'])?></b><small><?=e($a['email'])?> · <?=e($a['student_id']?:'No student ID')?></small></td><td><b><?=e($a['room_number'])?></b><small><?=e($a['block_name'])?> · <?=e($a['room_type'])?> · <?=$a['occupied']?>/<?=$a['capacity']?> active</small></td><td><?=date('d M Y',strtotime($a['created_at']))?></td><td><?=status_badge($a['status'])?></td><td><?php $acts=['pending'=>['active'=>'Approve','rejected'=>'Reject'],'active'=>['ended'=>'End stay']][$a['status']]??[];if(!$acts):?><span class="muted small">No further action</span><?php else:?><form method="post" class="row-form"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="allocation_status"><input type="hidden" name="id" value="<?=(int)$a['id']?>"><select name="status" required><?php if($a['status']==='pending'):?><option value="" selected disabled>Choose…</option><?php endif;?><?php foreach($acts as $st=>$label):?><option value="<?=$st?>"><?=$label?></option><?php endforeach;?></select><button class="btn btn-small btn-outline">Save</button></form><?php endif;?></td></tr><?php endforeach;?></tbody></table></div><?php if(!$items):?><div class="empty-state"><b>No allocation requests yet.</b></div><?php endif;?></section>
<?php endif;?></div><footer class="footer"><span>© <?=date('Y')?> HavenHostel</span><span>Designed for a smoother stay <b>✦</b></span></footer></main></div><?php endif;?></body></html>
