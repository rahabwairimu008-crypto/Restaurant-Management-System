<?php
session_start();
require_once 'dbconfig.php';
if (empty($_SESSION['user_id']) || !in_array($_SESSION['role'],['admin','cashier'])) {
    header('Location: login.php'); exit;
}
$pdo = getDB();
$activePage = 'reports';

$period = $_GET['period'] ?? 'today';
$dateFrom = match($period) {
    'week'  => date('Y-m-d', strtotime('-6 days')),
    'month' => date('Y-m-d', strtotime('-29 days')),
    default => date('Y-m-d'),
};
$dateTo = date('Y-m-d');

// Summary
$row = $pdo->prepare("SELECT IFNULL(SUM(total_price),0) AS rev, COUNT(*) AS cnt
    FROM orders WHERE DATE(created_at) BETWEEN ? AND ? AND status NOT IN ('cancelled')");
$row->execute([$dateFrom, $dateTo]); $row = $row->fetch();
$rev = (float)$row['rev']; $cnt = (int)$row['cnt'];
$avg = $cnt > 0 ? round($rev/$cnt) : 0;

// Prev period for comparison
$daysDiff = (strtotime($dateTo)-strtotime($dateFrom))/86400 + 1;
$prevFrom = date('Y-m-d', strtotime($dateFrom." -$daysDiff days"));
$prevTo   = date('Y-m-d', strtotime($dateTo." -$daysDiff days"));
$prevRow  = $pdo->prepare("SELECT IFNULL(SUM(total_price),0) AS rev, COUNT(*) AS cnt FROM orders WHERE DATE(created_at) BETWEEN ? AND ? AND status NOT IN ('cancelled')");
$prevRow->execute([$prevFrom,$prevTo]); $prevRow = $prevRow->fetch();
$prevRev = max((float)$prevRow['rev'],1); $prevCnt = max((int)$prevRow['cnt'],1);
$revChg  = round((($rev-$prevRev)/$prevRev)*100,1);
$cntChg  = round((($cnt-$prevCnt)/$prevCnt)*100,1);

// Top items
$topItems = $pdo->prepare("SELECT m.name, SUM(oi.quantity) AS qty, SUM(oi.quantity*oi.price) AS revenue
    FROM order_items oi JOIN orders o ON oi.order_id=o.id JOIN menu m ON oi.menu_item_id=m.id
    WHERE DATE(o.created_at) BETWEEN ? AND ? AND o.status NOT IN ('cancelled')
    GROUP BY m.id, m.name ORDER BY revenue DESC LIMIT 8");
$topItems->execute([$dateFrom,$dateTo]); $topItems = $topItems->fetchAll();
$maxRev = 1; foreach ($topItems as $t) { if ($t['revenue']>$maxRev) $maxRev=$t['revenue']; }
foreach ($topItems as &$t) { $t['pct']=round(($t['revenue']/$maxRev)*100); } unset($t);

// By category
$byCat = $pdo->prepare("SELECT c.name, SUM(oi.quantity*oi.price) AS revenue
    FROM order_items oi JOIN orders o ON oi.order_id=o.id
    JOIN menu m ON oi.menu_item_id=m.id JOIN categories c ON m.category_id=c.id
    WHERE DATE(o.created_at) BETWEEN ? AND ? AND o.status NOT IN ('cancelled')
    GROUP BY c.id, c.name ORDER BY revenue DESC");
$byCat->execute([$dateFrom,$dateTo]); $byCat=$byCat->fetchAll();
$maxCat = 1; foreach ($byCat as $b) { if ($b['revenue']>$maxCat) $maxCat=$b['revenue']; }

// Revenue over time
$byDay = $pdo->prepare("SELECT DATE(created_at) AS day, SUM(total_price) AS rev
    FROM orders WHERE DATE(created_at) BETWEEN ? AND ? AND status NOT IN ('cancelled')
    GROUP BY DATE(created_at) ORDER BY day");
$byDay->execute([$dateFrom,$dateTo]); $byDay=$byDay->fetchAll();
$maxDay=1; foreach ($byDay as $d) { if ($d['rev']>$maxDay) $maxDay=$d['rev']; }

// Payment methods breakdown
$payMethods = $pdo->prepare("SELECT method, SUM(amount) AS total, COUNT(*) AS cnt
    FROM payments WHERE DATE(created_at) BETWEEN ? AND ? AND status='completed'
    GROUP BY method ORDER BY total DESC");
$payMethods->execute([$dateFrom,$dateTo]); $payMethods=$payMethods->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Jiko House — Reports</title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600&family=DM+Sans:wght@400;500;600&family=DM+Mono&display=swap" rel="stylesheet">
<style><?php include '_styles.css'; ?>
.period-tabs{display:flex;gap:6px;margin-bottom:24px;}
.ptab{padding:8px 20px;border-radius:9px;border:1.5px solid var(--border);background:var(--surface);font-size:13px;font-weight:600;color:var(--text-muted);text-decoration:none;transition:all .18s;}
.ptab:hover{border-color:var(--brown);color:var(--text);}
.ptab.active{background:var(--deep);color:#fff;border-color:var(--deep);}
.kpi-row{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:24px;}
.kpi-box{background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:20px;box-shadow:var(--shadow);}
.kb-top{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:12px;}
.kb-icon{font-size:22px;}
.kb-chg{font-size:11px;font-weight:700;padding:3px 8px;border-radius:5px;}
.kb-chg.up{background:var(--sage-bg);color:var(--sage);}
.kb-chg.down{background:var(--red-bg);color:var(--red);}
.kb-val{font-family:'Playfair Display',serif;font-size:26px;color:var(--deep);line-height:1;margin-bottom:4px;}
.kb-lbl{font-size:12px;color:var(--text-muted);}
.day-chart{height:120px;display:flex;align-items:flex-end;gap:5px;padding-top:10px;}
.day-col{flex:1;display:flex;flex-direction:column;align-items:center;gap:4px;}
.day-bar{width:100%;background:var(--gold);border-radius:4px 4px 0 0;min-height:4px;cursor:pointer;transition:filter .15s;}
.day-bar:hover{filter:brightness(1.12);}
.day-lbl{font-size:9px;color:var(--text-dim);text-align:center;}
.pay-row{display:flex;align-items:center;justify-content:space-between;padding:10px 0;border-bottom:1px solid var(--border);font-size:13px;}
.pay-row:last-child{border-bottom:none;}
.pay-method{font-weight:600;text-transform:uppercase;font-size:12px;background:var(--bg2);padding:3px 9px;border-radius:5px;color:var(--brown);}
.rank-num{font-family:'Playfair Display',serif;font-size:18px;color:var(--gold);min-width:22px;}
</style>
</head>
<body>
<?php include '_sidebar.php'; ?>
<div class="main">
  <div class="topbar">
    <h1 class="page-title">Reports & Analytics</h1>
    <button class="btn btn-secondary btn-sm" onclick="window.print()">↓ Print</button>
  </div>
  <div class="content">

    <div class="period-tabs">
      <a href="?period=today" class="ptab <?= $period==='today'?'active':'' ?>">Today</a>
      <a href="?period=week"  class="ptab <?= $period==='week'?'active':'' ?>">Last 7 Days</a>
      <a href="?period=month" class="ptab <?= $period==='month'?'active':'' ?>">Last 30 Days</a>
    </div>

    <div class="kpi-row">
      <div class="kpi-box">
        <div class="kb-top"><span class="kb-icon">💰</span><span class="kb-chg <?= $revChg>=0?'up':'down' ?>"><?= ($revChg>=0?'+':'').$revChg ?>%</span></div>
        <div class="kb-val">KSh <?= number_format($rev) ?></div>
        <div class="kb-lbl">Revenue</div>
      </div>
      <div class="kpi-box">
        <div class="kb-top"><span class="kb-icon">📋</span><span class="kb-chg <?= $cntChg>=0?'up':'down' ?>"><?= ($cntChg>=0?'+':'').$cntChg ?>%</span></div>
        <div class="kb-val"><?= number_format($cnt) ?></div>
        <div class="kb-lbl">Orders</div>
      </div>
      <div class="kpi-box">
        <div class="kb-top"><span class="kb-icon">📊</span><span class="kb-chg neutral">avg</span></div>
        <div class="kb-val">KSh <?= number_format($avg) ?></div>
        <div class="kb-lbl">Avg Order Value</div>
      </div>
      <div class="kpi-box">
        <div class="kb-top"><span class="kb-icon">🗓</span><span class="kb-chg neutral"><?= $daysDiff ?> days</span></div>
        <div class="kb-val"><?= $cnt > 0 ? round($cnt/$daysDiff,1) : 0 ?></div>
        <div class="kb-lbl">Orders per Day</div>
      </div>
    </div>

    <?php if (!empty($byDay)): ?>
    <div class="grid-full">
      <div class="card">
        <div class="card-head"><span class="card-title">Revenue Over Time</span></div>
        <div class="card-body">
          <div class="day-chart">
            <?php foreach ($byDay as $d): ?>
            <div class="day-col">
              <div class="day-bar" style="height:<?= round(($d['rev']/$maxDay)*100) ?>px" title="<?= $d['day'] ?> — KSh <?= number_format($d['rev']) ?>"></div>
              <div class="day-lbl"><?= date($period==='today'?'H:i':'d/m', strtotime($d['day'])) ?></div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <div class="grid-2">
      <div class="card">
        <div class="card-head"><span class="card-title">Top Selling Items</span></div>
        <div class="card-body">
          <?php if (empty($topItems)): ?><p class="no-data">No data yet.</p>
          <?php else: ?>
          <div class="bar-chart">
            <?php foreach ($topItems as $i => $it): ?>
            <div class="bar-row">
              <span class="rank-num"><?= $i+1 ?></span>
              <span class="bar-label" title="<?= htmlspecialchars($it['name']) ?>"><?= htmlspecialchars($it['name']) ?></span>
              <div class="bar-track"><div class="bar-fill" style="width:<?= $it['pct'] ?>%"></div></div>
              <span class="bar-val">KSh <?= number_format($it['revenue']) ?></span>
            </div>
            <?php endforeach; endif; ?>
          </div>
        </div>
      </div>
      <div>
        <div class="card" style="margin-bottom:20px">
          <div class="card-head"><span class="card-title">By Category</span></div>
          <div class="card-body">
            <?php if (empty($byCat)): ?><p class="no-data">No data yet.</p>
            <?php else: ?>
            <div class="bar-chart">
              <?php foreach ($byCat as $b): ?>
              <div class="bar-row">
                <span class="bar-label"><?= htmlspecialchars($b['name']) ?></span>
                <div class="bar-track"><div class="bar-fill" style="width:<?= round(($b['revenue']/$maxCat)*100) ?>%;background:var(--gold)"></div></div>
                <span class="bar-val">KSh <?= number_format($b['revenue']) ?></span>
              </div>
              <?php endforeach; endif; ?>
            </div>
          </div>
        </div>
        <?php if (!empty($payMethods)): ?>
        <div class="card">
          <div class="card-head"><span class="card-title">Payment Methods</span></div>
          <div style="padding:0 20px">
            <?php foreach ($payMethods as $pm): ?>
            <div class="pay-row">
              <span class="pay-method"><?= htmlspecialchars($pm['method']) ?></span>
              <span style="font-size:12px;color:var(--text-muted)"><?= $pm['cnt'] ?> transactions</span>
              <span class="mono" style="font-weight:600;color:var(--deep)">KSh <?= number_format($pm['total']) ?></span>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>
      </div>
    </div>

  </div>
</div>
</body></html>
