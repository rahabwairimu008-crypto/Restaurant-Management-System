<?php
// settings.php — System Settings
session_start();
require_once 'dbconfig.php';
if (empty($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php'); exit;
}
$pdo = getDB();
$activePage = 'settings';
$msg = ''; $msgType = '';

if (!isset($_SESSION['settings'])) {
    $_SESSION['settings'] = [
        'restaurant_name'    => 'Jiko House',
        'restaurant_phone'   => '+254 700 000 000',
        'restaurant_email'   => 'info@jikohouse.co.ke',
        'restaurant_address' => 'Nairobi, Kenya',
        'currency'           => 'KSh',
        'tax_rate'           => '16',
        'timezone'           => 'Africa/Nairobi',
        'opening_time'       => '09:00',
        'closing_time'       => '22:00',
        'mpesa_shortcode'    => '',
        'mpesa_key'          => '',
    ];
}
$s = &$_SESSION['settings'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'save_info') {
        foreach (['restaurant_name','restaurant_phone','restaurant_email','restaurant_address','currency','timezone'] as $k) {
            if (isset($_POST[$k])) $s[$k] = trim($_POST[$k]);
        }
        $msg = '✅ Restaurant info saved.'; $msgType = 'success';
    }
    if ($action === 'save_ops') {
        foreach (['tax_rate','opening_time','closing_time'] as $k) {
            if (isset($_POST[$k])) $s[$k] = trim($_POST[$k]);
        }
        $msg = '✅ Operations settings saved.'; $msgType = 'success';
    }
    if ($action === 'save_mpesa') {
        foreach (['mpesa_shortcode','mpesa_key'] as $k) {
            if (isset($_POST[$k])) $s[$k] = trim($_POST[$k]);
        }
        $msg = '✅ M-Pesa settings saved.'; $msgType = 'success';
    }
    if ($action === 'change_password') {
        $new = $_POST['new_password'] ?? '';
        $cfm = $_POST['confirm_password'] ?? '';
        if (strlen($new) < 6) { $msg = '❌ Password must be at least 6 chars.'; $msgType = 'error'; }
        elseif ($new !== $cfm) { $msg = '❌ Passwords do not match.'; $msgType = 'error'; }
        else {
            $pdo->prepare("UPDATE users SET password_hash=? WHERE id=?")->execute([password_hash($new,PASSWORD_DEFAULT),$_SESSION['user_id']]);
            $msg = '✅ Password updated successfully.'; $msgType = 'success';
        }
    }
    if ($action === 'setup_db') {
        try { runSchema(); $msg = '✅ Database schema created/updated successfully.'; $msgType = 'success'; }
        catch (Exception $e) { $msg = '❌ '.$e->getMessage(); $msgType = 'error'; }
    }
}

$userCount  = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$menuCount  = $pdo->query("SELECT COUNT(*) FROM menu")->fetchColumn();
$orderCount = $pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Jiko House — Settings</title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<style>
<?php readfile('_styles.css'); ?>
.section{background:var(--surface);border:1px solid var(--border);border-radius:14px;box-shadow:var(--shadow);margin-bottom:22px;overflow:hidden;}
.section-head{padding:16px 22px 14px;border-bottom:1px solid var(--border);}
.section-title{font-family:'Playfair Display',serif;font-size:17px;color:var(--deep);}
.section-body{padding:22px;}
.section-footer{padding:14px 22px;border-top:1px solid var(--border);display:flex;justify-content:flex-end;}
.db-info{background:var(--bg2);border-radius:9px;padding:14px 18px;font-size:13px;color:var(--text-muted);margin-top:14px;font-family:'DM Mono',monospace;}
.danger-zone{background:rgba(168,50,50,.04);border:1px solid rgba(168,50,50,.15);border-radius:14px;padding:22px;margin-bottom:22px;}
.danger-title{font-family:'Playfair Display',serif;font-size:16px;color:var(--red);margin-bottom:12px;}
</style>
</head>
<body>
<?php include '_sidebar.php'; ?>
<div class="main">
  <div class="topbar"><h1 class="page-title">Settings</h1></div>
  <div class="content" style="max-width:820px">
    <?php if ($msg): ?><div class="flash <?= $msgType ?>"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

    <!-- DB SUMMARY -->
    <div class="mini-stats" style="margin-bottom:26px">
      <div class="ms"><div class="ms-num"><?= $userCount ?></div><div class="ms-label">Total users</div></div>
      <div class="ms"><div class="ms-num"><?= $menuCount ?></div><div class="ms-label">Menu items</div></div>
      <div class="ms"><div class="ms-num"><?= $orderCount ?></div><div class="ms-label">All-time orders</div></div>
      <div class="ms"><div class="ms-num"><?= DB_NAME ?></div><div class="ms-label">Database</div></div>
    </div>

    <!-- RESTAURANT INFO -->
    <div class="section">
      <div class="section-head"><div class="section-title">🏠 Restaurant Information</div></div>
      <form method="POST"><input type="hidden" name="action" value="save_info">
        <div class="section-body">
          <div class="form-row">
            <div class="form-group"><label class="form-label">Restaurant Name</label><input type="text" name="restaurant_name" class="form-input" value="<?= htmlspecialchars($s['restaurant_name']) ?>"></div>
            <div class="form-group"><label class="form-label">Phone</label><input type="text" name="restaurant_phone" class="form-input" value="<?= htmlspecialchars($s['restaurant_phone']) ?>"></div>
          </div>
          <div class="form-row">
            <div class="form-group"><label class="form-label">Email</label><input type="email" name="restaurant_email" class="form-input" value="<?= htmlspecialchars($s['restaurant_email']) ?>"></div>
            <div class="form-group"><label class="form-label">Currency</label>
              <select name="currency" class="form-select">
                <option value="KSh" <?= $s['currency']==='KSh'?'selected':'' ?>>KSh — Kenyan Shilling</option>
                <option value="USD" <?= $s['currency']==='USD'?'selected':'' ?>>USD — US Dollar</option>
                <option value="EUR" <?= $s['currency']==='EUR'?'selected':'' ?>>EUR — Euro</option>
              </select>
            </div>
          </div>
          <div class="form-group"><label class="form-label">Address</label><input type="text" name="restaurant_address" class="form-input" value="<?= htmlspecialchars($s['restaurant_address']) ?>"></div>
          <div class="form-group"><label class="form-label">Timezone</label>
            <select name="timezone" class="form-select">
              <option value="Africa/Nairobi" <?= $s['timezone']==='Africa/Nairobi'?'selected':'' ?>>Africa/Nairobi (EAT +3)</option>
              <option value="UTC">UTC</option>
            </select>
          </div>
        </div>
        <div class="section-footer"><button type="submit" class="btn btn-primary">Save Info</button></div>
      </form>
    </div>

    <!-- OPERATIONS -->
    <div class="section">
      <div class="section-head"><div class="section-title">⏰ Operations</div></div>
      <form method="POST"><input type="hidden" name="action" value="save_ops">
        <div class="section-body">
          <div class="form-row">
            <div class="form-group"><label class="form-label">Opening Time</label><input type="time" name="opening_time" class="form-input" value="<?= $s['opening_time'] ?>"></div>
            <div class="form-group"><label class="form-label">Closing Time</label><input type="time" name="closing_time" class="form-input" value="<?= $s['closing_time'] ?>"></div>
            <div class="form-group"><label class="form-label">Tax Rate (%)</label><input type="number" name="tax_rate" class="form-input" value="<?= $s['tax_rate'] ?>" step="0.1" min="0" max="100"></div>
          </div>
        </div>
        <div class="section-footer"><button type="submit" class="btn btn-primary">Save Operations</button></div>
      </form>
    </div>

    <!-- MPESA -->
    <div class="section">
      <div class="section-head"><div class="section-title">📱 M-Pesa Integration</div></div>
      <form method="POST"><input type="hidden" name="action" value="save_mpesa">
        <div class="section-body">
          <div class="form-row">
            <div class="form-group"><label class="form-label">Paybill / Till Number</label><input type="text" name="mpesa_shortcode" class="form-input" value="<?= htmlspecialchars($s['mpesa_shortcode']) ?>" placeholder="e.g. 174379"></div>
            <div class="form-group"><label class="form-label">Consumer Key</label><input type="text" name="mpesa_key" class="form-input" value="<?= htmlspecialchars($s['mpesa_key']) ?>" placeholder="From Daraja portal"></div>
          </div>
        </div>
        <div class="section-footer"><button type="submit" class="btn btn-primary">Save M-Pesa</button></div>
      </form>
    </div>

    <!-- CHANGE PASSWORD -->
    <div class="section">
      <div class="section-head"><div class="section-title">🔒 Change My Password</div></div>
      <form method="POST"><input type="hidden" name="action" value="change_password">
        <div class="section-body">
          <div class="form-row">
            <div class="form-group"><label class="form-label">New Password (min 6 chars)</label><input type="password" name="new_password" class="form-input" required></div>
            <div class="form-group"><label class="form-label">Confirm New Password</label><input type="password" name="confirm_password" class="form-input" required></div>
          </div>
        </div>
        <div class="section-footer"><button type="submit" class="btn btn-primary">Update Password</button></div>
      </form>
    </div>

    <!-- DANGER ZONE -->
    <div class="danger-zone">
      <div class="danger-title">⚠ Database Setup</div>
      <p style="font-size:13px;color:var(--text-muted);margin-bottom:16px">Run this once to create all tables and seed default data. Uses CREATE TABLE IF NOT EXISTS — safe to run multiple times.</p>
      <form method="POST" onsubmit="return confirm('Run schema setup on database: <?= DB_NAME ?>?')">
        <input type="hidden" name="action" value="setup_db">
        <button type="submit" class="btn btn-sm" style="background:var(--deep);color:#fff">▶ Run Schema Setup</button>
      </form>
      <div class="db-info">Host: <?= DB_HOST ?> &nbsp;|&nbsp; DB: <strong><?= DB_NAME ?></strong> &nbsp;|&nbsp; User: <?= DB_USER ?> &nbsp;|&nbsp; Port: <?= DB_PORT ?></div>
    </div>

  </div>
</div>
</body></html>
