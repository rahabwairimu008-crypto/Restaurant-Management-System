<?php
// waiter_pos.php — Waiter POS Tablet
session_start();
require_once 'dbconfig.php';

if (empty($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin','waiter'])) {
    header('Location: login.php'); exit;
}

$pdo = getDB();
$pdo->exec("SET NAMES 'utf8mb4' COLLATE 'utf8mb4_unicode_ci'");
$msg = ''; $msgType = '';

// ── ACTIONS ───────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'send_order') {
        $tableId   = (int)$_POST['table_id'];
        $guests    = max(1, (int)($_POST['guests'] ?? 1));
        $notes     = trim($_POST['notes'] ?? '');
        $items     = json_decode($_POST['items_json'] ?? '[]', true);

        if (!empty($items)) {
            $total = array_sum(array_map(fn($i) => $i['price'] * $i['qty'], $items));
            $pdo->prepare(
                "INSERT INTO orders (table_id, waiter_name, guests, status, total_price, notes)
                 VALUES (?, ?, ?, 'pending', ?, ?)"
            )->execute([$tableId, $_SESSION['user_name'], $guests, $total, $notes]);
            $orderId = $pdo->lastInsertId();
            foreach ($items as $it) {
                $pdo->prepare(
                    "INSERT INTO order_items (order_id, menu_item_id, name, price, quantity, note)
                     VALUES (?, ?, ?, ?, ?, ?)"
                )->execute([$orderId, $it['id'], $it['name'], $it['price'], $it['qty'], $it['note'] ?? '']);
            }
            $pdo->prepare("UPDATE table_layout SET status='occupied' WHERE id=?")->execute([$tableId]);
            $msg = "✅ Order #{$orderId} sent to kitchen!"; $msgType = 'success';
        } else {
            $msg = 'Add items to the order first.'; $msgType = 'error';
        }
    }

    if ($action === 'bill') {
        $pdo->prepare("UPDATE orders SET status='paid', updated_at=NOW() WHERE id=?")->execute([(int)$_POST['order_id']]);
        $pdo->prepare("UPDATE table_layout SET status='available' WHERE id=?")->execute([(int)$_POST['table_id']]);
        $msg = '✅ Bill processed. Table cleared.'; $msgType = 'success';
    }
}

// ── DATA ──────────────────────────────────────────────────────────────────────
$tables = $pdo->query(
    "SELECT tl.*,
     (SELECT o.id FROM orders o WHERE o.table_id=tl.id AND o.status NOT IN ('paid','cancelled') ORDER BY o.created_at DESC LIMIT 1) AS active_order_id,
     (SELECT o.total_price FROM orders o WHERE o.table_id=tl.id AND o.status NOT IN ('paid','cancelled') ORDER BY o.created_at DESC LIMIT 1) AS order_total
     FROM table_layout tl ORDER BY tl.table_number"
)->fetchAll();

$menuRows = $pdo->query(
    "SELECT m.*, c.name AS cat_name, c.sort_order AS cat_order
     FROM menu m LEFT JOIN categories c ON m.category_id=c.id
     WHERE m.available=1 ORDER BY c.sort_order, m.name"
)->fetchAll();

$menuByCat = [];
foreach ($menuRows as $row) { $menuByCat[$row['cat_name'] ?? 'Other'][] = $row; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Jiko House — Waiter POS</title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600&family=DM+Sans:wght@400;500;600&family=DM+Mono&display=swap" rel="stylesheet">
<style>
  :root{--bg:#1A1208;--surface:#241A0D;--surface2:#2E2210;--border:rgba(196,154,60,.15);--gold:#C49A3C;--goldlt:#E8C46A;--rust:#C0622B;--green:#5A8A5E;--red:#A83232;--amber:#C07A20;--text:#F2E8D5;--text-muted:#8C7A60;--text-dim:#5C4E38;--white:#FDF6E8;}
  *,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}
  body{background:var(--bg);font-family:'DM Sans',sans-serif;color:var(--text);min-height:100vh;display:flex;flex-direction:column;}
  .topnav{background:var(--surface);border-bottom:1px solid var(--border);padding:0 22px;height:56px;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;}
  .nav-logo{font-family:'Playfair Display',serif;font-size:18px;color:var(--gold);}
  .nav-right{display:flex;align-items:center;gap:16px;font-size:13px;color:var(--text-muted);}
  .nav-user{color:var(--text);font-weight:500;}
  .nav-link{color:var(--text-muted);text-decoration:none;font-size:12px;}
  .nav-link:hover{color:var(--gold);}
  .flash{padding:10px 22px;font-size:13px;font-weight:600;text-align:center;flex-shrink:0;}
  .flash.success{background:var(--green);color:#fff;}
  .flash.error{background:var(--red);color:#fff;}
  .main{display:grid;grid-template-columns:1fr 380px;flex:1;overflow:hidden;min-height:0;}
  /* TABLES */
  .tables-panel{padding:20px;overflow-y:auto;}
  .panel-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;}
  .panel-title{font-family:'Playfair Display',serif;font-size:20px;color:var(--white);}
  .legend{display:flex;gap:14px;font-size:11px;color:var(--text-muted);}
  .leg-item{display:flex;align-items:center;gap:5px;}
  .leg-dot{width:8px;height:8px;border-radius:50%;}
  .table-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;}
  .table-card{background:var(--surface);border:1.5px solid var(--border);border-radius:12px;padding:16px 13px;cursor:pointer;transition:all .2s;user-select:none;}
  .table-card:hover,.table-card.active{border-color:var(--gold);transform:translateY(-2px);box-shadow:0 4px 20px rgba(0,0,0,.3);}
  .table-card.occupied{border-left:3px solid var(--rust);}
  .table-card.available{border-left:3px solid var(--green);}
  .table-card.reserved{border-left:3px solid var(--amber);}
  .tc-num{font-family:'Playfair Display',serif;font-size:26px;color:var(--goldlt);line-height:1;margin-bottom:5px;}
  .tc-status{font-size:10px;letter-spacing:.12em;text-transform:uppercase;font-weight:600;margin-bottom:8px;}
  .ts-occupied{color:var(--rust);}.ts-available{color:var(--green);}.ts-reserved{color:var(--amber);}
  .tc-info{font-size:11px;color:var(--text-muted);line-height:1.7;}
  .tc-total{font-size:14px;font-weight:600;color:var(--gold);margin-top:8px;}
  /* ORDER PANEL */
  .order-panel{background:var(--surface);border-left:1px solid var(--border);display:flex;flex-direction:column;overflow:hidden;}
  .op-head{padding:16px 18px 12px;border-bottom:1px solid var(--border);flex-shrink:0;}
  .op-table{font-family:'Playfair Display',serif;font-size:17px;color:var(--white);margin-bottom:3px;}
  .op-sub{font-size:11px;color:var(--text-muted);}
  .menu-section{flex:1;overflow-y:auto;}
  .cat-header{font-size:10px;letter-spacing:.18em;text-transform:uppercase;color:var(--text-dim);padding:12px 16px 6px;font-weight:600;background:var(--surface);position:sticky;top:0;z-index:1;border-bottom:1px solid rgba(196,154,60,.06);}
  .menu-item{display:flex;align-items:center;justify-content:space-between;padding:9px 16px;border-bottom:1px solid rgba(196,154,60,.05);cursor:pointer;transition:background .15s;}
  .menu-item:hover{background:var(--surface2);}
  .mi-left{display:flex;align-items:center;gap:10px;}
  .mi-emoji{font-size:20px;}
  .mi-name{font-size:13px;font-weight:500;color:var(--text);}
  .mi-spicy{font-size:11px;color:var(--rust);}
  .mi-price{font-size:13px;font-weight:600;color:var(--gold);white-space:nowrap;}
  /* SUMMARY */
  .order-summary{border-top:1px solid var(--border);padding:14px 16px;flex-shrink:0;}
  .guests-row{display:flex;align-items:center;gap:8px;margin-bottom:12px;font-size:12px;color:var(--text-muted);}
  .guests-input{width:60px;background:var(--surface2);border:1px solid var(--border);border-radius:6px;padding:5px 8px;color:var(--text);font-family:'DM Mono',monospace;font-size:13px;outline:none;text-align:center;}
  .order-lines{max-height:200px;overflow-y:auto;margin-bottom:12px;}
  .ol-row{display:flex;align-items:center;font-size:13px;padding:5px 0;border-bottom:1px solid rgba(196,154,60,.06);gap:6px;}
  .ol-row:last-child{border-bottom:none;}
  .ol-name{flex:1;color:var(--text);min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
  .ol-qty{background:var(--surface2);border:1px solid var(--border);border-radius:5px;padding:2px 7px;font-size:12px;font-weight:600;color:var(--gold);flex-shrink:0;}
  .ol-price{color:var(--text-muted);font-size:12px;min-width:62px;text-align:right;flex-shrink:0;font-family:'DM Mono',monospace;}
  .ol-del{background:none;border:none;color:var(--text-dim);cursor:pointer;font-size:16px;padding:0 3px;flex-shrink:0;line-height:1;}
  .ol-del:hover{color:var(--rust);}
  .empty-order{text-align:center;padding:18px;color:var(--text-dim);font-size:13px;font-style:italic;}
  .notes-input{width:100%;background:var(--surface2);border:1px solid var(--border);border-radius:8px;padding:8px 10px;font-family:'DM Sans',sans-serif;font-size:13px;color:var(--text);resize:none;outline:none;margin-bottom:12px;}
  .notes-input::placeholder{color:var(--text-dim);}
  .total-row{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;padding-top:6px;}
  .total-label{font-size:12px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.08em;}
  .total-amt{font-family:'Playfair Display',serif;font-size:22px;color:var(--goldlt);}
  .action-btns{display:flex;gap:8px;}
  .act-btn{flex:1;padding:11px;border:none;border-radius:9px;font-family:'DM Sans',sans-serif;font-size:12px;font-weight:600;cursor:pointer;transition:all .18s;letter-spacing:.04em;}
  .act-clear{background:transparent;color:var(--text-muted);border:1px solid var(--border);flex:none;padding:11px 13px;}
  .act-clear:hover{border-color:var(--rust);color:var(--rust);}
  .act-send{background:var(--rust);color:#fff;} .act-send:hover{background:#A8501E;}
  .act-bill{background:var(--gold);color:var(--bg);} .act-bill:hover{background:var(--goldlt);}
</style>
</head>
<body>

<div class="topnav">
  <div class="nav-logo">Jiko House · POS</div>
  <div class="nav-right">
    <span><?= date('D, d M · H:i') ?></span>
    <span class="nav-user">👤 <?= htmlspecialchars($_SESSION['user_name'] ?? '') ?></span>
    <a href="logout.php" class="nav-link">Sign out</a>
  </div>
</div>

<?php if ($msg): ?><div class="flash <?= $msgType ?>"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

<div class="main">

  <!-- TABLES -->
  <div class="tables-panel">
    <div class="panel-header">
      <h2 class="panel-title">Floor Plan</h2>
      <div class="legend">
        <div class="leg-item"><div class="leg-dot" style="background:var(--green)"></div>Available</div>
        <div class="leg-item"><div class="leg-dot" style="background:var(--rust)"></div>Occupied</div>
        <div class="leg-item"><div class="leg-dot" style="background:var(--amber)"></div>Reserved</div>
      </div>
    </div>
    <div class="table-grid">
      <?php foreach ($tables as $t): ?>
      <div class="table-card <?= $t['status'] ?>"
           id="tc-<?= $t['id'] ?>"
           onclick="selectTable(<?= $t['id'] ?>,<?= $t['table_number'] ?>,'<?= $t['status'] ?>',<?= $t['active_order_id'] ?? 0 ?>,<?= $t['order_total'] ?? 0 ?>)">
        <div class="tc-num"><?= $t['table_number'] ?></div>
        <div class="tc-status ts-<?= $t['status'] ?>"><?= ucfirst($t['status']) ?></div>
        <div class="tc-info">Capacity: <?= $t['capacity'] ?> guests</div>
        <?php if ($t['order_total']): ?>
        <div class="tc-total">KSh <?= number_format($t['order_total']) ?></div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- ORDER PANEL -->
  <div class="order-panel">
    <div class="op-head">
      <div class="op-table" id="op-label">Select a table</div>
      <div class="op-sub"   id="op-sub">Tap a table to begin</div>
    </div>
    <div class="menu-section">
      <?php foreach ($menuByCat as $cat => $items): ?>
      <div class="cat-header"><?= htmlspecialchars($cat) ?></div>
      <?php foreach ($items as $item): ?>
      <div class="menu-item" onclick="addItem(<?= $item['id'] ?>,'<?= addslashes($item['name']) ?>',<?= $item['price'] ?>,'<?= $item['emoji'] ?>')">
        <div class="mi-left">
          <span class="mi-emoji"><?= $item['emoji'] ?></span>
          <div>
            <span class="mi-name"><?= htmlspecialchars($item['name']) ?></span>
            <?php if ($item['spicy']): ?><span class="mi-spicy"> 🌶</span><?php endif; ?>
          </div>
        </div>
        <div class="mi-price">KSh <?= number_format($item['price']) ?></div>
      </div>
      <?php endforeach; ?>
      <?php endforeach; ?>
    </div>
    <div class="order-summary">
      <div class="guests-row">
        <span>Guests:</span>
        <input type="number" id="guests-input" class="guests-input" value="2" min="1" max="20">
      </div>
      <div class="order-lines" id="order-lines">
        <div class="empty-order">No items added yet — tap menu items above</div>
      </div>
      <textarea id="notes-input" class="notes-input" rows="2" placeholder="Special instructions…"></textarea>
      <div class="total-row">
        <span class="total-label">Total</span>
        <span class="total-amt" id="order-total">KSh 0</span>
      </div>
      <div class="action-btns">
        <button class="act-btn act-clear" onclick="clearOrder()">✕</button>
        <button class="act-btn act-send"  onclick="sendToKitchen()">Send to Kitchen</button>
        <button class="act-btn act-bill"  onclick="processBill()">Bill</button>
      </div>
    </div>
  </div>
</div>

<form method="POST" id="order-form" style="display:none">
  <input type="hidden" name="action"     value="send_order">
  <input type="hidden" name="table_id"   id="f-table">
  <input type="hidden" name="guests"     id="f-guests">
  <input type="hidden" name="items_json" id="f-items">
  <input type="hidden" name="notes"      id="f-notes">
</form>
<form method="POST" id="bill-form" style="display:none">
  <input type="hidden" name="action"   value="bill">
  <input type="hidden" name="table_id" id="b-table">
  <input type="hidden" name="order_id" id="b-order">
</form>

<script>
let order = {}, selTableId = null, selTableNum = null, activeOrderId = 0;

function selectTable(id, num, status, orderId, total) {
  document.querySelectorAll('.table-card').forEach(c => c.classList.remove('active'));
  document.getElementById('tc-' + id).classList.add('active');
  selTableId = id; selTableNum = num; activeOrderId = orderId;
  document.getElementById('op-label').textContent = 'Table ' + num;
  document.getElementById('op-sub').textContent = status === 'occupied'
    ? 'Active order — KSh ' + Number(total).toLocaleString()
    : status === 'reserved' ? 'Reserved — new order' : 'Available — new order';
}

function addItem(id, name, price, emoji) {
  if (!selTableId) { alert('Please select a table first.'); return; }
  if (!order[id]) order[id] = { id, name, price, emoji, qty: 0 };
  order[id].qty++;
  renderOrder();
}

function removeItem(id) {
  if (!order[id]) return;
  order[id].qty--;
  if (order[id].qty <= 0) delete order[id];
  renderOrder();
}

function clearOrder() {
  order = {};
  renderOrder();
  document.getElementById('notes-input').value = '';
}

function renderOrder() {
  const lines   = document.getElementById('order-lines');
  const entries = Object.values(order);
  if (!entries.length) {
    lines.innerHTML = '<div class="empty-order">No items added yet — tap menu items above</div>';
    document.getElementById('order-total').textContent = 'KSh 0';
    return;
  }
  let total = 0, html = '';
  entries.forEach(it => {
    const lt = it.price * it.qty;
    total += lt;
    html += `<div class="ol-row">
      <span class="ol-name">${it.emoji} ${it.name}</span>
      <span class="ol-qty">×${it.qty}</span>
      <span class="ol-price">KSh ${lt.toLocaleString()}</span>
      <button class="ol-del" onclick="removeItem(${it.id})">×</button>
    </div>`;
  });
  lines.innerHTML = html;
  document.getElementById('order-total').textContent = 'KSh ' + total.toLocaleString();
}

function sendToKitchen() {
  if (!selTableId) { alert('Select a table first.'); return; }
  if (!Object.keys(order).length) { alert('Add items first.'); return; }
  document.getElementById('f-table').value  = selTableId;
  document.getElementById('f-guests').value = document.getElementById('guests-input').value;
  document.getElementById('f-notes').value  = document.getElementById('notes-input').value;
  document.getElementById('f-items').value  = JSON.stringify(Object.values(order).map(it => ({ id:it.id, name:it.name, price:it.price, qty:it.qty, note:'' })));
  document.getElementById('order-form').submit();
}

function processBill() {
  if (!selTableId)   { alert('Select a table first.'); return; }
  if (!activeOrderId){ alert('No active order on this table.'); return; }
  if (!confirm('Mark order #' + activeOrderId + ' as paid and clear Table ' + selTableNum + '?')) return;
  document.getElementById('b-table').value = selTableId;
  document.getElementById('b-order').value = activeOrderId;
  document.getElementById('bill-form').submit();
}
</script>
</body>
</html>