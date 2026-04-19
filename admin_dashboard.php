<?php
// admin_dashboard.php — Admin Dashboard
session_start();
require_once 'dbconfig.php';

if (empty($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin','cashier'])) {
    header('Location: login.php'); exit;
}

$pdo = getDB();
$activePage = 'dashboard';

// ── Helpers ───────────────────────────────────────────────────────────────────
function val($pdo, $sql, $p = []) {
    try {
        $s = $pdo->prepare($sql);
        $s->execute($p);
        $r = $s->fetch(PDO::FETCH_NUM);
        return $r ? $r[0] : 0;
    } catch (Exception $e) { return 0; }
}

function rows($pdo, $sql, $p = []) {
    try {
        $s = $pdo->prepare($sql);
        $s->execute($p);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { return []; }
}

// ── All-time counts ───────────────────────────────────────────────────────────
$totalUsers  = (int)val($pdo, "SELECT COUNT(*) FROM users");
$totalMenu   = (int)val($pdo, "SELECT COUNT(*) FROM menu");
$totalOrders = (int)val($pdo, "SELECT COUNT(*) FROM orders");
$totalRes    = (int)val($pdo, "SELECT COUNT(*) FROM reservations");
$totalTables = (int)val($pdo, "SELECT COUNT(*) FROM table_layout");
$totalCats   = (int)val($pdo, "SELECT COUNT(*) FROM categories");

// ── Today stats ───────────────────────────────────────────────────────────────
$revToday = (float)val($pdo, "SELECT IFNULL(SUM(total_price),0) FROM orders WHERE DATE(created_at)=CURDATE() AND status!='cancelled'");
$ordToday = (int)val($pdo,   "SELECT COUNT(*) FROM orders WHERE DATE(created_at)=CURDATE()");
$revYest  = (float)val($pdo, "SELECT IFNULL(SUM(total_price),0) FROM orders WHERE DATE(created_at)=CURDATE()-INTERVAL 1 DAY AND status!='cancelled'");
$ordYest  = (int)val($pdo,   "SELECT COUNT(*) FROM orders WHERE DATE(created_at)=CURDATE()-INTERVAL 1 DAY");
$revYest  = $revYest > 0 ? $revYest : 1;
$ordYest  = $ordYest > 0 ? $ordYest : 1;
$revChg   = round((($revToday - $revYest) / $revYest) * 100, 1);
$ordChg   = round((($ordToday - $ordYest) / $ordYest) * 100, 1);
$avgOrder = $ordToday > 0 ? round($revToday / $ordToday) : 0;
$activeTbl= (int)val($pdo, "SELECT COUNT(DISTINCT table_id) FROM orders WHERE DATE(created_at)=CURDATE() AND table_id IS NOT NULL");
$pending  = (int)val($pdo, "SELECT COUNT(*) FROM orders WHERE status='pending'");

// ── Top selling items ─────────────────────────────────────────────────────────
$topItems = rows($pdo,
    "SELECT m.name, SUM(oi.quantity) AS qty, SUM(oi.quantity*oi.price) AS revenue
     FROM order_items oi
     JOIN menu m ON oi.menu_item_id = m.id
     GROUP BY m.id, m.name
     ORDER BY revenue DESC LIMIT 5");
$maxRev = 1;
foreach ($topItems as $t)  { if ((float)$t['revenue'] > $maxRev) $maxRev = (float)$t['revenue']; }
foreach ($topItems as &$t) { $t['pct'] = round(((float)$t['revenue'] / $maxRev) * 100); }
unset($t);

// ── Revenue by hour (today) ───────────────────────────────────────────────────
$hourRows = rows($pdo,
    "SELECT HOUR(created_at) AS hr, SUM(total_price) AS rev
     FROM orders
     WHERE DATE(created_at) = CURDATE() AND status != 'cancelled'
     GROUP BY HOUR(created_at)
     ORDER BY hr ASC");
$revHours = [];
foreach ($hourRows as $r) {
    $revHours[str_pad((int)$r['hr'], 2, '0', STR_PAD_LEFT) . ':00'] = (float)$r['rev'];
}
$maxHr = !empty($revHours) ? max($revHours) : 1;

// ── Recent orders ─────────────────────────────────────────────────────────────
$recentOrders = rows($pdo,
    "SELECT o.id,
            IFNULL(tl.table_number, 0)  AS tbl,
            IFNULL(o.waiter_name, '—')  AS waiter,
            (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.id) AS items,
            o.total_price               AS total,
            IFNULL(o.status, 'pending') AS status,
            TIME(o.created_at)          AS time
     FROM orders o
     LEFT JOIN table_layout tl ON o.table_id = tl.id
     ORDER BY o.created_at DESC
     LIMIT 8");

// ── Low stock ─────────────────────────────────────────────────────────────────
$lowStock      = [];
$lowStockCount = 0;
try {
    $__ls = $pdo->query(
        "SELECT name, quantity+0 as quantity, min_threshold+0 as min_threshold, unit
         FROM inventory
         WHERE min_threshold > 0 AND quantity < min_threshold
         ORDER BY (quantity / NULLIF(min_threshold,0)) ASC
         LIMIT 5"
    );
    $lowStock      = $__ls->fetchAll(PDO::FETCH_ASSOC);
    $lowStockCount = is_array($lowStock) ? count($lowStock) : 0;
    if (!is_array($lowStock)) $lowStock = [];
} catch (Exception $e) {
    $lowStock = []; $lowStockCount = 0;
}






// ── Staff list ────────────────────────────────────────────────────────────────
$staffList = rows($pdo,
    "SELECT name, role, status
     FROM users
     WHERE role != 'customer'
     ORDER BY FIELD(status,'active','inactive'), name");

$today = date('D, d M Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Jiko House — Dashboard</title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600&family=DM+Sans:wght@300;400;500;600&family=DM+Mono&display=swap" rel="stylesheet">
<link rel="stylesheet" href="_styles.css">
<style>
  /* Page-specific additions — base styles live in _styles.css */
  .alert-banner      { background:var(--red-bg); border:1px solid rgba(168,50,50,.22); border-radius:12px; padding:14px 18px; margin-bottom:20px; display:flex; align-items:flex-start; gap:14px; }
  .alert-icon        { font-size:20px; flex-shrink:0; margin-top:1px; }
  .alert-body        { flex:1; min-width:0; }
  .alert-title       { font-size:13px; font-weight:600; color:var(--red); margin-bottom:8px; }
  .alert-chips       { display:flex; gap:7px; flex-wrap:wrap; }
  .alert-chip        { background:rgba(168,50,50,.12); color:var(--red); font-size:11px; font-weight:600; padding:3px 10px; border-radius:5px; text-decoration:none; white-space:nowrap; }
  .alert-chip:hover  { background:rgba(168,50,50,.2); }
  .alert-action      { flex-shrink:0; font-size:12px; color:var(--red); font-weight:600; text-decoration:none; padding:4px 12px; border:1px solid rgba(168,50,50,.25); border-radius:7px; white-space:nowrap; align-self:center; }
  .alert-action:hover{ background:rgba(168,50,50,.08); }

  .summary-row       { display:grid; grid-template-columns:repeat(6,1fr); gap:12px; margin-bottom:20px; }
  .si                { background:var(--surface); border:1px solid var(--border); border-radius:11px; padding:13px 15px; box-shadow:var(--shadow); }
  .si-num            { font-family:'Playfair Display',serif; font-size:22px; color:var(--deep); line-height:1; }
  .si-label          { font-size:10px; color:var(--text-muted); margin-top:3px; }

  .stats-row         { display:grid; grid-template-columns:repeat(4,1fr); gap:16px; margin-bottom:22px; }
  .kpi-card          { background:var(--surface); border:1px solid var(--border); border-radius:14px; padding:20px 20px 16px; box-shadow:var(--shadow); transition:transform .18s, box-shadow .18s; }
  .kpi-card:hover    { transform:translateY(-2px); box-shadow:var(--shadow-md); }
  .kpi-top           { display:flex; align-items:flex-start; justify-content:space-between; margin-bottom:12px; }
  .kpi-icon          { font-size:24px; }
  .kpi-chg           { font-size:11px; font-weight:700; padding:3px 8px; border-radius:5px; }
  .kpi-chg.up        { background:var(--sage-bg); color:var(--sage); }
  .kpi-chg.down      { background:var(--red-bg); color:var(--red); }
  .kpi-chg.neutral   { background:var(--bg2); color:var(--text-muted); }
  .kpi-val           { font-family:'Playfair Display',serif; font-size:26px; color:var(--deep); line-height:1; margin-bottom:3px; }
  .kpi-lbl           { font-size:12px; color:var(--text-muted); }

  .bar-chart         { display:flex; flex-direction:column; gap:10px; }
  .bar-row           { display:flex; align-items:center; gap:12px; font-size:13px; }
  .bar-label         { width:130px; font-weight:500; color:var(--text); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; flex-shrink:0; }
  .bar-track         { flex:1; background:var(--bg2); border-radius:4px; height:8px; overflow:hidden; }
  .bar-fill          { height:100%; background:var(--rust); border-radius:4px; transition:width .9s ease; }
  .bar-val           { min-width:85px; text-align:right; font-size:12px; color:var(--text-muted); font-family:'DM Mono',monospace; }

  .rev-chart         { height:130px; display:flex; align-items:flex-end; gap:5px; padding-top:14px; }
  .rev-wrap          { flex:1; display:flex; flex-direction:column; align-items:center; gap:4px; min-width:0; }
  .rev-bar           { width:100%; background:var(--gold); border-radius:4px 4px 0 0; min-height:4px; cursor:pointer; }
  .rev-bar:hover     { filter:brightness(1.12); }
  .rev-hr            { font-size:9px; color:var(--text-dim); overflow:hidden; white-space:nowrap; max-width:100%; text-align:center; }
  .rev-val           { font-size:9px; color:var(--text-muted); font-family:'DM Mono',monospace; }
  .card-sub          { font-size:12px; color:var(--text-muted); }

  .stock-low         { color:var(--red); font-weight:600; }
  .fw-bold           { font-weight:600; color:var(--deep); }
  .fw-medium         { font-weight:500; color:var(--deep); }
  .sm                { font-size:12px; }
</style>
</head>
<body>

<?php include '_sidebar.php'; ?>

<div class="main">

  <div class="topbar">
    <h1 class="page-title">Dashboard</h1>
    <div class="topbar-right">
      <span class="date-chip">📅 <?= htmlspecialchars($today) ?></span>
      <a href="reports.php" class="btn btn-secondary btn-sm">📈 Reports</a>
    </div>
  </div>

  <div class="content">

    <!-- ── LOW STOCK ALERT ────────────────────────────────────────────────── -->
  <?php if ($lowStockCount > 0 && is_array($lowStock)): ?>
<div class="alert-banner">
  <span class="alert-icon">⚠️</span>
  <div class="alert-body">
    <div class="alert-title">
      <?= $lowStockCount ?> item<?= $lowStockCount > 1 ? 's' : '' ?> running low — restock needed
    </div>

    <div class="alert-chips">
      <?php foreach ($lowStock as $ls): ?>
        <?php 
          $chip = htmlspecialchars($ls['name']) . ' — ' 
                . htmlspecialchars($ls['quantity']) . '/' 
                . htmlspecialchars($ls['min_threshold']) . ' ' 
                . htmlspecialchars($ls['unit']); 
        ?>
        <a href="inventory.php" class="alert-chip"><?= $chip ?></a>
      <?php endforeach; ?>
    </div>
  </div>
  <a href="inventory.php" class="alert-action">Manage →</a>
</div>
<?php endif; ?>



    <!-- ── ALL-TIME SUMMARY ───────────────────────────────────────────────── -->
    <div class="summary-row">
      <div class="si"><div class="si-num"><?= number_format($totalUsers) ?></div><div class="si-label">Users</div></div>
      <div class="si"><div class="si-num"><?= number_format($totalMenu) ?></div><div class="si-label">Menu items</div></div>
      <div class="si"><div class="si-num"><?= number_format($totalCats) ?></div><div class="si-label">Categories</div></div>
      <div class="si"><div class="si-num"><?= number_format($totalOrders) ?></div><div class="si-label">All orders</div></div>
      <div class="si"><div class="si-num"><?= number_format($totalRes) ?></div><div class="si-label">Reservations</div></div>
      <div class="si"><div class="si-num"><?= $activeTbl ?>/<?= $totalTables ?></div><div class="si-label">Tables active</div></div>
    </div>

    <!-- ── TODAY KPI CARDS ────────────────────────────────────────────────── -->
    <div class="stats-row">
      <div class="kpi-card">
        <div class="kpi-top">
          <span class="kpi-icon">💰</span>
          <span class="kpi-chg <?= $revChg >= 0 ? 'up' : 'down' ?>"><?= ($revChg>=0?'+':'').$revChg ?>% vs yesterday</span>
        </div>
        <div class="kpi-val">KSh <?= number_format($revToday) ?></div>
        <div class="kpi-lbl">Revenue today</div>
      </div>
      <div class="kpi-card">
        <div class="kpi-top">
          <span class="kpi-icon">📋</span>
          <span class="kpi-chg <?= $ordChg >= 0 ? 'up' : 'down' ?>"><?= ($ordChg>=0?'+':'').$ordChg ?>% vs yesterday</span>
        </div>
        <div class="kpi-val"><?= number_format($ordToday) ?></div>
        <div class="kpi-lbl">Orders today</div>
      </div>
      <div class="kpi-card">
        <div class="kpi-top">
          <span class="kpi-icon">📊</span>
          <span class="kpi-chg neutral">avg</span>
        </div>
        <div class="kpi-val">KSh <?= number_format($avgOrder) ?></div>
        <div class="kpi-lbl">Avg order value</div>
      </div>
      <div class="kpi-card">
        <div class="kpi-top">
          <span class="kpi-icon">⏳</span>
          <span class="kpi-chg neutral">open</span>
        </div>
        <div class="kpi-val"><?= number_format($pending) ?></div>
        <div class="kpi-lbl">Pending orders</div>
      </div>
    </div>

    <!-- ── CHARTS ─────────────────────────────────────────────────────────── -->
    <div class="grid-2">

      <div class="card">
        <div class="card-head">
          <span class="card-title">Top Selling Items</span>
          <a href="reports.php" class="card-action">Full report →</a>
        </div>
        <div class="card-body">
          <?php if (empty($topItems)): ?>
          <p class="no-data">No sales data yet.</p>
          <?php else: ?>
          <div class="bar-chart">
            <?php foreach ($topItems as $it): ?>
            <div class="bar-row">
              <span class="bar-label" title="<?= htmlspecialchars($it['name']) ?>"><?= htmlspecialchars($it['name']) ?></span>
              <div class="bar-track">
                <div class="bar-fill" style="width:<?= (int)$it['pct'] ?>%"></div>
              </div>
              <span class="bar-val">KSh <?= number_format($it['revenue']) ?></span>
            </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="card">
        <div class="card-head">
          <span class="card-title">Revenue by Hour</span>
          <span class="card-sub">Today</span>
        </div>
        <div class="card-body">
          <?php if (empty($revHours)): ?>
          <p class="no-data">No revenue recorded today yet.</p>
          <?php else: ?>
          <div class="rev-chart">
            <?php foreach ($revHours as $hr => $val): ?>
            <div class="rev-wrap">
              <div class="rev-val"><?= $val >= 1000 ? round($val/1000,1).'k' : (int)$val ?></div>
              <div class="rev-bar" style="height:<?= round(($val/$maxHr)*110) ?>px" title="<?= htmlspecialchars($hr) ?> — KSh <?= number_format($val) ?>"></div>
              <div class="rev-hr"><?= htmlspecialchars($hr) ?></div>
            </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </div>
      </div>

    </div>

    <!-- ── RECENT ORDERS ──────────────────────────────────────────────────── -->
    <div class="grid-full">
      <div class="card">
        <div class="card-head">
          <span class="card-title">Recent Orders</span>
          <a href="OrderController.php" class="card-action">View all →</a>
        </div>
        <table>
          <thead>
            <tr>
              <th>ID</th><th>Table</th><th>Waiter</th>
              <th>Items</th><th>Total</th><th>Status</th><th>Time</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($recentOrders)): ?>
            <tr><td colspan="7" class="empty-cell">No orders yet.</td></tr>
            <?php else: ?>
            <?php foreach ($recentOrders as $o):
              $sc = 's-' . preg_replace('/[^a-z]/', '', strtolower($o['status']));
            ?>
            <tr>
              <td class="mono muted">#<?= (int)$o['id'] ?></td>
              <td><?= (int)$o['tbl'] > 0 ? 'Table ' . (int)$o['tbl'] : '—' ?></td>
              <td><?= htmlspecialchars($o['waiter']) ?></td>
              <td><?= (int)$o['items'] ?></td>
              <td class="mono fw-bold">KSh <?= number_format($o['total']) ?></td>
              <td><span class="badge <?= $sc ?>"><?= htmlspecialchars($o['status']) ?></span></td>
              <td class="mono muted sm"><?= htmlspecialchars($o['time']) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- ── STAFF + LOW STOCK ──────────────────────────────────────────────── -->
    <div class="grid-2">

      <!-- Staff on Duty -->
      <div class="card">
        <div class="card-head">
          <span class="card-title">Staff on Duty</span>
          <a href="staff.php" class="card-action">Manage →</a>
        </div>
        <table>
          <thead>
            <tr><th>Name</th><th>Role</th><th>Status</th></tr>
          </thead>
          <tbody>
            <?php if (empty($staffList)): ?>
            <tr><td colspan="3" class="empty-cell">No staff records yet.</td></tr>
            <?php else: ?>
            <?php foreach ($staffList as $s): ?>
            <tr>
              <td class="fw-medium"><?= htmlspecialchars($s['name']) ?></td>
              <td class="muted sm"><?= ucfirst(str_replace('_', ' ', $s['role'])) ?></td>
              <td>
                <span class="staff-dot ss-<?= htmlspecialchars($s['status']) ?>"></span>
                <?= ucfirst(htmlspecialchars($s['status'])) ?>
              </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

     <!-- Low Stock Summary Card -->
<div class="card">
  <div class="card-head">
    <span class="card-title">Low Stock Items</span>
    <a href="inventory.php" class="card-action">Manage →</a>
  </div>
  <?php if ($lowStockCount > 0): ?>
  <table>
    <thead>
      <tr>
        <th>Item</th>
        <th>In Stock</th>
        <th>Minimum</th>
        <th>Status</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($lowStock as $ls):
        $pct = $ls['min_threshold'] > 0 ? ($ls['quantity'] / $ls['min_threshold']) : 0;
        $statusLabel = $pct <= 0 ? 'Out' : ($pct < 0.5 ? 'Critical' : 'Low');
        $statusColor = $pct <= 0 ? '#c0392b' : ($pct < 0.5 ? '#e74c3c' : '#e67e22');
      ?>
      <tr>
        <td style="font-weight:600;color:var(--deep)"><?= htmlspecialchars($ls['name']) ?></td>
        <td class="mono" style="color:var(--red);font-weight:600"><?= (float)$ls['quantity'] ?> <?= htmlspecialchars($ls['unit']) ?></td>
        <td class="mono muted"><?= (float)$ls['min_threshold'] ?> <?= htmlspecialchars($ls['unit']) ?></td>
        <td><span class="badge" style="background:<?= $statusColor ?>20;color:<?= $statusColor ?>;font-size:11px;font-weight:700"><?= $statusLabel ?></span></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php else: ?>
  <div class="card-body">
    <p class="no-data">All items are sufficiently stocked.</p>
  </div>
  <?php endif; ?>
</div>

    </div><!-- /.grid-2 -->

  </div><!-- /.content -->
</div><!-- /.main -->

</body>
</html>