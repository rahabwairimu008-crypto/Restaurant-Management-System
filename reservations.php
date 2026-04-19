<?php
// reservations.php — Table Reservations
// Restaurant Management System — Jiko House
session_start();
require_once 'dbconfig.php';

$today = date('Y-m-d');
$message = '';
$msg_type = '';

$default_reservations = [
  ['id'=>1,'table'=>4,'name'=>'James Mwangi',  'phone'=>'+254 712 345 678','party'=>6,'date'=>$today,'time'=>'13:00','notes'=>'Anniversary dinner','status'=>'confirmed'],
  ['id'=>2,'table'=>2,'name'=>'Sarah Otieno',   'phone'=>'+254 720 987 654','party'=>3,'date'=>$today,'time'=>'14:30','notes'=>'Window seat please','status'=>'confirmed'],
  ['id'=>3,'table'=>6,'name'=>'Peter Kamau',    'phone'=>'+254 733 111 222','party'=>2,'date'=>$today,'time'=>'12:00','notes'=>'','status'=>'seated'],
  ['id'=>4,'table'=>1,'name'=>'Grace Wanjiku',  'phone'=>'+254 700 555 444','party'=>4,'date'=>date('Y-m-d', strtotime('+1 day')),'time'=>'19:00','notes'=>'Vegetarian menu','status'=>'confirmed'],
  ['id'=>5,'table'=>8,'name'=>'Corporate Lunch','phone'=>'+254 745 888 000','party'=>8,'date'=>date('Y-m-d', strtotime('+1 day')),'time'=>'12:30','notes'=>'Invoice required','status'=>'confirmed'],
];

if (!isset($_SESSION['reservations'])) $_SESSION['reservations'] = $default_reservations;
$reservations = &$_SESSION['reservations'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';

  if ($action === 'add') {
    $new_id = $reservations ? max(array_column($reservations, 'id')) + 1 : 1;
    $reservations[] = [
      'id'     => $new_id,
      'table'  => (int)$_POST['table'],
      'name'   => trim($_POST['name']),
      'phone'  => trim($_POST['phone']),
      'party'  => (int)$_POST['party'],
      'date'   => $_POST['date'],
      'time'   => $_POST['time'],
      'notes'  => trim($_POST['notes']),
      'status' => 'confirmed',
    ];
    $message = '✅ Reservation for ' . htmlspecialchars($_POST['name']) . ' added.';
    $msg_type = 'success';
  }

  if ($action === 'cancel') {
    $cid = (int)$_POST['res_id'];
    foreach ($reservations as &$r) {
      if ($r['id'] === $cid) { $r['status'] = 'cancelled'; break; }
    }
    unset($r);
    $message = 'Reservation cancelled.';
    $msg_type = 'warning';
  }

  if ($action === 'seat') {
    $sid = (int)$_POST['res_id'];
    foreach ($reservations as &$r) {
      if ($r['id'] === $sid) { $r['status'] = 'seated'; break; }
    }
    unset($r);
    $message = '✅ Guest seated.';
    $msg_type = 'success';
  }
}

$view_date = $_GET['date'] ?? $today;
$today_res  = array_filter($reservations, fn($r) => $r['date'] === $view_date && $r['status'] !== 'cancelled');
usort($today_res, fn($a,$b) => strcmp($a['time'], $b['time']));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Jiko House — Reservations</title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
  :root {
    --bg:#F4EFE6; --bg2:#EDE5D8; --surface:#FFFDF8;
    --border:rgba(92,61,46,0.12); --brown:#5C3D2E; --deep:#3A2218;
    --rust:#C0622B; --gold:#C49A3C; --sage:#5A8A5E; --red:#A83232;
    --amber:#C07A20;
    --text:#2C1A0E; --text-muted:#7A5C45; --text-dim:#B09A85;
    --shadow:0 2px 16px rgba(60,34,24,0.08);
  }
  * { margin:0; padding:0; box-sizing:border-box; }
  body { background:var(--bg); font-family:'DM Sans',sans-serif; color:var(--text); min-height:100vh; display:flex; }

  aside { width:230px; background:var(--deep); flex-shrink:0; position:fixed; top:0; left:0; bottom:0; z-index:10; display:flex; flex-direction:column; }
  .sidebar-logo { padding:24px 20px 20px; border-bottom:1px solid rgba(255,255,255,0.07); }
  .logo-main { font-family:'Playfair Display',serif; font-size:20px; color:var(--gold); }
  .logo-sub  { font-size:10px; color:rgba(240,228,210,0.3); letter-spacing:.18em; text-transform:uppercase; margin-top:3px; }
  nav { flex:1; padding:16px 12px; }
  .nav-section { font-size:10px; text-transform:uppercase; letter-spacing:.16em; color:rgba(240,228,210,0.3); padding:12px 10px 6px; font-weight:600; }
  .nav-item { display:flex; align-items:center; gap:10px; padding:10px 12px; border-radius:9px; font-size:14px; color:rgba(240,228,210,0.6); cursor:pointer; transition:all .18s; margin-bottom:2px; text-decoration:none; }
  .nav-item:hover { background:rgba(255,255,255,0.06); color:rgba(240,228,210,0.9); }
  .nav-item.active { background:var(--rust); color:#fff; font-weight:500; }
  .sidebar-footer { padding:16px; border-top:1px solid rgba(255,255,255,0.07); font-size:11px; color:rgba(240,228,210,0.25); text-align:center; }

  .main { margin-left:230px; flex:1; display:flex; flex-direction:column; }
  .topbar { background:var(--surface); border-bottom:1px solid var(--border); padding:0 28px; height:62px; display:flex; align-items:center; justify-content:space-between; position:sticky; top:0; z-index:5; box-shadow:var(--shadow); }
  .page-title { font-family:'Playfair Display',serif; font-size:20px; color:var(--deep); }
  .btn { padding:9px 18px; border-radius:9px; font-family:'DM Sans',sans-serif; font-size:13px; font-weight:600; cursor:pointer; border:none; transition:all .18s; }
  .btn-primary { background:var(--rust); color:#fff; }
  .btn-primary:hover { background:#A8501E; }

  .content { padding:28px; }
  .flash { padding:12px 18px; border-radius:10px; font-size:14px; font-weight:500; margin-bottom:22px; display:flex; align-items:center; gap:10px; animation:fadeDown .3s; }
  @keyframes fadeDown { from{opacity:0;transform:translateY(-8px)} to{opacity:1;transform:none} }
  .flash.success { background:rgba(90,138,94,0.12); border:1px solid rgba(90,138,94,0.25); color:#2A6B30; }
  .flash.warning { background:rgba(196,154,60,0.10); border:1px solid rgba(196,154,60,0.25); color:#8A6010; }

  .date-nav { display:flex; align-items:center; gap:14px; margin-bottom:24px; }
  .date-btn { background:var(--surface); border:1px solid var(--border); border-radius:8px; padding:8px 14px; cursor:pointer; font-size:13px; color:var(--text-muted); transition:all .15s; font-family:'DM Sans',sans-serif; }
  .date-btn:hover { border-color:var(--brown); color:var(--text); }
  .date-display { font-family:'Playfair Display',serif; font-size:20px; color:var(--deep); }
  input[type=date] { background:var(--surface); border:1px solid var(--border); border-radius:8px; padding:8px 12px; font-family:'DM Sans',sans-serif; font-size:13px; color:var(--text); outline:none; cursor:pointer; }

  .grid-2 { display:grid; grid-template-columns:1fr 380px; gap:22px; }

  .card { background:var(--surface); border:1px solid var(--border); border-radius:14px; box-shadow:var(--shadow); overflow:hidden; }
  .card-head { padding:18px 20px 14px; border-bottom:1px solid var(--border); display:flex; align-items:center; justify-content:space-between; }
  .card-title { font-family:'Playfair Display',serif; font-size:16px; color:var(--deep); }

  /* RESERVATION LIST */
  .res-list { padding:8px 0; }
  .res-item {
    display:flex; align-items:center; padding:14px 20px; gap:16px;
    border-bottom:1px solid rgba(92,61,46,0.07); transition:background .15s; cursor:default;
    animation:fadeUp .3s ease both;
  }
  @keyframes fadeUp { from{opacity:0;transform:translateY(8px)} to{opacity:1;transform:none} }
  .res-item:last-child { border-bottom:none; }
  .res-item:hover { background:var(--bg); }
  .res-time { font-family:'Playfair Display',serif; font-size:20px; color:var(--deep); min-width:58px; text-align:center; }
  .res-time-sub { font-size:10px; color:var(--text-dim); text-align:center; text-transform:uppercase; letter-spacing:.08em; }
  .res-body { flex:1; }
  .res-name { font-weight:600; color:var(--deep); font-size:15px; }
  .res-meta { font-size:12px; color:var(--text-muted); margin-top:2px; }
  .res-table { display:inline-block; background:var(--bg2); border-radius:5px; padding:2px 8px; font-size:11px; font-weight:600; color:var(--brown); margin-right:6px; }
  .res-note { font-size:11px; color:var(--gold); font-style:italic; margin-top:3px; }

  .res-status { font-size:11px; font-weight:700; padding:4px 10px; border-radius:6px; text-transform:uppercase; letter-spacing:.06em; white-space:nowrap; }
  .rs-confirmed { background:rgba(74,144,217,0.12); color:#3A6FA8; }
  .rs-seated    { background:rgba(90,138,94,0.12);  color:var(--sage); }

  .res-actions { display:flex; gap:6px; }
  .ra-btn { padding:6px 12px; border-radius:7px; border:1px solid var(--border); background:none; font-family:'DM Sans',sans-serif; font-size:12px; font-weight:600; cursor:pointer; transition:all .15s; color:var(--text-muted); }
  .ra-btn.seat:hover { background:rgba(90,138,94,0.1); border-color:var(--sage); color:var(--sage); }
  .ra-btn.cancel:hover { background:rgba(168,50,50,0.08); border-color:var(--red); color:var(--red); }

  .empty-res { text-align:center; padding:40px 20px; color:var(--text-muted); }
  .empty-res .er-icon { font-size:40px; margin-bottom:10px; }

  /* ADD FORM */
  .form-group { margin-bottom:16px; }
  .form-label { display:block; font-size:11px; text-transform:uppercase; letter-spacing:.12em; color:var(--text-muted); font-weight:600; margin-bottom:6px; }
  .form-input, .form-select, .form-textarea {
    width:100%; background:var(--bg); border:1.5px solid var(--border); border-radius:9px;
    padding:10px 13px; font-family:'DM Sans',sans-serif; font-size:14px; color:var(--text); outline:none; transition:border-color .2s;
  }
  .form-input:focus, .form-select:focus, .form-textarea:focus { border-color:rgba(192,98,43,.5); }
  .form-textarea { resize:vertical; min-height:60px; }
  .form-row { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
  .add-form-body { padding:20px; }
  .add-form-footer { padding:14px 20px; border-top:1px solid var(--border); }
</style>
</head>
<body>
<aside>
  <div class="sidebar-logo">
    <div class="logo-main">Jiko House</div>
    <div class="logo-sub">Admin Console</div>
  </div>
  <nav>
    <div class="nav-section">Main</div>
    <a class="nav-item" href="admin_dashboard.php">📊 Dashboard</a>
    <a class="nav-item" href="waiter_pos.php">📋 Orders</a>
    <a class="nav-item" href="menu_management.php">🍽 Menu</a>
    <a class="nav-item active" href="reservations.php">🪑 Reservations</a>
    <div class="nav-section">Management</div>
    <a class="nav-item" href="inventory.php">📦 Inventory</a>
    <a class="nav-item" href="#">👤 Staff</a>
    <a class="nav-item" href="#">💳 Payments</a>
    <a class="nav-item" href="reports.php">📈 Reports</a>
  </nav>
  <div class="sidebar-footer">v1.0.0 · Jiko RMS</div>
</aside>

<div class="main">
  <div class="topbar">
    <h1 class="page-title">Reservations</h1>
  </div>
  <div class="content">
    <?php if ($message): ?><div class="flash <?= $msg_type ?>"><?= $message ?></div><?php endif; ?>

    <div class="date-nav">
      <a href="?date=<?= date('Y-m-d', strtotime($view_date . ' -1 day')) ?>" class="date-btn">← Prev</a>
      <div>
        <div class="date-display"><?= date('D, d M Y', strtotime($view_date)) ?></div>
        <?php if ($view_date === $today): ?><div style="font-size:11px;color:var(--rust);font-weight:600;text-transform:uppercase;letter-spacing:.1em">Today</div><?php endif; ?>
      </div>
      <a href="?date=<?= date('Y-m-d', strtotime($view_date . ' +1 day')) ?>" class="date-btn">Next →</a>
      <input type="date" value="<?= $view_date ?>" onchange="location.href='?date='+this.value">
    </div>

    <div class="grid-2">
      <!-- LEFT: RESERVATION LIST -->
      <div class="card">
        <div class="card-head">
          <span class="card-title">Schedule — <?= count($today_res) ?> bookings</span>
        </div>
        <?php if (empty($today_res)): ?>
        <div class="empty-res">
          <div class="er-icon">🗓</div>
          <div>No reservations for this date.</div>
        </div>
        <?php else: ?>
        <div class="res-list">
          <?php foreach ($today_res as $r): ?>
          <div class="res-item">
            <div>
              <div class="res-time"><?= date('H:i', strtotime($r['time'])) ?></div>
              <div class="res-time-sub"><?= $r['party'] ?> pax</div>
            </div>
            <div class="res-body">
              <div class="res-name"><?= htmlspecialchars($r['name']) ?></div>
              <div class="res-meta">
                <span class="res-table">Table <?= $r['table'] ?></span>
                <?= htmlspecialchars($r['phone']) ?>
              </div>
              <?php if ($r['notes']): ?><div class="res-note">📝 <?= htmlspecialchars($r['notes']) ?></div><?php endif; ?>
            </div>
            <span class="res-status rs-<?= $r['status'] ?>"><?= $r['status'] ?></span>
            <div class="res-actions">
              <?php if ($r['status'] === 'confirmed'): ?>
              <form method="POST">
                <input type="hidden" name="action" value="seat">
                <input type="hidden" name="res_id" value="<?= $r['id'] ?>">
                <input type="hidden" name="date" value="<?= $view_date ?>">
                <button type="submit" class="ra-btn seat">Seat</button>
              </form>
              <form method="POST" onsubmit="return confirm('Cancel this reservation?')">
                <input type="hidden" name="action" value="cancel">
                <input type="hidden" name="res_id" value="<?= $r['id'] ?>">
                <input type="hidden" name="date" value="<?= $view_date ?>">
                <button type="submit" class="ra-btn cancel">Cancel</button>
              </form>
              <?php endif; ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>

      <!-- RIGHT: ADD RESERVATION -->
      <div class="card">
        <div class="card-head"><span class="card-title">New Reservation</span></div>
        <form method="POST">
          <input type="hidden" name="action" value="add">
          <input type="hidden" name="date_redirect" value="<?= $view_date ?>">
          <div class="add-form-body">
            <div class="form-group">
              <label class="form-label">Guest Name *</label>
              <input type="text" name="name" class="form-input" placeholder="Full name" required>
            </div>
            <div class="form-row">
              <div class="form-group">
                <label class="form-label">Phone</label>
                <input type="text" name="phone" class="form-input" placeholder="+254 7xx xxx xxx">
              </div>
              <div class="form-group">
                <label class="form-label">Party Size *</label>
                <input type="number" name="party" class="form-input" placeholder="2" min="1" max="20" required>
              </div>
            </div>
            <div class="form-row">
              <div class="form-group">
                <label class="form-label">Date *</label>
                <input type="date" name="date" class="form-input" value="<?= $view_date ?>" required>
              </div>
              <div class="form-group">
                <label class="form-label">Time *</label>
                <input type="time" name="time" class="form-input" required>
              </div>
            </div>
            <div class="form-group">
              <label class="form-label">Table</label>
              <select name="table" class="form-select">
                <?php for ($t=1; $t<=8; $t++): ?><option value="<?= $t ?>">Table <?= $t ?></option><?php endfor; ?>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label">Special Notes</label>
              <textarea name="notes" class="form-textarea" placeholder="e.g. Birthday, dietary needs, seating preference…"></textarea>
            </div>
          </div>
          <div class="add-form-footer">
            <button type="submit" class="btn btn-primary" style="width:100%">Confirm Reservation</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>
</body>
</html>
