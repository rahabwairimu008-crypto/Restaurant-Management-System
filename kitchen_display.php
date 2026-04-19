<?php
session_start();
require_once 'dbconfig.php';
if (empty($_SESSION['user_id']) || !in_array($_SESSION['role'],['admin','chef','sous_chef','waiter'])) {
    header('Location: login.php'); exit;
}
$pdo = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a  = $_POST['action'] ?? '';
    $id = (int)$_POST['order_id'];
    if ($a === 'start')  $pdo->prepare("UPDATE orders SET status='cooking',  updated_at=NOW() WHERE id=?")->execute([$id]);
    if ($a === 'ready')  $pdo->prepare("UPDATE orders SET status='ready',    updated_at=NOW() WHERE id=?")->execute([$id]);
    if ($a === 'served') $pdo->prepare("UPDATE orders SET status='served',   updated_at=NOW() WHERE id=?")->execute([$id]);
    header('Location: kitchen_display.php'); exit;
}

function getOrders($pdo, $status) {
    return $pdo->prepare(
        "SELECT o.*, tl.table_number,
         TIMESTAMPDIFF(MINUTE, o.created_at, NOW()) AS elapsed
         FROM orders o LEFT JOIN table_layout tl ON o.table_id=tl.id
         WHERE o.status=? ORDER BY o.created_at ASC"
    ) && ($s=$pdo->prepare("SELECT o.*, tl.table_number, TIMESTAMPDIFF(MINUTE,o.created_at,NOW()) AS elapsed FROM orders o LEFT JOIN table_layout tl ON o.table_id=tl.id WHERE o.status=? ORDER BY o.created_at ASC"))
    && $s->execute([$status]) ? $s->fetchAll() : [];
}
// simpler
$fetchOrders = function($status) use ($pdo) {
    $s = $pdo->prepare("SELECT o.*, tl.table_number, TIMESTAMPDIFF(MINUTE,o.created_at,NOW()) AS elapsed
        FROM orders o LEFT JOIN table_layout tl ON o.table_id=tl.id
        WHERE o.status=? ORDER BY o.created_at ASC");
    $s->execute([$status]); return $s->fetchAll();
};
$fetchItems = function($orderId) use ($pdo) {
    $s = $pdo->prepare("SELECT * FROM order_items WHERE order_id=? ORDER BY id");
    $s->execute([$orderId]); return $s->fetchAll();
};

$pending = $fetchOrders('pending');
$cooking = $fetchOrders('cooking');
$ready   = $fetchOrders('ready');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Jiko Kitchen Display</title>
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:wght@400;500;600&family=DM+Mono&display=swap" rel="stylesheet">
<style>
:root{--bg:#0D0D0D;--surface:#161616;--surface2:#1E1E1E;--border:rgba(255,255,255,.08);--pending:#4A90D9;--cooking:#E8A020;--ready:#3DAA5C;--urgent:#E84040;--text:#F0F0F0;--text-muted:#888;--text-dim:#555;--gold:#C49A3C;}
*{margin:0;padding:0;box-sizing:border-box;}
body{background:var(--bg);font-family:'DM Sans',sans-serif;color:var(--text);min-height:100vh;display:flex;flex-direction:column;}
header{background:var(--surface);border-bottom:1px solid var(--border);padding:0 24px;height:60px;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;}
.klogo{font-family:'Bebas Neue',sans-serif;font-size:26px;letter-spacing:.08em;}
.klogo span{color:var(--gold);}
.hstats{display:flex;gap:24px;}
.hs{text-align:center;}
.hs-num{font-family:'Bebas Neue',sans-serif;font-size:22px;line-height:1;}
.hs-num.p{color:var(--pending);} .hs-num.c{color:var(--cooking);} .hs-num.r{color:var(--ready);}
.hs-lbl{font-size:9px;text-transform:uppercase;letter-spacing:.1em;color:var(--text-muted);}
.hright{display:flex;align-items:center;gap:16px;}
#clock{font-family:'DM Mono',monospace;font-size:20px;color:var(--text-muted);}
.board{flex:1;display:grid;grid-template-columns:repeat(3,1fr);overflow:hidden;}
.col{border-right:1px solid var(--border);display:flex;flex-direction:column;overflow:hidden;}
.col:last-child{border-right:none;}
.col-head{padding:12px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;flex-shrink:0;}
.col-title{font-family:'Bebas Neue',sans-serif;font-size:18px;letter-spacing:.1em;}
.ct-p{color:var(--pending);} .ct-c{color:var(--cooking);} .ct-r{color:var(--ready);}
.col-count{font-size:11px;color:var(--text-muted);background:var(--surface2);padding:2px 9px;border-radius:10px;font-weight:600;}
.col-body{flex:1;overflow-y:auto;padding:12px;display:flex;flex-direction:column;gap:10px;}
.ocard{background:var(--surface);border:1px solid var(--border);border-radius:12px;overflow:hidden;}
.ocard.urgent{border-top:3px solid var(--urgent);}
.ocard.warn{border-top:3px solid var(--cooking);}
.ocard.new{border-top:3px solid var(--pending);}
.ocard.done{border-top:3px solid var(--ready);opacity:.85;}
.oc-top{padding:11px 13px 8px;display:flex;align-items:flex-start;justify-content:space-between;border-bottom:1px solid var(--border);}
.oc-id{font-family:'DM Mono',monospace;font-size:10px;color:var(--text-muted);margin-bottom:2px;}
.oc-table{font-family:'Bebas Neue',sans-serif;font-size:22px;letter-spacing:.05em;line-height:1;}
.oc-waiter{font-size:10px;color:var(--text-muted);margin-top:2px;}
.oc-timer-num{font-family:'Bebas Neue',sans-serif;font-size:28px;line-height:1;text-align:right;}
.tc-ok{color:var(--ready);} .tc-warn{color:var(--cooking);} .tc-urg{color:var(--urgent);animation:blink 1s infinite;}
@keyframes blink{0%,100%{opacity:1}50%{opacity:.35}}
.oc-timer-lbl{font-size:9px;color:var(--text-dim);text-transform:uppercase;text-align:right;}
.oc-items{padding:9px 13px;}
.oc-item{display:flex;align-items:baseline;gap:7px;padding:4px 0;border-bottom:1px solid rgba(255,255,255,.03);font-size:13px;}
.oc-item:last-child{border-bottom:none;}
.oc-qty{font-family:'Bebas Neue',sans-serif;font-size:18px;color:var(--gold);min-width:22px;line-height:1;}
.oc-name{font-weight:500;flex:1;}
.oc-note{font-size:11px;color:var(--urgent);font-style:italic;}
.oc-acts{padding:9px 13px;display:flex;gap:7px;border-top:1px solid var(--border);}
.ka{flex:1;padding:7px;border:none;border-radius:6px;font-family:'DM Sans',sans-serif;font-size:11px;font-weight:600;letter-spacing:.04em;cursor:pointer;transition:all .15s;}
.ka-start{background:var(--cooking);color:#000;}
.ka-start:hover{filter:brightness(1.1);}
.ka-ready{background:var(--ready);color:#fff;}
.ka-ready:hover{filter:brightness(1.1);}
.ka-served{background:transparent;border:1px solid var(--border);color:var(--text-muted);}
.ka-served:hover{border-color:var(--text-muted);color:var(--text);}
.empty-col{text-align:center;padding:40px 20px;color:var(--text-dim);font-size:13px;font-style:italic;}
a.logout{color:var(--text-muted);font-size:12px;text-decoration:none;}
a.logout:hover{color:var(--gold);}
</style>
</head>
<body>
<header>
  <div class="klogo">JIKO <span>KITCHEN</span></div>
  <div class="hstats">
    <div class="hs"><div class="hs-num p"><?= count($pending) ?></div><div class="hs-lbl">Pending</div></div>
    <div class="hs"><div class="hs-num c"><?= count($cooking) ?></div><div class="hs-lbl">Cooking</div></div>
    <div class="hs"><div class="hs-num r"><?= count($ready) ?></div><div class="hs-lbl">Ready</div></div>
  </div>
  <div class="hright">
    <span id="clock"><?= date('H:i:s') ?></span>
    <a href="logout.php" class="logout">Sign out</a>
  </div>
</header>

<div class="board">

  <!-- PENDING -->
  <div class="col">
    <div class="col-head"><span class="col-title ct-p">Pending</span><span class="col-count"><?= count($pending) ?></span></div>
    <div class="col-body">
      <?php if (empty($pending)): ?><div class="empty-col">No pending orders</div>
      <?php else: foreach ($pending as $o):
        $tc = $o['elapsed'] > 20 ? 'tc-urg' : ($o['elapsed'] > 10 ? 'tc-warn' : 'tc-ok');
        $cc = $o['elapsed'] > 20 ? 'urgent' : 'new';
        $items = $fetchItems($o['id']); ?>
      <div class="ocard <?= $cc ?>">
        <div class="oc-top">
          <div>
            <div class="oc-id">#<?= $o['id'] ?></div>
            <div class="oc-table"><?= $o['table_number'] ? 'Table '.$o['table_number'] : 'Takeaway' ?></div>
            <div class="oc-waiter"><?= htmlspecialchars($o['waiter_name'] ?? 'Customer') ?></div>
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
            <?php if ($it['note']): ?><span class="oc-note">! <?= htmlspecialchars($it['note']) ?></span><?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
        <div class="oc-acts">
          <form method="POST" style="flex:1">
            <input type="hidden" name="action" value="start">
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
    <div class="col-head"><span class="col-title ct-c">Cooking</span><span class="col-count"><?= count($cooking) ?></span></div>
    <div class="col-body">
      <?php if (empty($cooking)): ?><div class="empty-col">Nothing cooking</div>
      <?php else: foreach ($cooking as $o):
        $tc = $o['elapsed'] > 30 ? 'tc-urg' : ($o['elapsed'] > 20 ? 'tc-warn' : 'tc-ok');
        $cc = $o['elapsed'] > 30 ? 'urgent' : 'warn';
        $items = $fetchItems($o['id']); ?>
      <div class="ocard <?= $cc ?>">
        <div class="oc-top">
          <div>
            <div class="oc-id">#<?= $o['id'] ?></div>
            <div class="oc-table"><?= $o['table_number'] ? 'Table '.$o['table_number'] : 'Takeaway' ?></div>
            <div class="oc-waiter"><?= htmlspecialchars($o['waiter_name'] ?? 'Customer') ?></div>
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
            <?php if ($it['note']): ?><span class="oc-note">! <?= htmlspecialchars($it['note']) ?></span><?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
        <div class="oc-acts">
          <form method="POST" style="flex:1">
            <input type="hidden" name="action" value="ready">
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
    <div class="col-head"><span class="col-title ct-r">Ready to Serve</span><span class="col-count"><?= count($ready) ?></span></div>
    <div class="col-body">
      <?php if (empty($ready)): ?><div class="empty-col">No orders ready</div>
      <?php else: foreach ($ready as $o):
        $items = $fetchItems($o['id']); ?>
      <div class="ocard done">
        <div class="oc-top">
          <div>
            <div class="oc-id">#<?= $o['id'] ?></div>
            <div class="oc-table"><?= $o['table_number'] ? 'Table '.$o['table_number'] : 'Takeaway' ?></div>
            <div class="oc-waiter"><?= htmlspecialchars($o['waiter_name'] ?? 'Customer') ?></div>
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
            <input type="hidden" name="action" value="served">
            <input type="hidden" name="order_id" value="<?= $o['id'] ?>">
            <button type="submit" class="ka ka-served" style="width:100%">Served ✓</button>
          </form>
        </div>
      </div>
      <?php endforeach; endif; ?>
    </div>
  </div>

</div>

<script>
// Live clock
setInterval(() => {
  const n = new Date();
  document.getElementById('clock').textContent =
    String(n.getHours()).padStart(2,'0')+':'+
    String(n.getMinutes()).padStart(2,'0')+':'+
    String(n.getSeconds()).padStart(2,'0');
}, 1000);
// Auto-refresh every 30 seconds to pick up new orders
setTimeout(() => location.reload(), 30000);
</script>
</body>
</html>
