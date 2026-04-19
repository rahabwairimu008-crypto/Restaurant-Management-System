<?php
// cart.php — Admin view of all open customer carts
session_start();
require_once 'dbconfig.php';
if (empty($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin','cashier'])) {
    header('Location: login.php'); exit;
}
$pdo = getDB();
$activePage = 'cart';
$msg = ''; $msgType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'clear_user') {
        $pdo->prepare("DELETE FROM cart WHERE user_id = ?")->execute([(int)$_POST['user_id']]);
        $msg = '🗑 Cart cleared.'; $msgType = 'warning';
    }
    if ($action === 'clear_all') {
        $pdo->exec("DELETE FROM cart");
        $msg = '🗑 All carts cleared.'; $msgType = 'warning';
    }
    if ($action === 'convert') {
        $uid = (int)$_POST['user_id'];
        $cartStmt = $pdo->prepare("SELECT * FROM cart WHERE user_id = ?");
        $cartStmt->execute([$uid]);
        $cartItems = $cartStmt->fetchAll();
        if (!empty($cartItems)) {
            $total = array_sum(array_map(fn($i) => $i['price'] * $i['quantity'], $cartItems));
            $pdo->prepare("INSERT INTO orders (user_id, status, total_price) VALUES (?, 'pending', ?)")
                ->execute([$uid, $total]);
            $oid = $pdo->lastInsertId();
            foreach ($cartItems as $ci) {
                $pdo->prepare("INSERT INTO order_items (order_id, menu_item_id, name, price, quantity) VALUES (?,?,?,?,?)")
                    ->execute([$oid, $ci['menu_item_id'], $ci['name'], $ci['price'], $ci['quantity']]);
            }
            $pdo->prepare("DELETE FROM cart WHERE user_id = ?")->execute([$uid]);
            $msg = "✅ Cart converted to Order #$oid."; $msgType = 'success';
        }
    }
}

// Group cart by user
$carts = $pdo->query(
    "SELECT c.user_id, u.name AS user_name, u.email,
            COUNT(*) AS item_types,
            SUM(c.quantity) AS total_items,
            SUM(c.price * c.quantity) AS subtotal,
            MIN(c.created_at) AS first_added
     FROM cart c
     LEFT JOIN users u ON c.user_id = u.id
     GROUP BY c.user_id, u.name, u.email
     ORDER BY first_added DESC"
)->fetchAll();

$cartDetails = [];
foreach ($carts as $cart) {
    $stmt = $pdo->prepare("SELECT c.*, m.emoji FROM cart c LEFT JOIN menu m ON c.menu_item_id = m.id WHERE c.user_id = ? ORDER BY c.created_at");
    $stmt->execute([$cart['user_id']]);
    $cartDetails[$cart['user_id']] = $stmt->fetchAll();
}

$totalCartValue = array_sum(array_column($carts, 'subtotal'));
$totalCartItems = array_sum(array_column($carts, 'total_items'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Jiko House — Cart</title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600&family=DM+Sans:wght@400;500;600&family=DM+Mono&display=swap" rel="stylesheet">
<style>
<?php readfile('_styles.css'); ?>
.cart-card{background:var(--surface);border:1px solid var(--border);border-radius:14px;box-shadow:var(--shadow);overflow:hidden;margin-bottom:16px;animation:fadeUp .35s ease both;}
.cart-head{padding:14px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:12px;background:var(--bg);}
.cu-name{font-weight:600;color:var(--deep);font-size:15px;}
.cu-email{font-size:12px;color:var(--text-muted);margin-top:2px;}
.cu-meta{font-size:12px;color:var(--text-dim);}
.cart-items-list{padding:8px 0;}
.ci-row{display:flex;align-items:center;gap:12px;padding:10px 20px;border-bottom:1px solid rgba(92,61,46,.06);font-size:13px;}
.ci-row:last-child{border-bottom:none;}
.ci-emoji{font-size:22px;flex-shrink:0;}
.ci-name{font-weight:500;color:var(--deep);flex:1;}
.ci-qty{background:var(--bg2);border-radius:5px;padding:2px 8px;font-size:12px;font-weight:700;color:var(--brown);flex-shrink:0;}
.ci-price{font-family:'DM Mono',monospace;font-size:12px;color:var(--text-muted);min-width:75px;text-align:right;}
.cart-foot{padding:12px 20px;border-top:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;}
.cart-total-tag{font-family:'Playfair Display',serif;font-size:18px;color:var(--deep);}
.act-group{display:flex;gap:8px;}
.empty-state{text-align:center;padding:60px 20px;color:var(--text-muted);}
.es-icon{font-size:48px;margin-bottom:14px;}
</style>
</head>
<body>
<?php include '_sidebar.php'; ?>
<div class="main">
  <div class="topbar">
    <h1 class="page-title">Customer Carts</h1>
    <div style="display:flex;gap:10px;align-items:center">
      <?php if (!empty($carts)): ?>
      <form method="POST" onsubmit="return confirm('Clear ALL customer carts?')">
        <input type="hidden" name="action" value="clear_all">
        <button type="submit" class="btn btn-sm" style="background:var(--red-bg);color:var(--red);border:1px solid rgba(168,50,50,.2)">Clear All Carts</button>
      </form>
      <?php endif; ?>
    </div>
  </div>

  <div class="content">
    <?php if ($msg): ?><div class="flash <?= $msgType ?>"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

    <div class="mini-stats">
      <div class="ms"><div class="ms-num"><?= count($carts) ?></div><div class="ms-label">Customers with carts</div></div>
      <div class="ms"><div class="ms-num"><?= $totalCartItems ?></div><div class="ms-label">Total items in carts</div></div>
      <div class="ms"><div class="ms-num">KSh <?= number_format($totalCartValue) ?></div><div class="ms-label">Potential revenue</div></div>
    </div>

    <?php if (empty($carts)): ?>
    <div class="empty-state">
      <div class="es-icon">🛒</div>
      <div style="font-family:'Playfair Display',serif;font-size:20px;color:var(--deep);margin-bottom:8px">No open carts</div>
      <div style="font-size:14px">All customer carts are currently empty.</div>
    </div>
    <?php else: ?>
    <?php foreach ($carts as $cart): ?>
    <div class="cart-card">
      <div class="cart-head">
        <div>
          <div class="cu-name"><?= htmlspecialchars($cart['user_name'] ?? 'Guest') ?></div>
          <div class="cu-email"><?= htmlspecialchars($cart['email'] ?? '') ?></div>
        </div>
        <div class="cu-meta">
          <?= $cart['total_items'] ?> items &nbsp;·&nbsp;
          since <?= date('H:i', strtotime($cart['first_added'])) ?>
        </div>
      </div>

      <div class="cart-items-list">
        <?php foreach ($cartDetails[$cart['user_id']] as $ci): ?>
        <div class="ci-row">
          <span class="ci-emoji"><?= $ci['emoji'] ?? '🍽' ?></span>
          <span class="ci-name"><?= htmlspecialchars($ci['name']) ?></span>
          <span class="ci-qty">×<?= $ci['quantity'] ?></span>
          <span class="ci-price">KSh <?= number_format($ci['price'] * $ci['quantity']) ?></span>
        </div>
        <?php endforeach; ?>
      </div>

      <div class="cart-foot">
        <div class="cart-total-tag">KSh <?= number_format($cart['subtotal']) ?></div>
        <div class="act-group">
          <form method="POST">
            <input type="hidden" name="action" value="convert">
            <input type="hidden" name="user_id" value="<?= $cart['user_id'] ?>">
            <button type="submit" class="btn btn-sm" style="background:var(--sage);color:#fff">→ Convert to Order</button>
          </form>
          <form method="POST" onsubmit="return confirm('Clear this cart?')">
            <input type="hidden" name="action" value="clear_user">
            <input type="hidden" name="user_id" value="<?= $cart['user_id'] ?>">
            <button type="submit" class="btn btn-sm" style="background:var(--red-bg);color:var(--red);border:1px solid rgba(168,50,50,.2)">🗑 Clear</button>
          </form>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
