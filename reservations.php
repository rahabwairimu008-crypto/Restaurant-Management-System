<?php
// reservations.php — Table Reservations
session_start();
require_once 'dbconfig.php';

if (empty($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin','cashier','waiter'])) {
    header('Location: login.php'); exit;
}

$pdo        = getDB();
$activePage = 'reservations';
$today      = date('Y-m-d');
$msg        = '';
$msgType    = '';

// ── ACTIONS ───────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $name   = trim($_POST['name']   ?? '');
        $phone  = trim($_POST['phone']  ?? '');
        $party  = (int)($_POST['party'] ?? 1);
        $date   = $_POST['date']        ?? $today;
        $time   = $_POST['time']        ?? '12:00';
        $table  = (int)($_POST['table'] ?? 0) ?: null;
        $notes  = trim($_POST['notes']  ?? '');

        if (!$name || !$date || !$time) {
            $msg = 'Name, date and time are required.'; $msgType = 'error';
        } else {
            $pdo->prepare(
                "INSERT INTO reservations (table_id, guest_name, guest_phone, party_size, reservation_date, reservation_time, notes, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 'confirmed')"
            )->execute([$table, $name, $phone, $party, $date, $time, $notes]);
            $msg = '✅ Reservation for ' . htmlspecialchars($name) . ' added.';
            $msgType = 'success';
        }
    }

    if ($action === 'seat') {
        $id = (int)$_POST['res_id'];
        $pdo->prepare("UPDATE reservations SET status='seated' WHERE id=?")->execute([$id]);
        $msg = '✅ Guest seated.'; $msgType = 'success';
    }

    if ($action === 'cancel') {
        $id = (int)$_POST['res_id'];
        $pdo->prepare("UPDATE reservations SET status='cancelled' WHERE id=?")->execute([$id]);
        $msg = 'Reservation cancelled.'; $msgType = 'warning';
    }

    if ($action === 'no_show') {
        $id = (int)$_POST['res_id'];
        $pdo->prepare("UPDATE reservations SET status='no_show' WHERE id=?")->execute([$id]);
        $msg = 'Marked as no-show.'; $msgType = 'warning';
    }
}

// ── FETCH DATA ────────────────────────────────────────────────────────────────
$view_date = $_GET['date'] ?? $today;

$reservations = $pdo->prepare(
    "SELECT r.*, tl.table_number
     FROM reservations r
     LEFT JOIN table_layout tl ON r.table_id = tl.id
     WHERE r.reservation_date = ?
     ORDER BY r.reservation_time ASC"
);
$reservations->execute([$view_date]);
$reservations = $reservations->fetchAll(PDO::FETCH_ASSOC);

// Active = not cancelled/no_show
$activeRes = array_filter($reservations, fn($r) => !in_array($r['status'], ['cancelled','no_show']));

// Summary counts
$totalToday     = count($reservations);
$confirmedCount = count(array_filter($reservations, fn($r) => $r['status'] === 'confirmed'));
$seatedCount    = count(array_filter($reservations, fn($r) => $r['status'] === 'seated'));
$cancelledCount = count(array_filter($reservations, fn($r) => in_array($r['status'], ['cancelled','no_show'])));

// Tables for dropdown
$tables = $pdo->query("SELECT id, table_number, capacity FROM table_layout ORDER BY table_number")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Jiko House — Reservations</title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600&family=DM+Sans:wght@300;400;500;600&family=DM+Mono&display=swap" rel="stylesheet">
<link rel="stylesheet" href="_styles.css">
<style>
  .date-nav       { display:flex; align-items:center; gap:14px; margin-bottom:24px; flex-wrap:wrap; }
  .date-btn       { background:var(--surface); border:1px solid var(--border); border-radius:8px; padding:8px 16px; cursor:pointer; font-size:13px; color:var(--text-muted); transition:all .15s; font-family:'DM Sans',sans-serif; text-decoration:none; }
  .date-btn:hover { border-color:var(--rust); color:var(--text); }
  .date-display   { font-family:'Playfair Display',serif; font-size:20px; color:var(--deep); }
  .today-chip     { font-size:11px; color:var(--rust); font-weight:700; text-transform:uppercase; letter-spacing:.1em; }

  .stats-strip    { display:grid; grid-template-columns:repeat(4,1fr); gap:12px; margin-bottom:22px; }
  .ss-card        { background:var(--surface); border:1px solid var(--border); border-radius:11px; padding:14px 18px; box-shadow:var(--shadow); }
  .ss-num         { font-family:'Playfair Display',serif; font-size:26px; color:var(--deep); line-height:1; }
  .ss-label       { font-size:11px; color:var(--text-muted); margin-top:3px; }

  .grid-res       { display:grid; grid-template-columns:1fr 380px; gap:22px; }

  .res-list       { padding:6px 0; }
  .res-item       { display:flex; align-items:flex-start; padding:16px 20px; gap:16px; border-bottom:1px solid var(--border); transition:background .15s; }
  .res-item:last-child { border-bottom:none; }
  .res-item:hover { background:var(--bg2); }

  .res-time-col   { text-align:center; min-width:54px; }
  .res-time       { font-family:'Playfair Display',serif; font-size:19px; color:var(--deep); line-height:1; }
  .res-pax        { font-size:10px; color:var(--text-dim); text-transform:uppercase; letter-spacing:.08em; margin-top:2px; }

  .res-body       { flex:1; min-width:0; }
  .res-name       { font-weight:600; color:var(--deep); font-size:15px; }
  .res-meta       { font-size:12px; color:var(--text-muted); margin-top:3px; display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
  .res-table-chip { background:var(--bg2); border-radius:5px; padding:2px 8px; font-size:11px; font-weight:600; color:var(--brown); }
  .res-note       { font-size:11px; color:var(--gold); font-style:italic; margin-top:4px; }

  .res-right      { display:flex; flex-direction:column; align-items:flex-end; gap:8px; flex-shrink:0; }
  .res-status     { font-size:11px; font-weight:700; padding:3px 10px; border-radius:6px; text-transform:uppercase; letter-spacing:.06em; white-space:nowrap; }
  .rs-confirmed   { background:rgba(74,144,217,.12); color:#3A6FA8; }
  .rs-seated      { background:rgba(90,138,94,.12);  color:var(--sage); }
  .rs-cancelled   { background:rgba(168,50,50,.08);  color:var(--red); }
  .rs-no_show     { background:rgba(168,50,50,.08);  color:var(--red); }

  .res-actions    { display:flex; gap:6px; }
  .ra-btn         { padding:5px 11px; border-radius:7px; border:1px solid var(--border); background:none; font-family:'DM Sans',sans-serif; font-size:12px; font-weight:600; cursor:pointer; transition:all .15s; color:var(--text-muted); }
  .ra-btn.seat:hover    { background:rgba(90,138,94,.1);   border-color:var(--sage); color:var(--sage); }
  .ra-btn.cancel:hover  { background:rgba(168,50,50,.08);  border-color:var(--red);  color:var(--red); }
  .ra-btn.noshow:hover  { background:rgba(196,154,60,.1);  border-color:var(--gold); color:var(--gold); }

  .empty-res      { text-align:center; padding:50px 20px; color:var(--text-muted); }
  .empty-res .er-icon { font-size:42px; margin-bottom:12px; }
</style>
</head>
<body>

<?php include '_sidebar.php'; ?>

<div class="main">
  <div class="topbar">
    <h1 class="page-title">Reservations</h1>
    <div class="topbar-right">
      <span class="date-chip">📅 <?= date('D, d M Y') ?></span>
    </div>
  </div>

  <div class="content">
    <?php if ($msg): ?>
    <div class="flash <?= $msgType ?>"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>

    <!-- DATE NAV -->
    <div class="date-nav">
      <a href="?date=<?= date('Y-m-d', strtotime($view_date . ' -1 day')) ?>" class="date-btn">← Prev</a>
      <div>
        <div class="date-display"><?= date('D, d M Y', strtotime($view_date)) ?></div>
        <?php if ($view_date === $today): ?><div class="today-chip">Today</div><?php endif; ?>
      </div>
      <a href="?date=<?= date('Y-m-d', strtotime($view_date . ' +1 day')) ?>" class="date-btn">Next →</a>
      <input type="date" value="<?= $view_date ?>" onchange="location.href='?date='+this.value"
             style="background:var(--surface);border:1px solid var(--border);border-radius:8px;padding:8px 12px;font-family:'DM Sans',sans-serif;font-size:13px;color:var(--text);outline:none;cursor:pointer;">
    </div>

    <!-- STATS STRIP -->
    <div class="stats-strip">
      <div class="ss-card"><div class="ss-num"><?= $totalToday ?></div><div class="ss-label">Total bookings</div></div>
      <div class="ss-card"><div class="ss-num" style="color:var(--rust)"><?= $confirmedCount ?></div><div class="ss-label">Confirmed</div></div>
      <div class="ss-card"><div class="ss-num" style="color:var(--sage)"><?= $seatedCount ?></div><div class="ss-label">Seated</div></div>
      <div class="ss-card"><div class="ss-num" style="color:var(--text-muted)"><?= $cancelledCount ?></div><div class="ss-label">Cancelled / No-show</div></div>
    </div>

    <div class="grid-res">

      <!-- LEFT: RESERVATION LIST -->
      <div class="card">
        <div class="card-head">
          <span class="card-title">Schedule — <?= count($activeRes) ?> active booking<?= count($activeRes) != 1 ? 's' : '' ?></span>
        </div>

        <?php if (empty($reservations)): ?>
        <div class="empty-res">
          <div class="er-icon">🗓</div>
          <div style="font-family:'Playfair Display',serif;font-size:17px;color:var(--deep);margin-bottom:6px">No reservations for this date</div>
          <div style="font-size:13px">Use the form to add one.</div>
        </div>
        <?php else: ?>
        <div class="res-list">
          <?php foreach ($reservations as $r): ?>
          <div class="res-item">
            <div class="res-time-col">
              <div class="res-time"><?= date('H:i', strtotime($r['reservation_time'])) ?></div>
              <div class="res-pax"><?= (int)$r['party_size'] ?> pax</div>
            </div>
            <div class="res-body">
              <div class="res-name"><?= htmlspecialchars($r['guest_name']) ?></div>
              <div class="res-meta">
                <?php if ($r['table_number']): ?>
                <span class="res-table-chip">Table <?= (int)$r['table_number'] ?></span>
                <?php endif; ?>
                <?= htmlspecialchars($r['guest_phone'] ?? '') ?>
              </div>
              <?php if (!empty($r['notes'])): ?>
              <div class="res-note">📝 <?= htmlspecialchars($r['notes']) ?></div>
              <?php endif; ?>
            </div>
            <div class="res-right">
              <span class="res-status rs-<?= $r['status'] ?>"><?= ucfirst(str_replace('_',' ',$r['status'])) ?></span>
              <?php if ($r['status'] === 'confirmed'): ?>
              <div class="res-actions">
                <form method="POST" style="display:inline">
                  <input type="hidden" name="action" value="seat">
                  <input type="hidden" name="res_id" value="<?= (int)$r['id'] ?>">
                  <button type="submit" class="ra-btn seat">✓ Seat</button>
                </form>
                <form method="POST" style="display:inline" onsubmit="return confirm('Mark as no-show?')">
                  <input type="hidden" name="action" value="no_show">
                  <input type="hidden" name="res_id" value="<?= (int)$r['id'] ?>">
                  <button type="submit" class="ra-btn noshow">No-show</button>
                </form>
                <form method="POST" style="display:inline" onsubmit="return confirm('Cancel this reservation?')">
                  <input type="hidden" name="action" value="cancel">
                  <input type="hidden" name="res_id" value="<?= (int)$r['id'] ?>">
                  <button type="submit" class="ra-btn cancel">✕ Cancel</button>
                </form>
              </div>
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
          <div style="padding:20px">

            <div class="form-group">
              <label class="form-label">Guest Name *</label>
              <input type="text" name="name" class="form-input" placeholder="Full name" required>
            </div>

            <div class="form-row">
              <div class="form-group">
                <label class="form-label">Phone</label>
                <input type="tel" name="phone" class="form-input" placeholder="+254 7xx xxx xxx">
              </div>
              <div class="form-group">
                <label class="form-label">Party Size *</label>
                <input type="number" name="party" class="form-input" placeholder="2" min="1" max="30" required>
              </div>
            </div>

            <div class="form-row">
              <div class="form-group">
                <label class="form-label">Date *</label>
                <input type="date" name="date" class="form-input" value="<?= htmlspecialchars($view_date) ?>" required>
              </div>
              <div class="form-group">
                <label class="form-label">Time *</label>
                <input type="time" name="time" class="form-input" required>
              </div>
            </div>

            <div class="form-group">
              <label class="form-label">Table</label>
              <select name="table" class="form-select">
                <option value="">— No specific table —</option>
                <?php foreach ($tables as $tbl): ?>
                <option value="<?= (int)$tbl['id'] ?>">
                  Table <?= (int)$tbl['table_number'] ?> (<?= (int)$tbl['capacity'] ?> seats)
                </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="form-group">
              <label class="form-label">Special Notes</label>
              <textarea name="notes" class="form-textarea" placeholder="e.g. Birthday, dietary needs, seating preference…"></textarea>
            </div>

          </div>
          <div style="padding:14px 20px;border-top:1px solid var(--border)">
            <button type="submit" class="btn btn-primary" style="width:100%">Confirm Reservation →</button>
          </div>
        </form>
      </div>

    </div><!-- /.grid-res -->
  </div><!-- /.content -->
</div><!-- /.main -->

</body>
</html>
