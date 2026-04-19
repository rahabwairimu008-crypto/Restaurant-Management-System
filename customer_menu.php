<?php
// customer_menu.php — Customer Menu & Ordering
session_start();
require_once 'dbconfig.php';

// Redirect guests to login
if (empty($_SESSION['user_id']) || $_SESSION['role'] !== 'customer') {
    header('Location: login.php'); exit;
}

$pdo = getDB();
$pdo->exec("SET NAMES 'utf8mb4' COLLATE 'utf8mb4_unicode_ci'");

$uid = (int)$_SESSION['user_id'];
$msg = ''; $msgType = '';

// ── ACTIONS ───────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Add item to cart
    if ($action === 'add_cart') {
        $mid = (int)$_POST['menu_item_id'];
        $qty = max(1, (int)($_POST['qty'] ?? 1));
        $item = $pdo->prepare("SELECT * FROM menu WHERE id = ? AND available = 1");
        $item->execute([$mid]);
        $item = $item->fetch();
        if ($item) {
            $ex = $pdo->prepare("SELECT id, quantity FROM cart WHERE user_id = ? AND menu_item_id = ?");
            $ex->execute([$uid, $mid]);
            $ex = $ex->fetch();
            if ($ex) {
                $pdo->prepare("UPDATE cart SET quantity = quantity + ? WHERE id = ?")
                    ->execute([$qty, $ex['id']]);
            } else {
                $pdo->prepare("INSERT INTO cart (user_id, menu_item_id, name, price, quantity) VALUES (?,?,?,?,?)")
                    ->execute([$uid, $mid, $item['name'], $item['price'], $qty]);
            }
            $msg = "✅ {$item['name']} added to cart!";
            $msgType = 'success';
        }
    }

    // Remove single cart item
    if ($action === 'remove_cart') {
        $pdo->prepare("DELETE FROM cart WHERE id = ? AND user_id = ?")
            ->execute([(int)$_POST['cart_id'], $uid]);
    }

    // Update quantity in cart
    if ($action === 'update_qty') {
        $newQty = (int)$_POST['new_qty'];
        if ($newQty <= 0) {
            $pdo->prepare("DELETE FROM cart WHERE id = ? AND user_id = ?")
                ->execute([(int)$_POST['cart_id'], $uid]);
        } else {
            $pdo->prepare("UPDATE cart SET quantity = ? WHERE id = ? AND user_id = ?")
                ->execute([$newQty, (int)$_POST['cart_id'], $uid]);
        }
    }

    // Place order with payment
    if ($action === 'checkout') {
        $cartItems = $pdo->prepare("SELECT * FROM cart WHERE user_id = ?");
        $cartItems->execute([$uid]);
        $cartItems = $cartItems->fetchAll();

        if (!empty($cartItems)) {
            $total    = array_sum(array_map(fn($i) => $i['price'] * $i['quantity'], $cartItems));
            $method   = $_POST['payment_method'] ?? 'cash';
            $mpesa    = trim($_POST['mpesa_code'] ?? '');
            $notes    = trim($_POST['notes'] ?? '');
            $tableNum = (int)($_POST['table_number'] ?? 0);

            // Find table_id from table_number if given
            $tableId = null;
            if ($tableNum > 0) {
                $tRow = $pdo->prepare("SELECT id FROM table_layout WHERE table_number = ?");
                $tRow->execute([$tableNum]);
                $tRow = $tRow->fetch();
                $tableId = $tRow ? $tRow['id'] : null;
            }

            // Create order
            $pdo->prepare("INSERT INTO orders (user_id, table_id, status, total_price, notes) VALUES (?, ?, 'pending', ?, ?)")
                ->execute([$uid, $tableId, $total, $notes]);
            $orderId = $pdo->lastInsertId();

            // Insert order items
            foreach ($cartItems as $ci) {
                $pdo->prepare("INSERT INTO order_items (order_id, menu_item_id, name, price, quantity) VALUES (?,?,?,?,?)")
                    ->execute([$orderId, $ci['menu_item_id'], $ci['name'], $ci['price'], $ci['quantity']]);
            }

            // Record payment
            $payStatus = ($method === 'mpesa' && $mpesa) ? 'completed' : ($method === 'cash' ? 'pending' : 'pending');
            $pdo->prepare("INSERT INTO payments (order_id, method, amount, reference, status, processed_by) VALUES (?,?,?,?,?,?)")
                ->execute([$orderId, $method, $total, $mpesa ?: null, $payStatus, 'Customer']);

            // If M-Pesa code provided, mark order paid
            if ($method === 'mpesa' && $mpesa) {
                $pdo->prepare("UPDATE orders SET status = 'paid' WHERE id = ?")
                    ->execute([$orderId]);
            }

            // Clear cart
            $pdo->prepare("DELETE FROM cart WHERE user_id = ?")->execute([$uid]);

            $payNote = ($method === 'mpesa' && $mpesa) ? 'M-Pesa payment confirmed.' : 'Pay '.($method === 'cash' ? 'cash' : 'at counter').' when served.';
            $msg = "✅ Order #$orderId placed! $payNote";
            $msgType = 'success';
        } else {
            $msg = 'Your cart is empty.';
            $msgType = 'error';
        }
    }
}

// ── DATA ──────────────────────────────────────────────────────────────────────
$cats = $pdo->query("SELECT * FROM categories WHERE active = 1 ORDER BY sort_order")->fetchAll();
$filterCat = (int)($_GET['cat'] ?? 0);
$searchQ   = trim($_GET['q'] ?? '');

$where = ['m.available = 1'];
$params = [];
if ($filterCat) { $where[] = 'm.category_id = ?'; $params[] = $filterCat; }
if ($searchQ)   { $where[] = '(m.name LIKE ? OR m.description LIKE ?)'; $params[] = "%$searchQ%"; $params[] = "%$searchQ%"; }

$stmt = $pdo->prepare("SELECT m.*, c.name AS cat_name FROM menu m LEFT JOIN categories c ON m.category_id = c.id WHERE " . implode(' AND ', $where) . " ORDER BY c.sort_order, m.name");
$stmt->execute($params);
$menuItems = $stmt->fetchAll();

// Group by category for display
$byCategory = [];
foreach ($menuItems as $item) {
    $byCategory[$item['cat_name'] ?? 'Other'][] = $item;
}

// Cart
$cartStmt = $pdo->prepare("SELECT * FROM cart WHERE user_id = ? ORDER BY created_at");
$cartStmt->execute([$uid]);
$cartItems = $cartStmt->fetchAll();
$cartTotal = array_sum(array_map(fn($i) => $i['price'] * $i['quantity'], $cartItems));
$cartCount = array_sum(array_column($cartItems, 'quantity'));

// My orders
$myOrders = $pdo->prepare(
    "SELECT o.*, (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.id) AS item_count
     FROM orders o WHERE o.user_id = ? ORDER BY o.created_at DESC LIMIT 8");
$myOrders->execute([$uid]);
$myOrders = $myOrders->fetchAll();

// Tables list for checkout
$tables = $pdo->query("SELECT table_number, capacity FROM table_layout ORDER BY table_number")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Jiko House — Menu & Order</title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,600;1,400&family=DM+Sans:wght@300;400;500;600&family=DM+Mono&display=swap" rel="stylesheet">
<style>
  :root {
    --cream: #F9F3E8; --parchment: #EDE4D0; --warm-brown: #5C3D2E;
    --deep-brown: #3A2218; --rust: #C0622B; --gold: #C49A3C;
    --sage: #6B7C5E; --text: #2C1A0E; --text-muted: #7A5C45;
    --text-dim: #B09A85; --white: #FFFDF8;
    --shadow: 0 4px 24px rgba(60,34,24,.10);
    --shadow-sm: 0 2px 10px rgba(60,34,24,.08);
    --border: rgba(92,61,46,.12);
    --red: #A83232;
  }
  * { margin:0; padding:0; box-sizing:border-box; }
  body { background:var(--cream); font-family:'DM Sans',sans-serif; color:var(--text);
    background-image: radial-gradient(ellipse at 20% 0%, rgba(196,154,60,.07) 0%, transparent 55%),
                      radial-gradient(ellipse at 80% 100%, rgba(192,98,43,.06) 0%, transparent 55%);
  }

  /* ── HEADER ── */
  header { background:var(--deep-brown); position:sticky; top:0; z-index:200; box-shadow:0 2px 14px rgba(0,0,0,.25); }
  .hdr { max-width:1140px; margin:0 auto; padding:14px 20px; display:flex; align-items:center; justify-content:space-between; gap:16px; }
  .logo-wrap .logo-name { font-family:'Playfair Display',serif; font-size:22px; color:var(--gold); letter-spacing:.04em; line-height:1; }
  .logo-wrap .logo-sub  { font-size:10px; letter-spacing:.2em; text-transform:uppercase; color:rgba(249,243,232,.35); margin-top:3px; }
  .hdr-center { flex:1; max-width:400px; }
  .search-bar { display:flex; align-items:center; background:rgba(255,255,255,.07); border:1.5px solid rgba(196,154,60,.2); border-radius:10px; padding:8px 14px; gap:8px; }
  .search-bar input { border:none; background:none; outline:none; font-family:'DM Sans',sans-serif; font-size:14px; color:var(--white); flex:1; }
  .search-bar input::placeholder { color:rgba(249,243,232,.35); }
  .hdr-right { display:flex; align-items:center; gap:14px; }
  .user-name { font-size:13px; color:rgba(249,243,232,.6); }
  .cart-fab { background:var(--rust); color:#fff; border:none; border-radius:10px; padding:9px 18px; font-family:'DM Sans',sans-serif; font-size:14px; font-weight:600; cursor:pointer; display:flex; align-items:center; gap:8px; transition:background .2s; position:relative; }
  .cart-fab:hover { background:#A8501E; }
  .cart-badge { background:rgba(255,255,255,.25); border-radius:10px; padding:1px 7px; font-size:12px; font-weight:700; }
  .logout-link { color:rgba(249,243,232,.4); font-size:12px; text-decoration:none; }
  .logout-link:hover { color:var(--gold); }

  /* ── FLASH ── */
  .flash-wrap { max-width:1140px; margin:16px auto 0; padding:0 20px; }
  .flash { padding:12px 18px; border-radius:10px; font-size:14px; font-weight:500; border:1px solid; animation:fadeDown .3s; }
  @keyframes fadeDown { from{opacity:0;transform:translateY(-8px)} to{opacity:1;transform:none} }
  .flash.success { background:rgba(107,124,94,.12); border-color:rgba(107,124,94,.3); color:var(--sage); }
  .flash.error   { background:rgba(168,50,50,.1);   border-color:rgba(168,50,50,.25); color:var(--red); }

  /* ── MAIN LAYOUT ── */
  .page-wrap { max-width:1140px; margin:0 auto; padding:24px 20px; display:grid; grid-template-columns:1fr 340px; gap:28px; align-items:start; }
  @media(max-width:800px) { .page-wrap { grid-template-columns:1fr; } .cart-sidebar { display:none; } }

  /* ── CATEGORY TABS ── */
  .cat-scroll { display:flex; gap:8px; overflow-x:auto; scrollbar-width:none; margin-bottom:24px; padding-bottom:4px; }
  .cat-scroll::-webkit-scrollbar { display:none; }
  .cat-pill { flex-shrink:0; padding:8px 18px; border-radius:20px; font-size:13px; font-weight:600; border:1.5px solid rgba(92,61,46,.18); background:var(--white); color:var(--text-muted); text-decoration:none; transition:all .2s; white-space:nowrap; }
  .cat-pill.active, .cat-pill:hover { background:var(--warm-brown); color:#fff; border-color:var(--warm-brown); }

  /* ── MENU SECTION ── */
  .cat-section { margin-bottom:28px; animation:fadeUp .35s ease both; }
  @keyframes fadeUp { from{opacity:0;transform:translateY(10px)} to{opacity:1;transform:none} }
  .cat-heading { font-family:'Playfair Display',serif; font-size:20px; color:var(--deep-brown); margin-bottom:14px; padding-bottom:8px; border-bottom:2px solid var(--parchment); display:flex; align-items:center; gap:10px; }
  .cat-heading::after { content:''; flex:1; height:1px; background:var(--parchment); }
  .menu-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(280px, 1fr)); gap:14px; }
  .menu-card { background:var(--white); border-radius:14px; display:flex; overflow:hidden; box-shadow:var(--shadow-sm); border:1px solid var(--border); transition:transform .18s, box-shadow .18s; cursor:default; }
  .menu-card:hover { transform:translateY(-2px); box-shadow:var(--shadow); }
  .card-emoji-box { width:80px; min-height:100px; flex-shrink:0; background:var(--parchment); display:flex; align-items:center; justify-content:center; font-size:38px; position:relative; }
  .spicy-tag { position:absolute; top:6px; right:4px; font-size:13px; }
  .card-body { flex:1; padding:13px 14px 12px; display:flex; flex-direction:column; justify-content:space-between; }
  .badge-row { margin-bottom:4px; }
  .badge-chip { display:inline-block; font-size:10px; letter-spacing:.08em; text-transform:uppercase; font-weight:700; padding:2px 7px; border-radius:4px; }
  .bc-chef { background:rgba(196,154,60,.15); color:var(--gold); }
  .bc-new  { background:rgba(107,124,94,.15); color:var(--sage); }
  .card-name { font-family:'Playfair Display',serif; font-size:15px; font-weight:600; color:var(--deep-brown); margin-bottom:4px; line-height:1.3; }
  .card-desc { font-size:12px; color:var(--text-muted); line-height:1.45; margin-bottom:10px; flex:1; }
  .card-foot { display:flex; align-items:center; justify-content:space-between; gap:10px; }
  .card-price { font-family:'Playfair Display',serif; font-size:17px; color:var(--warm-brown); font-weight:600; }
  .qty-wrap { display:flex; align-items:center; gap:6px; }
  .qty-btn { width:28px; height:28px; border-radius:7px; border:1.5px solid rgba(92,61,46,.2); background:var(--parchment); color:var(--warm-brown); font-size:16px; cursor:pointer; display:flex; align-items:center; justify-content:center; transition:all .15s; }
  .qty-btn:hover { background:var(--warm-brown); color:#fff; border-color:var(--warm-brown); }
  .qty-num { font-size:13px; font-weight:700; color:var(--deep-brown); min-width:16px; text-align:center; }
  .add-btn { background:var(--rust); color:#fff; border:none; border-radius:8px; padding:7px 14px; font-family:'DM Sans',sans-serif; font-size:12px; font-weight:600; cursor:pointer; transition:background .18s; white-space:nowrap; }
  .add-btn:hover { background:var(--warm-brown); }

  /* ── CART SIDEBAR ── */
  .cart-sidebar { background:var(--white); border-radius:16px; box-shadow:var(--shadow); border:1px solid var(--border); overflow:hidden; position:sticky; top:80px; }
  .cart-head { background:var(--deep-brown); padding:16px 18px; display:flex; align-items:center; justify-content:space-between; }
  .cart-title { font-family:'Playfair Display',serif; font-size:17px; color:var(--gold); }
  .cart-sub   { font-size:12px; color:rgba(249,243,232,.45); }
  .cart-items-list { padding:12px 16px; max-height:340px; overflow-y:auto; }
  .ci-row { display:flex; align-items:center; gap:8px; padding:9px 0; border-bottom:1px solid rgba(92,61,46,.08); font-size:13px; }
  .ci-row:last-child { border-bottom:none; }
  .ci-emoji { font-size:18px; flex-shrink:0; }
  .ci-name { font-weight:500; color:var(--deep-brown); flex:1; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
  .ci-qty-wrap { display:flex; align-items:center; gap:4px; flex-shrink:0; }
  .ci-qbtn { width:22px; height:22px; border-radius:5px; border:1px solid var(--border); background:var(--parchment); color:var(--warm-brown); font-size:14px; cursor:pointer; display:flex; align-items:center; justify-content:center; }
  .ci-qnum { font-size:12px; font-weight:700; color:var(--deep-brown); min-width:16px; text-align:center; }
  .ci-price { font-size:12px; color:var(--text-muted); font-family:'DM Mono',monospace; min-width:58px; text-align:right; }
  .ci-del { background:none; border:none; color:rgba(168,50,50,.5); cursor:pointer; font-size:16px; padding:0 2px; flex-shrink:0; }
  .ci-del:hover { color:var(--red); }
  .cart-empty { text-align:center; padding:30px 16px; color:var(--text-muted); font-size:13px; font-style:italic; }
  .cart-empty-icon { font-size:36px; margin-bottom:10px; }
  .cart-footer { border-top:1px solid var(--border); padding:14px 18px; }
  .cart-total-row { display:flex; justify-content:space-between; align-items:baseline; margin-bottom:14px; }
  .cart-total-label { font-size:12px; color:var(--text-muted); text-transform:uppercase; letter-spacing:.08em; }
  .cart-total-amt { font-family:'Playfair Display',serif; font-size:22px; color:var(--deep-brown); }
  .checkout-btn { width:100%; background:var(--rust); color:#fff; border:none; border-radius:11px; padding:13px; font-family:'DM Sans',sans-serif; font-size:14px; font-weight:600; cursor:pointer; transition:background .2s; margin-bottom:8px; }
  .checkout-btn:hover { background:var(--warm-brown); }
  .view-cart-btn { width:100%; background:var(--parchment); color:var(--warm-brown); border:1.5px solid var(--border); border-radius:11px; padding:10px; font-family:'DM Sans',sans-serif; font-size:13px; font-weight:600; cursor:pointer; text-align:center; text-decoration:none; display:block; transition:all .2s; }
  .view-cart-btn:hover { background:var(--warm-brown); color:#fff; border-color:var(--warm-brown); }

  /* ── MY ORDERS ── */
  .orders-section { margin-top:10px; }
  .orders-title { font-family:'Playfair Display',serif; font-size:18px; color:var(--deep-brown); margin-bottom:14px; }
  .order-card { background:var(--white); border-radius:12px; padding:14px 16px; margin-bottom:10px; box-shadow:var(--shadow-sm); border:1px solid var(--border); display:flex; align-items:center; justify-content:space-between; gap:14px; }
  .oc-left { flex:1; min-width:0; }
  .oc-id   { font-size:12px; color:var(--text-muted); font-family:'DM Mono',monospace; margin-bottom:2px; }
  .oc-items{ font-size:12px; color:var(--text-dim); }
  .oc-total{ font-family:'Playfair Display',serif; font-size:17px; color:var(--deep-brown); font-weight:600; white-space:nowrap; }
  .oc-status { display:inline-block; padding:3px 10px; border-radius:5px; font-size:11px; font-weight:700; text-transform:uppercase; white-space:nowrap; }
  .s-pending  { background:rgba(74,144,217,.12); color:#3A6FA8; }
  .s-cooking  { background:rgba(224,160,32,.12);  color:#A07020; }
  .s-ready    { background:rgba(90,138,94,.12);   color:#3A7A40; }
  .s-served   { background:rgba(92,61,46,.10);    color:var(--warm-brown); }
  .s-paid     { background:rgba(90,138,94,.08);   color:var(--sage); }
  .s-cancelled{ background:rgba(168,50,50,.08);   color:var(--red); }

  /* ── CHECKOUT MODAL ── */
  .modal-overlay { display:none; position:fixed; inset:0; background:rgba(26,10,4,.65); z-index:300; align-items:center; justify-content:center; padding:20px; }
  .modal-overlay.open { display:flex; }
  .modal { background:var(--white); border-radius:18px; width:100%; max-width:500px; max-height:92vh; overflow-y:auto; box-shadow:0 20px 60px rgba(0,0,0,.3); border:1px solid var(--border); animation:popIn .25s ease; }
  @keyframes popIn { from{opacity:0;transform:scale(.96)} to{opacity:1;transform:scale(1)} }
  .modal-head { background:var(--deep-brown); padding:20px 22px 16px; display:flex; align-items:center; justify-content:space-between; position:sticky; top:0; z-index:1; }
  .modal-title { font-family:'Playfair Display',serif; font-size:20px; color:var(--gold); }
  .modal-close { background:none; border:none; color:rgba(249,243,232,.5); font-size:24px; cursor:pointer; line-height:1; }
  .modal-close:hover { color:var(--gold); }
  .modal-body { padding:22px; }
  .section-label { font-size:11px; text-transform:uppercase; letter-spacing:.14em; color:var(--text-muted); font-weight:700; margin-bottom:10px; }
  /* Order summary in modal */
  .order-summary { background:var(--parchment); border-radius:10px; padding:14px 16px; margin-bottom:18px; }
  .os-row { display:flex; justify-content:space-between; font-size:13px; padding:5px 0; border-bottom:1px solid rgba(92,61,46,.1); }
  .os-row:last-child { border-bottom:none; font-weight:700; font-size:15px; color:var(--deep-brown); padding-top:10px; margin-top:4px; }
  /* Form fields */
  .form-group { margin-bottom:16px; }
  .form-label { display:block; font-size:11px; text-transform:uppercase; letter-spacing:.12em; color:var(--text-muted); font-weight:600; margin-bottom:7px; }
  .form-input, .form-select, .form-textarea { width:100%; background:var(--cream); border:1.5px solid rgba(92,61,46,.18); border-radius:10px; padding:11px 14px; font-family:'DM Sans',sans-serif; font-size:14px; color:var(--text); outline:none; transition:border-color .2s; }
  .form-input:focus, .form-select:focus, .form-textarea:focus { border-color:rgba(192,98,43,.5); }
  .form-textarea { resize:vertical; min-height:60px; }
  /* Payment methods */
  .pay-methods { display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:16px; }
  .pay-opt { border:2px solid var(--border); border-radius:12px; padding:14px 12px; cursor:pointer; transition:all .2s; text-align:center; position:relative; }
  .pay-opt:hover { border-color:var(--rust); }
  .pay-opt.selected { border-color:var(--rust); background:rgba(192,98,43,.06); }
  .pay-opt input[type=radio] { position:absolute; opacity:0; width:0; height:0; }
  .pay-icon { font-size:28px; margin-bottom:6px; }
  .pay-name { font-size:13px; font-weight:600; color:var(--deep-brown); }
  .pay-desc { font-size:11px; color:var(--text-muted); margin-top:2px; }
  .mpesa-field { display:none; animation:fadeDown .25s ease; }
  .mpesa-field.show { display:block; }
  .mpesa-note { background:rgba(107,124,94,.1); border:1px solid rgba(107,124,94,.25); border-radius:8px; padding:10px 14px; font-size:12px; color:var(--sage); margin-bottom:12px; }
  /* Confirm btn */
  .modal-footer { padding:16px 22px; border-top:1px solid var(--border); display:flex; gap:10px; }
  .confirm-btn { flex:2; background:var(--rust); color:#fff; border:none; border-radius:11px; padding:14px; font-family:'DM Sans',sans-serif; font-size:15px; font-weight:600; cursor:pointer; transition:background .2s; }
  .confirm-btn:hover { background:var(--warm-brown); }
  .cancel-btn { flex:1; background:var(--parchment); color:var(--warm-brown); border:1.5px solid var(--border); border-radius:11px; padding:14px; font-family:'DM Sans',sans-serif; font-size:14px; font-weight:600; cursor:pointer; transition:all .2s; }
  .cancel-btn:hover { background:var(--warm-brown); color:#fff; }
  /* Empty menu */
  .empty-menu { text-align:center; padding:60px 20px; color:var(--text-muted); }
  .empty-icon { font-size:48px; margin-bottom:14px; }
</style>
</head>
<body>

<!-- ── HEADER ─────────────────────────────────────────────────────────────── -->
<header>
  <div class="hdr">
    <div class="logo-wrap">
      <div class="logo-name">Jiko House</div>
      <div class="logo-sub">Traditional Kitchen</div>
    </div>

    <div class="hdr-center">
      <form method="GET" class="search-bar">
        <?php if ($filterCat): ?><input type="hidden" name="cat" value="<?= $filterCat ?>"><?php endif; ?>
        <span style="color:rgba(249,243,232,.4)">🔍</span>
        <input type="text" name="q" placeholder="Search dishes…" value="<?= htmlspecialchars($searchQ) ?>" autocomplete="off">
      </form>
    </div>

    <div class="hdr-right">
      <span class="user-name">👤 <?= htmlspecialchars($_SESSION['user_name'] ?? '') ?></span>
      <button class="cart-fab" onclick="openCheckout()">
        🛒 Cart
        <?php if ($cartCount > 0): ?>
          <span class="cart-badge"><?= $cartCount ?></span>
        <?php endif; ?>
      </button>
      <a href="logout.php" class="logout-link">Sign out</a>
    </div>
  </div>
</header>

<!-- ── FLASH MESSAGE ─────────────────────────────────────────────────────── -->
<?php if ($msg): ?>
<div class="flash-wrap">
  <div class="flash <?= $msgType ?>"><?= htmlspecialchars($msg) ?></div>
</div>
<?php endif; ?>

<!-- ── PAGE ──────────────────────────────────────────────────────────────── -->
<div class="page-wrap">

  <!-- LEFT — MENU -->
  <div>
    <!-- Category pills -->
    <div class="cat-scroll">
      <a href="customer_menu.php<?= $searchQ ? '?q='.urlencode($searchQ) : '' ?>" class="cat-pill <?= !$filterCat ? 'active' : '' ?>">All</a>
      <?php foreach ($cats as $c): ?>
      <a href="?cat=<?= $c['id'] ?><?= $searchQ ? '&q='.urlencode($searchQ) : '' ?>"
         class="cat-pill <?= $filterCat == $c['id'] ? 'active' : '' ?>">
        <?= htmlspecialchars($c['name']) ?>
      </a>
      <?php endforeach; ?>
    </div>

    <!-- Menu items grouped by category -->
    <?php if (empty($menuItems)): ?>
    <div class="empty-menu">
      <div class="empty-icon">🍽</div>
      <div style="font-family:'Playfair Display',serif;font-size:20px;color:var(--deep-brown);margin-bottom:8px">Nothing found</div>
      <div>Try a different search or category.</div>
    </div>
    <?php else: ?>
    <?php foreach ($byCategory as $catName => $items): ?>
    <div class="cat-section">
      <div class="cat-heading"><?= htmlspecialchars($catName) ?></div>
      <div class="menu-grid">
        <?php foreach ($items as $item): ?>
        <div class="menu-card">
          <div class="card-emoji-box">
            <?= $item['emoji'] ?>
            <?php if ($item['spicy']): ?><span class="spicy-tag">🌶</span><?php endif; ?>
          </div>
          <div class="card-body">
            <?php if ($item['badge']): $bc = stripos($item['badge'],'chef') !== false ? 'bc-chef' : 'bc-new'; ?>
            <div class="badge-row"><span class="badge-chip <?= $bc ?>"><?= htmlspecialchars($item['badge']) ?></span></div>
            <?php endif; ?>
            <div class="card-name"><?= htmlspecialchars($item['name']) ?></div>
            <div class="card-desc"><?= htmlspecialchars($item['description'] ?? '') ?></div>
            <div class="card-foot">
              <div class="card-price">KSh <?= number_format($item['price']) ?></div>
              <form method="POST" style="display:flex;align-items:center;gap:6px">
                <input type="hidden" name="action" value="add_cart">
                <input type="hidden" name="menu_item_id" value="<?= $item['id'] ?>">
                <div class="qty-wrap">
                  <button type="button" class="qty-btn" onclick="decQty(this)">−</button>
                  <span class="qty-num">1</span>
                  <button type="button" class="qty-btn" onclick="incQty(this)">+</button>
                  <input type="hidden" name="qty" class="qty-hidden" value="1">
                </div>
                <button type="submit" class="add-btn">Add +</button>
              </form>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

    <!-- My Recent Orders -->
    <?php if (!empty($myOrders)): ?>
    <div class="orders-section">
      <div class="orders-title">My Recent Orders</div>
      <?php foreach ($myOrders as $o):
        $sc = 's-' . preg_replace('/[^a-z]/', '', strtolower($o['status']));
      ?>
      <div class="order-card">
        <div class="oc-left">
          <div class="oc-id">#<?= $o['id'] ?> · <?= $o['item_count'] ?> item<?= $o['item_count'] != 1 ? 's' : '' ?></div>
          <div class="oc-items"><?= date('d M Y, H:i', strtotime($o['created_at'])) ?></div>
        </div>
        <div class="oc-total">KSh <?= number_format($o['total_price']) ?></div>
        <span class="oc-status <?= $sc ?>"><?= htmlspecialchars($o['status']) ?></span>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <!-- RIGHT — CART SIDEBAR -->
  <div class="cart-sidebar">
    <div class="cart-head">
      <div class="cart-title">Your Cart</div>
      <span class="cart-sub"><?= $cartCount ?> item<?= $cartCount != 1 ? 's' : '' ?></span>
    </div>

    <?php if (empty($cartItems)): ?>
    <div class="cart-empty">
      <div class="cart-empty-icon">🛒</div>
      Add dishes from the menu to start your order.
    </div>
    <?php else: ?>
    <div class="cart-items-list">
      <?php foreach ($cartItems as $ci): ?>
      <div class="ci-row">
        <span class="ci-emoji"><?= $ci['name'][0] ?? '🍽' ?></span>
        <span class="ci-name" title="<?= htmlspecialchars($ci['name']) ?>"><?= htmlspecialchars($ci['name']) ?></span>
        <div class="ci-qty-wrap">
          <form method="POST" style="display:inline">
            <input type="hidden" name="action" value="update_qty">
            <input type="hidden" name="cart_id" value="<?= $ci['id'] ?>">
            <input type="hidden" name="new_qty" value="<?= $ci['quantity'] - 1 ?>">
            <button type="submit" class="ci-qbtn">−</button>
          </form>
          <span class="ci-qnum"><?= $ci['quantity'] ?></span>
          <form method="POST" style="display:inline">
            <input type="hidden" name="action" value="update_qty">
            <input type="hidden" name="cart_id" value="<?= $ci['id'] ?>">
            <input type="hidden" name="new_qty" value="<?= $ci['quantity'] + 1 ?>">
            <button type="submit" class="ci-qbtn">+</button>
          </form>
        </div>
        <span class="ci-price">KSh <?= number_format($ci['price'] * $ci['quantity']) ?></span>
        <form method="POST" style="display:inline">
          <input type="hidden" name="action" value="remove_cart">
          <input type="hidden" name="cart_id" value="<?= $ci['id'] ?>">
          <button type="submit" class="ci-del">×</button>
        </form>
      </div>
      <?php endforeach; ?>
    </div>
    <div class="cart-footer">
      <div class="cart-total-row">
        <span class="cart-total-label">Total</span>
        <span class="cart-total-amt">KSh <?= number_format($cartTotal) ?></span>
      </div>
      <button class="checkout-btn" onclick="openCheckout()">Checkout & Pay →</button>
      <a href="customer_cart.php" class="view-cart-btn">View Full Cart</a>
    </div>
    <?php endif; ?>
  </div>

</div>

<!-- ── CHECKOUT MODAL ─────────────────────────────────────────────────────── -->
<div class="modal-overlay" id="checkout-modal" onclick="if(event.target===this)closeCheckout()">
  <div class="modal">
    <div class="modal-head">
      <span class="modal-title">Checkout</span>
      <button class="modal-close" onclick="closeCheckout()">×</button>
    </div>

    <?php if (empty($cartItems)): ?>
    <div class="modal-body" style="text-align:center;padding:40px">
      <div style="font-size:40px;margin-bottom:14px">🛒</div>
      <div style="font-family:'Playfair Display',serif;font-size:18px;color:var(--deep-brown)">Your cart is empty</div>
      <div style="font-size:13px;color:var(--text-muted);margin-top:8px">Add items from the menu first.</div>
    </div>
    <?php else: ?>
    <form method="POST" id="checkout-form">
      <input type="hidden" name="action" value="checkout">
      <div class="modal-body">

        <!-- ORDER SUMMARY -->
        <div class="section-label">Order Summary</div>
        <div class="order-summary">
          <?php foreach ($cartItems as $ci): ?>
          <div class="os-row">
            <span><?= htmlspecialchars($ci['name']) ?> ×<?= $ci['quantity'] ?></span>
            <span>KSh <?= number_format($ci['price'] * $ci['quantity']) ?></span>
          </div>
          <?php endforeach; ?>
          <div class="os-row">
            <span>Total</span>
            <span>KSh <?= number_format($cartTotal) ?></span>
          </div>
        </div>

        <!-- TABLE -->
        <div class="form-group">
          <label class="form-label">Table Number (optional)</label>
          <select name="table_number" class="form-select">
            <option value="">— Takeaway / Not at a table —</option>
            <?php foreach ($tables as $t): ?>
            <option value="<?= $t['table_number'] ?>">Table <?= $t['table_number'] ?> (<?= $t['capacity'] ?> seats)</option>
            <?php endforeach; ?>
          </select>
        </div>

        <!-- PAYMENT METHOD -->
        <div class="section-label" style="margin-top:4px">Payment Method</div>
        <div class="pay-methods">
          <label class="pay-opt selected" id="opt-cash" onclick="selectPay('cash')">
            <input type="radio" name="payment_method" value="cash" checked>
            <div class="pay-icon">💵</div>
            <div class="pay-name">Cash</div>
            <div class="pay-desc">Pay when served</div>
          </label>
          <label class="pay-opt" id="opt-mpesa" onclick="selectPay('mpesa')">
            <input type="radio" name="payment_method" value="mpesa">
            <div class="pay-icon">📱</div>
            <div class="pay-name">M-Pesa</div>
            <div class="pay-desc">Enter code below</div>
          </label>
        </div>

        <!-- MPESA FIELD -->
        <div class="mpesa-field" id="mpesa-field">
          <div class="mpesa-note">
            📲 Send <strong>KSh <?= number_format($cartTotal) ?></strong> to Till/Paybill, then enter your M-Pesa confirmation code below.
          </div>
          <div class="form-group">
            <label class="form-label">M-Pesa Confirmation Code</label>
            <input type="text" name="mpesa_code" id="mpesa-code" class="form-input" placeholder="e.g. QJ7X2P4KLM" style="font-family:'DM Mono',monospace;letter-spacing:.08em" maxlength="12">
          </div>
        </div>

        <!-- CARD -->
        <div class="mpesa-field" id="card-field">
          <div class="mpesa-note" style="background:rgba(74,144,217,.08);border-color:rgba(74,144,217,.2);color:#3A6FA8;">
            💳 Card payment will be processed at the counter when your order is ready.
          </div>
        </div>

        <!-- NOTES -->
        <div class="form-group">
          <label class="form-label">Special Instructions (optional)</label>
          <textarea name="notes" class="form-textarea" placeholder="Allergies, preferences, seating requests…"></textarea>
        </div>

      </div>
      <div class="modal-footer">
        <button type="button" class="cancel-btn" onclick="closeCheckout()">Cancel</button>
        <button type="submit" class="confirm-btn" id="confirm-btn">
          Place Order · KSh <?= number_format($cartTotal) ?>
        </button>
      </div>
    </form>
    <?php endif; ?>
  </div>
</div>

<script>
// Qty controls on menu cards
function incQty(btn) {
  const wrap = btn.closest('.qty-wrap');
  const num  = wrap.querySelector('.qty-num');
  const hidden = wrap.querySelector('.qty-hidden');
  let v = parseInt(num.textContent) + 1;
  num.textContent = v;
  hidden.value = v;
}
function decQty(btn) {
  const wrap = btn.closest('.qty-wrap');
  const num  = wrap.querySelector('.qty-num');
  const hidden = wrap.querySelector('.qty-hidden');
  let v = Math.max(1, parseInt(num.textContent) - 1);
  num.textContent = v;
  hidden.value = v;
}

// Checkout modal
function openCheckout() {
  document.getElementById('checkout-modal').classList.add('open');
  document.body.style.overflow = 'hidden';
}
function closeCheckout() {
  document.getElementById('checkout-modal').classList.remove('open');
  document.body.style.overflow = '';
}
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeCheckout(); });

// Payment method toggle
function selectPay(method) {
  document.querySelectorAll('.pay-opt').forEach(el => el.classList.remove('selected'));
  document.getElementById('opt-' + method).classList.add('selected');
  document.getElementById('mpesa-field').classList.toggle('show', method === 'mpesa');
  document.getElementById('card-field').classList.toggle('show', method === 'card');
  // Update confirm button text
  const total = '<?= number_format($cartTotal) ?>';
  const labels = { cash: 'Place Order · KSh '+total, mpesa: 'Confirm M-Pesa · KSh '+total, card: 'Place Order · KSh '+total };
  document.getElementById('confirm-btn').textContent = labels[method] || 'Place Order';
}

// Open checkout automatically if redirected with ?checkout=1
<?php if (($_GET['checkout'] ?? '') === '1'): ?>
window.addEventListener('load', () => openCheckout());
<?php endif; ?>
</script>
</body>
</html>
