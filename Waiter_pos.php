<?php
// Waiter_pos.php — Waiter POS
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
        $tableId = (int)$_POST['table_id'];
        $guests  = max(1, (int)($_POST['guests'] ?? 1));
        $notes   = trim($_POST['notes'] ?? '');
        $items   = json_decode($_POST['items_json'] ?? '[]', true);

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
        $orderId  = (int)$_POST['order_id'];
        $tableId  = (int)$_POST['table_id'];
        $method   = $_POST['method']    ?? 'cash';
        $amount   = (float)$_POST['amount'];
        $ref      = trim($_POST['reference'] ?? '');

        // Record payment
        $pdo->prepare(
            "INSERT INTO payments (order_id, method, amount, reference, status, processed_by)
             VALUES (?, ?, ?, ?, 'completed', ?)"
        )->execute([$orderId, $method, $amount, $ref, $_SESSION['user_name']]);

        // Mark order paid & free the table
        $pdo->prepare("UPDATE orders SET status='paid', updated_at=NOW() WHERE id=?")->execute([$orderId]);
        $pdo->prepare("UPDATE table_layout SET status='available' WHERE id=?")->execute([$tableId]);
        $msg = "✅ Payment of KSh " . number_format($amount) . " ({$method}) recorded. Table cleared.";
        $msgType = 'success';
    }
}

// ── DATA ──────────────────────────────────────────────────────────────────────
$tables = $pdo->query(
    "SELECT tl.*,
     (SELECT o.id FROM orders o WHERE o.table_id=tl.id AND o.status NOT IN ('paid','cancelled') ORDER BY o.created_at DESC LIMIT 1) AS active_order_id,
     (SELECT o.total_price FROM orders o WHERE o.table_id=tl.id AND o.status NOT IN ('paid','cancelled') ORDER BY o.created_at DESC LIMIT 1) AS order_total
     FROM table_layout tl ORDER BY tl.table_number"
)->fetchAll(PDO::FETCH_ASSOC);

$menuRows = $pdo->query(
    "SELECT m.*, c.name AS cat_name, c.sort_order AS cat_order
     FROM menu m LEFT JOIN categories c ON m.category_id=c.id
     WHERE m.available=1 ORDER BY c.sort_order, m.name"
)->fetchAll(PDO::FETCH_ASSOC);

$menuByCat = [];
foreach ($menuRows as $row) { $menuByCat[$row['cat_name'] ?? 'Other'][] = $row; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Jiko House — Waiter POS</title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600&family=DM+Sans:wght@300;400;500;600&family=DM+Mono&display=swap" rel="stylesheet">
<style>
  :root {
    --goldlt:  #E8C46A;
    --rust:    #C0622B;
    --amber:   #C07A20;
    --sage:    #5A8A5E;
    --red:     #A83232;
    --gold:    #C49A3C;
    --deep:    #3A2218;
  }
  *, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }
  body { background:#0D1117; font-family:'DM Sans',sans-serif; color:#fff; min-height:100vh; display:flex; flex-direction:column; }

  /* TOP NAV */
  .topnav { background:#161B22; border-bottom:1px solid rgba(255,255,255,.08); padding:0 24px; height:58px; display:flex; align-items:center; justify-content:space-between; flex-shrink:0; }
  .nav-logo { font-family:'Playfair Display',serif; font-size:19px; color:var(--goldlt); letter-spacing:.04em; }
  .nav-right { display:flex; align-items:center; gap:18px; font-size:13px; }
  .nav-user  { color:rgba(255,255,255,.7); font-weight:500; }
  .nav-time  { color:rgba(255,255,255,.3); font-family:'DM Mono',monospace; font-size:12px; }
  .nav-link  { color:rgba(255,255,255,.3); text-decoration:none; font-size:12px; transition:color .15s; }
  .nav-link:hover { color:var(--goldlt); }

  /* FLASH */
  .flash { padding:11px 24px; font-size:13px; font-weight:600; text-align:center; flex-shrink:0; border-bottom:1px solid transparent; }
  .flash.success { background:rgba(90,187,106,.12); border-color:rgba(90,187,106,.2); color:#7ACC88; }
  .flash.error   { background:rgba(168,50,50,.12);  border-color:rgba(168,50,50,.2);  color:#FF6B6B; }

  /* LAYOUT */
  .pos-wrap { display:grid; grid-template-columns:1fr 400px; flex:1; overflow:hidden; min-height:0; height:calc(100vh - 58px); }

  /* ── LEFT: TABLES PANEL ── */
  .tables-panel { padding:22px; overflow-y:auto; background:#0D1117; }
  .panel-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:18px; }
  .panel-title  { font-family:'Playfair Display',serif; font-size:20px; color:var(--goldlt); }
  .legend       { display:flex; gap:14px; font-size:11px; color:rgba(255,255,255,.35); }
  .leg-item     { display:flex; align-items:center; gap:5px; }
  .leg-dot      { width:8px; height:8px; border-radius:50%; }

  .table-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:12px; }

  .table-card {
    background:rgba(255,255,255,.05); border:1.5px solid rgba(255,255,255,.10);
    border-radius:13px; padding:16px 14px; cursor:pointer;
    transition:all .2s; user-select:none; box-shadow:0 2px 12px rgba(0,0,0,.3);
  }
  .table-card:hover  { border-color:rgba(232,196,106,.4); transform:translateY(-2px); box-shadow:0 4px 20px rgba(0,0,0,.4); }
  .table-card.active { border-color:var(--goldlt); background:rgba(232,196,106,.08); }
  .table-card.occupied  { border-left:3px solid #FF6B6B; }
  .table-card.available { border-left:3px solid #7ACC88; }
  .table-card.reserved  { border-left:3px solid var(--goldlt); }

  .tc-num    { font-family:'Playfair Display',serif; font-size:28px; color:#fff; line-height:1; margin-bottom:4px; }
  .tc-status { font-size:10px; letter-spacing:.12em; text-transform:uppercase; font-weight:700; margin-bottom:8px; }
  .ts-occupied  { color:#FF6B6B; }
  .ts-available { color:#7ACC88; }
  .ts-reserved  { color:var(--goldlt); }
  .tc-info  { font-size:11px; color:rgba(255,255,255,.35); }
  .tc-total { font-size:13px; font-weight:600; color:var(--goldlt); margin-top:7px; font-family:'DM Mono',monospace; }

  /* ── RIGHT: ORDER PANEL ── */
  .order-panel { background:#161B22; border-left:1px solid rgba(255,255,255,.08); display:flex; flex-direction:column; overflow:hidden; }

  .op-head { padding:16px 18px 13px; border-bottom:1px solid rgba(255,255,255,.08); flex-shrink:0; background:#1A2030; }
  .op-table { font-family:'Playfair Display',serif; font-size:18px; color:var(--goldlt); margin-bottom:2px; }
  .op-sub   { font-size:11px; color:rgba(255,255,255,.3); }

  /* MENU */
  .menu-section { flex:1; overflow-y:auto; background:#161B22; }
  .cat-header {
    font-size:10px; letter-spacing:.18em; text-transform:uppercase; color:rgba(255,255,255,.3);
    padding:11px 16px 6px; font-weight:700; background:#1A2030;
    position:sticky; top:0; z-index:1; border-bottom:1px solid rgba(255,255,255,.08);
  }
  .menu-item { display:flex; align-items:center; justify-content:space-between; padding:10px 16px; border-bottom:1px solid rgba(255,255,255,.06); cursor:pointer; transition:background .15s; }
  .menu-item:hover { background:rgba(255,255,255,.05); }
  .mi-left  { display:flex; align-items:center; gap:10px; }
  .mi-emoji { font-size:20px; }
  .mi-name  { font-size:13px; font-weight:500; color:rgba(255,255,255,.85); }
  .mi-spicy { font-size:11px; color:#FF6B6B; }
  .mi-price { font-size:13px; font-weight:600; color:var(--goldlt); white-space:nowrap; font-family:'DM Mono',monospace; }

  /* ORDER SUMMARY */
  .order-summary { border-top:1px solid rgba(255,255,255,.08); padding:14px 16px; flex-shrink:0; background:#161B22; }
  .guests-row { display:flex; align-items:center; gap:8px; margin-bottom:12px; font-size:12px; color:rgba(255,255,255,.4); }
  .guests-input { width:60px; background:rgba(255,255,255,.07); border:1.5px solid rgba(255,255,255,.12); border-radius:7px; padding:5px 8px; color:#fff; font-family:'DM Mono',monospace; font-size:13px; outline:none; text-align:center; }
  .guests-input:focus { border-color:rgba(232,196,106,.5); }

  .order-lines { max-height:190px; overflow-y:auto; margin-bottom:12px; }
  .ol-row { display:flex; align-items:center; font-size:13px; padding:6px 0; border-bottom:1px solid rgba(255,255,255,.07); gap:6px; }
  .ol-row:last-child { border-bottom:none; }
  .ol-name  { flex:1; color:rgba(255,255,255,.85); min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
  .ol-qty   { background:rgba(232,196,106,.15); border:1px solid rgba(232,196,106,.2); border-radius:5px; padding:2px 7px; font-size:12px; font-weight:700; color:var(--goldlt); flex-shrink:0; }
  .ol-price { color:rgba(255,255,255,.4); font-size:12px; min-width:65px; text-align:right; flex-shrink:0; font-family:'DM Mono',monospace; }
  .ol-del   { background:none; border:none; color:rgba(255,255,255,.25); cursor:pointer; font-size:16px; padding:0 3px; flex-shrink:0; line-height:1; transition:color .15s; }
  .ol-del:hover { color:#FF6B6B; }
  .empty-order { text-align:center; padding:20px; color:rgba(255,255,255,.2); font-size:13px; font-style:italic; }

  .notes-input { width:100%; background:rgba(255,255,255,.06); border:1.5px solid rgba(255,255,255,.10); border-radius:8px; padding:8px 10px; font-family:'DM Sans',sans-serif; font-size:13px; color:#fff; resize:none; outline:none; margin-bottom:12px; transition:border-color .2s; }
  .notes-input:focus { border-color:rgba(232,196,106,.4); }
  .notes-input::placeholder { color:rgba(255,255,255,.2); }

  .total-row { display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; padding-top:8px; border-top:1px solid rgba(255,255,255,.08); }
  .total-label { font-size:11px; color:rgba(255,255,255,.35); text-transform:uppercase; letter-spacing:.1em; font-weight:600; }
  .total-amt   { font-family:'Playfair Display',serif; font-size:24px; color:var(--goldlt); }

  .action-btns { display:flex; gap:8px; }
  .act-btn { flex:1; padding:11px; border:none; border-radius:9px; font-family:'DM Sans',sans-serif; font-size:12px; font-weight:600; cursor:pointer; transition:all .18s; letter-spacing:.04em; }
  .act-clear { background:transparent; color:rgba(255,255,255,.3); border:1.5px solid rgba(255,255,255,.12); flex:none; padding:11px 14px; }
  .act-clear:hover { border-color:#FF6B6B; color:#FF6B6B; }
  .act-send  { background:rgba(192,122,32,.25); color:var(--goldlt); border:1px solid rgba(192,122,32,.3); }
  .act-send:hover  { background:rgba(192,122,32,.4); }
  .act-bill  { background:rgba(90,187,106,.2); color:#7ACC88; border:1px solid rgba(90,187,106,.3); }
  .act-bill:hover  { background:rgba(90,187,106,.35); }

  /* PAYMENT MODAL */
  .modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.7); z-index:100; align-items:center; justify-content:center; padding:20px; }
  .modal-overlay.open { display:flex; }
  .modal { background:#1E2530; border:1px solid rgba(255,255,255,.12); border-radius:16px; width:100%; max-width:420px; box-shadow:0 20px 60px rgba(0,0,0,.6); animation:popIn .2s ease; }
  @keyframes popIn { from{opacity:0;transform:scale(.95)} to{opacity:1;transform:scale(1)} }
  .modal-head { padding:18px 22px 14px; border-bottom:1px solid rgba(255,255,255,.08); display:flex; align-items:center; justify-content:space-between; }
  .modal-title { font-family:'Playfair Display',serif; font-size:18px; color:var(--goldlt); }
  .modal-close { background:none; border:1px solid rgba(255,255,255,.12); border-radius:7px; width:30px; height:30px; color:rgba(255,255,255,.4); cursor:pointer; font-size:18px; display:flex; align-items:center; justify-content:center; }
  .modal-close:hover { color:#fff; border-color:rgba(255,255,255,.3); }
  .modal-body { padding:20px 22px; }
  .modal-footer { padding:14px 22px; border-top:1px solid rgba(255,255,255,.08); display:flex; gap:10px; }
  .m-label { display:block; font-size:11px; text-transform:uppercase; letter-spacing:.12em; color:rgba(255,255,255,.4); font-weight:600; margin-bottom:7px; }
  .m-input, .m-select { width:100%; background:rgba(255,255,255,.07); border:1.5px solid rgba(255,255,255,.12); border-radius:9px; padding:10px 13px; font-family:'DM Sans',sans-serif; font-size:14px; color:#fff; outline:none; transition:border-color .2s; margin-bottom:16px; }
  .m-input:focus, .m-select:focus { border-color:rgba(232,196,106,.5); }
  .m-select option { background:#1E2530; }
  .method-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:8px; margin-bottom:16px; }
  .method-opt input { display:none; }
  .method-opt label { display:flex; flex-direction:column; align-items:center; gap:4px; padding:10px 6px; border:1.5px solid rgba(255,255,255,.10); border-radius:10px; cursor:pointer; transition:all .2s; font-size:12px; font-weight:600; color:rgba(255,255,255,.5); }
  .method-opt label:hover { border-color:rgba(232,196,106,.3); color:rgba(255,255,255,.8); }
  .method-opt input:checked + label { border-color:var(--goldlt); background:rgba(232,196,106,.1); color:var(--goldlt); }
  .method-icon { font-size:22px; }
  .order-summary-line { background:rgba(255,255,255,.05); border-radius:8px; padding:12px 14px; margin-bottom:16px; display:flex; justify-content:space-between; align-items:center; }
  .osl-label { font-size:12px; color:rgba(255,255,255,.4); }
  .osl-amount { font-family:'Playfair Display',serif; font-size:22px; color:var(--goldlt); }
  .change-row { background:rgba(122,204,136,.1); border:1px solid rgba(122,204,136,.2); border-radius:8px; padding:10px 14px; display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; }
  .change-label { font-size:12px; color:#7ACC88; font-weight:600; }
  .change-amt { font-family:'DM Mono',monospace; font-size:18px; color:#7ACC88; font-weight:700; }
  .btn-pay { flex:1; padding:12px; background:rgba(90,187,106,.25); color:#7ACC88; border:1px solid rgba(90,187,106,.3); border-radius:9px; font-family:'DM Sans',sans-serif; font-size:14px; font-weight:700; cursor:pointer; transition:all .18s; }
  .btn-pay:hover { background:rgba(90,187,106,.4); }
  .btn-cancel-modal { padding:12px 18px; background:transparent; color:rgba(255,255,255,.3); border:1.5px solid rgba(255,255,255,.1); border-radius:9px; font-family:'DM Sans',sans-serif; font-size:13px; font-weight:600; cursor:pointer; transition:all .18s; }
  .btn-cancel-modal:hover { border-color:rgba(255,255,255,.3); color:rgba(255,255,255,.6); }
</style>
</head>
<body>

<div class="topnav">
  <div class="nav-logo">Jiko House · POS</div>
  <div class="nav-right">
    <span class="nav-time" id="nav-clock"><?= date('D, d M · H:i') ?></span>
    <span class="nav-user">👤 <?= htmlspecialchars($_SESSION['user_name'] ?? '') ?></span>
    <a href="logout.php" class="nav-link">Sign out</a>
  </div>
</div>

<?php if ($msg): ?>
<div class="flash <?= $msgType ?>"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<div class="pos-wrap">

  <!-- ── TABLES ── -->
  <div class="tables-panel">
    <div class="panel-header">
      <h2 class="panel-title">Floor Plan</h2>
      <div class="legend">
        <div class="leg-item"><div class="leg-dot" style="background:var(--sage)"></div> Available</div>
        <div class="leg-item"><div class="leg-dot" style="background:var(--rust)"></div> Occupied</div>
        <div class="leg-item"><div class="leg-dot" style="background:var(--amber)"></div> Reserved</div>
      </div>
    </div>
    <div class="table-grid">
      <?php foreach ($tables as $t): ?>
      <div class="table-card <?= htmlspecialchars($t['status']) ?>"
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

  <!-- ── ORDER PANEL ── -->
  <div class="order-panel">
    <div class="op-head">
      <div class="op-table" id="op-label">Select a table</div>
      <div class="op-sub"   id="op-sub">Tap a table to begin an order</div>
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
        <input type="number" id="guests-input" class="guests-input" value="2" min="1" max="30">
      </div>
      <div class="order-lines" id="order-lines">
        <div class="empty-order">No items yet — tap menu items above</div>
      </div>
      <textarea id="notes-input" class="notes-input" rows="2" placeholder="Special instructions…"></textarea>
      <div class="total-row">
        <span class="total-label">Order Total</span>
        <span class="total-amt" id="order-total">KSh 0</span>
      </div>
      <div class="action-btns">
        <button class="act-btn act-clear" onclick="clearOrder()" title="Clear order">✕</button>
        <button class="act-btn act-send"  onclick="sendToKitchen()">🍳 Send to Kitchen</button>
        <button class="act-btn act-bill"  onclick="processBill()">💳 Bill</button>
      </div>
    </div>
  </div>

</div><!-- /.pos-wrap -->

<!-- Hidden forms -->
<form method="POST" id="order-form" style="display:none">
  <input type="hidden" name="action"     value="send_order">
  <input type="hidden" name="table_id"   id="f-table">
  <input type="hidden" name="guests"     id="f-guests">
  <input type="hidden" name="items_json" id="f-items">
  <input type="hidden" name="notes"      id="f-notes">
</form>

<!-- PAYMENT MODAL -->
<div class="modal-overlay" id="pay-modal">
  <div class="modal">
    <div class="modal-head">
      <span class="modal-title">💳 Collect Payment</span>
      <button class="modal-close" onclick="closePayModal()">×</button>
    </div>
    <form method="POST" id="bill-form">
      <input type="hidden" name="action"   value="bill">
      <input type="hidden" name="table_id" id="b-table">
      <input type="hidden" name="order_id" id="b-order">
      <div class="modal-body">

        <!-- Order total summary -->
        <div class="order-summary-line">
          <span class="osl-label">Order Total</span>
          <span class="osl-amount" id="modal-total">KSh 0</span>
        </div>

        <!-- Payment method -->
        <label class="m-label">Payment Method</label>
        <div class="method-grid">
          <div class="method-opt">
            <input type="radio" name="method" id="m-cash" value="cash" checked>
            <label for="m-cash"><span class="method-icon">💵</span>Cash</label>
          </div>
          <div class="method-opt">
            <input type="radio" name="method" id="m-mpesa" value="mpesa">
            <label for="m-mpesa"><span class="method-icon">📱</span>M-Pesa</label>
          </div>
          <div class="method-opt">
            <input type="radio" name="method" id="m-card" value="card">
            <label for="m-card"><span class="method-icon">💳</span>Card</label>
          </div>
        </div>

        <!-- Amount tendered -->
        <label class="m-label" for="pay-amount">Amount Received (KSh)</label>
        <input type="number" name="amount" id="pay-amount" class="m-input"
               placeholder="Enter amount" min="0" step="0.01" required
               oninput="calcChange()">

        <!-- Change due -->
        <div class="change-row" id="change-row" style="display:none">
          <span class="change-label">Change Due</span>
          <span class="change-amt" id="change-amt">KSh 0</span>
        </div>

        <!-- Reference (M-Pesa code etc.) -->
        <label class="m-label" for="pay-ref">Reference / M-Pesa Code <span style="color:rgba(255,255,255,.25)">(optional)</span></label>
        <input type="text" name="reference" id="pay-ref" class="m-input" placeholder="e.g. QJ7X2P4KLM">

      </div>
      <div class="modal-footer">
        <button type="button" class="btn-cancel-modal" onclick="closePayModal()">Cancel</button>
        <button type="submit" class="btn-pay">✓ Confirm Payment</button>
      </div>
    </form>
  </div>
</div>

<script>
let order = {}, selTableId = null, selTableNum = null, activeOrderId = 0, activeOrderTotal = 0;

function selectTable(id, num, status, orderId, total) {
  document.querySelectorAll('.table-card').forEach(c => c.classList.remove('active'));
  document.getElementById('tc-' + id).classList.add('active');
  selTableId = id; selTableNum = num; activeOrderId = orderId; activeOrderTotal = total;
  document.getElementById('op-label').textContent = 'Table ' + num;
  document.getElementById('op-sub').textContent = status === 'occupied'
    ? 'Active order — KSh ' + Number(total).toLocaleString()
    : status === 'reserved' ? 'Reserved — start new order' : 'Available — new order';
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
    lines.innerHTML = '<div class="empty-order">No items yet — tap menu items above</div>';
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
  document.getElementById('f-items').value  = JSON.stringify(
    Object.values(order).map(it => ({ id:it.id, name:it.name, price:it.price, qty:it.qty, note:'' }))
  );
  document.getElementById('order-form').submit();
}

function processBill() {
  if (!selTableId)    { alert('Select a table first.'); return; }
  if (!activeOrderId) { alert('No active order on this table.'); return; }
  // Open payment modal
  document.getElementById('b-table').value  = selTableId;
  document.getElementById('b-order').value  = activeOrderId;
  document.getElementById('modal-total').textContent = 'KSh ' + Number(activeOrderTotal).toLocaleString();
  document.getElementById('pay-amount').value = activeOrderTotal;
  calcChange();
  document.getElementById('pay-modal').classList.add('open');
}

function closePayModal() {
  document.getElementById('pay-modal').classList.remove('open');
}

function calcChange() {
  const total    = parseFloat(activeOrderTotal) || 0;
  const received = parseFloat(document.getElementById('pay-amount').value) || 0;
  const change   = received - total;
  const row      = document.getElementById('change-row');
  const method   = document.querySelector('input[name="method"]:checked')?.value;
  if (method === 'cash' && received > 0) {
    row.style.display = 'flex';
    document.getElementById('change-amt').textContent = 'KSh ' + Math.max(0, change).toLocaleString();
    row.style.background = change < 0 ? 'rgba(255,107,107,.1)' : 'rgba(122,204,136,.1)';
    row.style.borderColor = change < 0 ? 'rgba(255,107,107,.2)' : 'rgba(122,204,136,.2)';
    document.getElementById('change-amt').style.color = change < 0 ? '#FF6B6B' : '#7ACC88';
    document.querySelector('.change-label').textContent = change < 0 ? 'Amount Short' : 'Change Due';
  } else {
    row.style.display = 'none';
  }
}

// Recalculate change when method changes
document.querySelectorAll('input[name="method"]').forEach(r => r.addEventListener('change', calcChange));

// Live clock
setInterval(() => {
  const n = new Date();
  const pad = v => String(v).padStart(2,'0');
  const days = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
  const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
  document.getElementById('nav-clock').textContent =
    days[n.getDay()] + ', ' + pad(n.getDate()) + ' ' + months[n.getMonth()] + ' · ' + pad(n.getHours()) + ':' + pad(n.getMinutes());
}, 10000);
</script>
</body>
</html>
