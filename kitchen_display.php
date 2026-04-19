<?php
// kitchen_display.php — Kitchen Display System
session_start();
require_once 'dbconfig.php';

if (empty($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin','chef','sous_chef','waiter'])) {
    header('Location: login.php'); exit;
}

$pdo = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a  = $_POST['action']   ?? '';
    $id = (int)$_POST['order_id'];
    if ($a === 'start')  $pdo->prepare("UPDATE orders SET status='cooking', updated_at=NOW() WHERE id=?")->execute([$id]);
    if ($a === 'ready')  $pdo->prepare("UPDATE orders SET status='ready',   updated_at=NOW() WHERE id=?")->execute([$id]);
    if ($a === 'served') $pdo->prepare("UPDATE orders SET status='served',  updated_at=NOW() WHERE id=?")->execute([$id]);
    header('Location: kitchen_display.php'); exit;
}

$fetchOrders = function($status) use ($pdo) {
    $s = $pdo->prepare(
        "SELECT o.*, tl.table_number,
         TIMESTAMPDIFF(MINUTE, o.created_at, NOW()) AS elapsed
         FROM orders o
         LEFT JOIN table_layout tl ON o.table_id = tl.id
         WHERE o.status = ? ORDER BY o.created_at ASC"
    );
    $s->execute([$status]);
    return $s->fetchAll(PDO::FETCH_ASSOC);
};

$fetchItems = function($orderId) use ($pdo) {
    $s = $pdo->prepare("SELECT * FROM order_items WHERE order_id = ? ORDER BY id");
    $s->execute([$orderId]);
    return $s->fetchAll(PDO::FETCH_ASSOC);
};

$pending = $fetchOrders('pending');
$cooking = $fetchOrders('cooking');
$ready   = $fetchOrders('ready');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Jiko House — Kitchen Display</title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600&family=DM+Sans:wght@400;500;600&family=DM+Mono&display=swap" rel="stylesheet">
<style>
  /* ── Shared palette (matches _styles.css) ── */
  :root {
    --bg:        #F4EFE6;
    --bg2:       #EDE5D8;
    --surface:   #FFFDF8;
    --border:    rgba(92,61,46,.12);
    --brown:     #5C3D2E;
    --deep:      #3A2218;
    --rust:      #C0622B;
    --gold:      #C49A3C;
    --goldlt:    #E8C46A;
    --sage:      #5A8A5E;
    --red:       #A83232;
    --amber:     #C07A20;
    --text:      #2C1A0E;
    --text-muted:#7A5C45;
    --text-dim:  #B09A85;
    --shadow:    0 2px 16px rgba(60,34,24,.08);
    --shadow-md: 0 4px 28px rgba(60,34,24,.12);
    /* Kitchen status colors */
    --pending-bg:  rgba(74,144,217,.10);
    --pending-col: #2A5FA8;
    --cooking-bg:  rgba(192,122,32,.12);
    --cooking-col: #A06010;
    --ready-bg:    rgba(90,138,94,.12);
    --ready-col:   #2A6B30;
    --urgent-col:  var(--red);
  }
  *, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }
  body { background:#0D1117; font-family:'DM Sans',sans-serif; color:#fff; min-height:100vh; display:flex; flex-direction:column; }

  /* HEADER */
  header {
    background:#161B22; border-bottom:1px solid rgba(255,255,255,.08);
    padding:0 24px; height:60px; display:flex; align-items:center;
    justify-content:space-between; flex-shrink:0;
  }
  .klogo { font-family:'Playfair Display',serif; font-size:20px; color:var(--goldlt); letter-spacing:.04em; }
  .klogo span { color:rgba(255,255,255,.35); font-size:14px; font-family:'DM Sans',sans-serif; font-weight:400; margin-left:8px; }

  .hstats { display:flex; gap:28px; }
  .hs { text-align:center; }
  .hs-num { font-family:'Playfair Display',serif; font-size:22px; line-height:1; }
  .hs-num.p { color:#6AABF0; }
  .hs-num.c { color:var(--goldlt); }
  .hs-num.r { color:#7ACC88; }
  .hs-lbl { font-size:9px; text-transform:uppercase; letter-spacing:.12em; color:rgba(255,255,255,.25); margin-top:2px; }

  .hright { display:flex; align-items:center; gap:16px; }
  #clock { font-family:'DM Mono',monospace; font-size:18px; color:rgba(255,255,255,.4); }
  .logout-link { color:rgba(255,255,255,.3); font-size:12px; text-decoration:none; transition:color .15s; }
  .logout-link:hover { color:var(--goldlt); }

  /* BOARD */
  .board { flex:1; display:grid; grid-template-columns:repeat(3,1fr); gap:0; overflow:hidden; height:calc(100vh - 60px); }

  .col { border-right:1px solid rgba(255,255,255,.08); display:flex; flex-direction:column; overflow:hidden; }
  .col:last-child { border-right:none; }

  .col-head {
    padding:14px 18px; border-bottom:1px solid rgba(255,255,255,.08);
    display:flex; align-items:center; justify-content:space-between;
    flex-shrink:0;
  }
  .col-head.ch-p { background:#1A2A3A; }
  .col-head.ch-c { background:#2A1A08; }
  .col-head.ch-r { background:#0E2214; }

  .col-title { font-family:'Playfair Display',serif; font-size:17px; font-weight:600; }
  .ct-p { color:#6AABF0; }
  .ct-c { color:var(--goldlt); }
  .ct-r { color:#7ACC88; }

  .col-count {
    font-size:12px; font-weight:700; padding:3px 11px; border-radius:10px;
  }
  .cc-p { background:rgba(106,171,240,.15); color:#6AABF0; }
  .cc-c { background:rgba(232,196,106,.15); color:var(--goldlt); }
  .cc-r { background:rgba(122,204,136,.15); color:#7ACC88; }

  .col-body { flex:1; overflow-y:auto; padding:12px; display:flex; flex-direction:column; gap:10px; }
  .col-body.cb-p { background:#111C26; }
  .col-body.cb-c { background:#1C1208; }
  .col-body.cb-r { background:#0A1A10; }

  /* ORDER CARDS */
  .ocard {
    background:rgba(255,255,255,.05); border:1px solid rgba(255,255,255,.10);
    border-radius:13px; overflow:hidden; box-shadow:0 2px 12px rgba(0,0,0,.3);
    animation:fadeUp .3s ease both;
  }
  @keyframes fadeUp { from{opacity:0;transform:translateY(8px)} to{opacity:1;transform:none} }

  .ocard.status-pending { border-top:3px solid #4A90D9; }
  .ocard.status-cooking { border-top:3px solid var(--amber); }
  .ocard.status-ready   { border-top:3px solid #5ABB6A; }
  .ocard.urgent         { border-top:3px solid var(--red); box-shadow:0 0 0 1px rgba(168,50,50,.3); }

  .oc-top {
    padding:12px 14px 10px; display:flex; align-items:flex-start;
    justify-content:space-between; border-bottom:1px solid rgba(255,255,255,.08);
    background:rgba(255,255,255,.04);
  }
  .oc-id     { font-size:10px; color:rgba(255,255,255,.3); font-family:'DM Mono',monospace; margin-bottom:2px; }
  .oc-table  { font-family:'Playfair Display',serif; font-size:20px; color:#fff; line-height:1; }
  .oc-waiter { font-size:11px; color:rgba(255,255,255,.4); margin-top:3px; }

  .oc-timer-num { font-family:'Playfair Display',serif; font-size:26px; line-height:1; text-align:right; }
  .tc-ok   { color:#7ACC88; }
  .tc-warn { color:var(--goldlt); }
  .tc-urg  { color:#FF6B6B; animation:blink 1s infinite; }
  @keyframes blink { 0%,100%{opacity:1} 50%{opacity:.3} }
  .oc-timer-lbl { font-size:9px; color:rgba(255,255,255,.3); text-transform:uppercase; text-align:right; letter-spacing:.08em; }

  .oc-items { padding:10px 14px; }
  .oc-item {
    display:flex; align-items:baseline; gap:8px; padding:6px 0;
    border-bottom:1px solid rgba(255,255,255,.07); font-size:13px;
  }
  .oc-item:last-child { border-bottom:none; }
  .oc-qty  { font-family:'Playfair Display',serif; font-size:17px; color:var(--goldlt); min-width:22px; line-height:1; font-weight:600; }
  .oc-name { font-weight:500; flex:1; color:rgba(255,255,255,.88); }
  .oc-note { font-size:11px; color:#FF9B9B; font-style:italic; }

  .oc-acts { padding:10px 14px; display:flex; gap:8px; border-top:1px solid rgba(255,255,255,.08); background:rgba(0,0,0,.2); }
  .ka {
    flex:1; padding:9px; border:none; border-radius:8px;
    font-family:'DM Sans',sans-serif; font-size:12px; font-weight:600;
    letter-spacing:.04em; cursor:pointer; transition:all .15s;
  }
  .ka-start  { background:rgba(74,144,217,.25); color:#6AABF0; border:1px solid rgba(74,144,217,.3); }
  .ka-start:hover { background:rgba(74,144,217,.4); }
  .ka-ready  { background:rgba(192,122,32,.25); color:var(--goldlt); border:1px solid rgba(192,122,32,.3); }
  .ka-ready:hover { background:rgba(192,122,32,.4); }
  .ka-served { background:rgba(90,187,106,.2); color:#7ACC88; border:1px solid rgba(90,187,106,.3); }
  .ka-served:hover { background:rgba(90,187,106,.35); }

  .empty-col { text-align:center; padding:50px 20px; color:rgba(255,255,255,.2); font-size:13px; font-style:italic; }
  .empty-col .ec-icon { font-size:36px; margin-bottom:10px; opacity:.5; }
</style>
</head>
<body>

<header>
  <div class="klogo">Jiko House <span>Kitchen Display</span></div>
  <div class="hstats">
    <div class="hs"><div class="hs-num p"><?= count($pending) ?></div><div class="hs-lbl">Pending</div></div>
    <div class="hs"><div class="hs-num c"><?= count($cooking) ?></div><div class="hs-lbl">Cooking</div></div>
    <div class="hs"><div class="hs-num r"><?= count($ready) ?></div><div class="hs-lbl">Ready</div></div>
  </div>
  <div class="hright">
    <span id="clock"><?= date('H:i:s') ?></span>
    <a href="logout.php" class="logout-link">Sign out</a>
  </div>
</header>

<div class="board">

  <!-- PENDING -->
  <div class="col">
    <div class="col-head ch-p">
      <span class="col-title ct-p">🕐 Pending</span>
      <span class="col-count cc-p"><?= count($pending) ?></span>
    </div>
    <div class="col-body cb-p">
      <?php if (empty($pending)): ?>
      <div class="empty-col"><div class="ec-icon">✅</div>No pending orders</div>
      <?php else: foreach ($pending as $o):
        $urgent = $o['elapsed'] > 15;
        $tc     = $o['elapsed'] > 15 ? 'tc-urg' : ($o['elapsed'] > 8 ? 'tc-warn' : 'tc-ok');
        $items  = $fetchItems($o['id']);
      ?>
      <div class="ocard <?= $urgent ? 'urgent' : 'status-pending' ?>">
        <div class="oc-top">
          <div>
            <div class="oc-id">#<?= $o['id'] ?></div>
            <div class="oc-table"><?= $o['table_number'] ? 'Table ' . $o['table_number'] : 'Takeaway' ?></div>
            <div class="oc-waiter">👤 <?= htmlspecialchars($o['waiter_name'] ?? 'Customer') ?></div>
          </div>
          <div>
            <div class="oc-timer-num <?= $tc ?>"><?= $o['elapsed'] ?>m</div>
            <div class="oc-timer-lbl">waiting</div>
          </div>
        </div>
        <div class="oc-items">
          <?php foreach ($items as $it): ?>
          <div class="oc-item">
            <span class="oc-qty"><?= $it['quantity'] ?>×</span>
            <span class="oc-name"><?= htmlspecialchars($it['name']) ?></span>
            <?php if ($it['note']): ?><span class="oc-note">⚠ <?= htmlspecialchars($it['note']) ?></span><?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
        <div class="oc-acts">
          <form method="POST" style="flex:1">
            <input type="hidden" name="action"   value="start">
            <input type="hidden" name="order_id" value="<?= $o['id'] ?>">
            <button type="submit" class="ka ka-start" style="width:100%">▶ Start Cooking</button>
          </form>
        </div>
      </div>
      <?php endforeach; endif; ?>
    </div>
  </div>

  <!-- COOKING -->
  <div class="col">
    <div class="col-head ch-c">
      <span class="col-title ct-c">🔥 Cooking</span>
      <span class="col-count cc-c"><?= count($cooking) ?></span>
    </div>
    <div class="col-body cb-c">
      <?php if (empty($cooking)): ?>
      <div class="empty-col"><div class="ec-icon">🍳</div>Nothing cooking right now</div>
      <?php else: foreach ($cooking as $o):
        $urgent = $o['elapsed'] > 25;
        $tc     = $o['elapsed'] > 25 ? 'tc-urg' : ($o['elapsed'] > 15 ? 'tc-warn' : 'tc-ok');
        $items  = $fetchItems($o['id']);
      ?>
      <div class="ocard <?= $urgent ? 'urgent' : 'status-cooking' ?>">
        <div class="oc-top">
          <div>
            <div class="oc-id">#<?= $o['id'] ?></div>
            <div class="oc-table"><?= $o['table_number'] ? 'Table ' . $o['table_number'] : 'Takeaway' ?></div>
            <div class="oc-waiter">👤 <?= htmlspecialchars($o['waiter_name'] ?? 'Customer') ?></div>
          </div>
          <div>
            <div class="oc-timer-num <?= $tc ?>"><?= $o['elapsed'] ?>m</div>
            <div class="oc-timer-lbl">cooking</div>
          </div>
        </div>
        <div class="oc-items">
          <?php foreach ($items as $it): ?>
          <div class="oc-item">
            <span class="oc-qty"><?= $it['quantity'] ?>×</span>
            <span class="oc-name"><?= htmlspecialchars($it['name']) ?></span>
            <?php if ($it['note']): ?><span class="oc-note">⚠ <?= htmlspecialchars($it['note']) ?></span><?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
        <div class="oc-acts">
          <form method="POST" style="flex:1">
            <input type="hidden" name="action"   value="ready">
            <input type="hidden" name="order_id" value="<?= $o['id'] ?>">
            <button type="submit" class="ka ka-ready" style="width:100%">✓ Mark Ready</button>
          </form>
        </div>
      </div>
      <?php endforeach; endif; ?>
    </div>
  </div>

  <!-- READY -->
  <div class="col">
    <div class="col-head ch-r">
      <span class="col-title ct-r">✅ Ready to Serve</span>
      <span class="col-count cc-r"><?= count($ready) ?></span>
    </div>
    <div class="col-body cb-r">
      <?php if (empty($ready)): ?>
      <div class="empty-col"><div class="ec-icon">🛎</div>No orders ready yet</div>
      <?php else: foreach ($ready as $o):
        $items = $fetchItems($o['id']);
      ?>
      <div class="ocard status-ready">
        <div class="oc-top">
          <div>
            <div class="oc-id">#<?= $o['id'] ?></div>
            <div class="oc-table"><?= $o['table_number'] ? 'Table ' . $o['table_number'] : 'Takeaway' ?></div>
            <div class="oc-waiter">👤 <?= htmlspecialchars($o['waiter_name'] ?? 'Customer') ?></div>
          </div>
          <div>
            <div class="oc-timer-num tc-ok">✓</div>
            <div class="oc-timer-lbl">ready</div>
          </div>
        </div>
        <div class="oc-items">
          <?php foreach ($items as $it): ?>
          <div class="oc-item">
            <span class="oc-qty"><?= $it['quantity'] ?>×</span>
            <span class="oc-name"><?= htmlspecialchars($it['name']) ?></span>
          </div>
          <?php endforeach; ?>
        </div>
        <div class="oc-acts">
          <form method="POST" style="flex:1">
            <input type="hidden" name="action"   value="served">
            <input type="hidden" name="order_id" value="<?= $o['id'] ?>">
            <button type="submit" class="ka ka-served" style="width:100%">Served ✓</button>
          </form>
        </div>
      </div>
      <?php endforeach; endif; ?>
    </div>
  </div>

</div><!-- /.board -->

<script>
// Live clock
setInterval(() => {
  const n = new Date();
  const pad = v => String(v).padStart(2,'0');
  document.getElementById('clock').textContent =
    pad(n.getHours()) + ':' + pad(n.getMinutes()) + ':' + pad(n.getSeconds());
}, 1000);

// Auto-refresh every 30s to pick up new orders
setTimeout(() => location.reload(), 30000);
</script>
</body>
</html>
