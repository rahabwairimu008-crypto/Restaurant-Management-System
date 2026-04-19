<?php
// customer_cart.php — Customer Full Cart & Checkout Page
session_start();
require_once 'dbconfig.php';

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

    if ($action === 'remove') {
        $pdo->prepare("DELETE FROM cart WHERE id = ? AND user_id = ?")
            ->execute([(int)$_POST['cart_id'], $uid]);
    }

    if ($action === 'clear_cart') {
        $pdo->prepare("DELETE FROM cart WHERE user_id = ?")->execute([$uid]);
        $msg = 'Cart cleared.'; $msgType = 'warning';
    }

    if ($action === 'checkout') {
        $cartItems = $pdo->prepare("SELECT * FROM cart WHERE user_id = ?");
        $cartItems->execute([$uid]);
        $cartItems = $cartItems->fetchAll();

        if (!empty($cartItems)) {
            $total         = array_sum(array_map(fn($i) => $i['price'] * $i['quantity'], $cartItems));
            $method        = $_POST['payment_method'] ?? 'cash';
            $mpesaCode     = trim($_POST['mpesa_code'] ?? '');
            $notes         = trim($_POST['notes'] ?? '');
            $tableNum      = (int)($_POST['table_number'] ?? 0);

            // Resolve table_id
            $tableId = null;
            if ($tableNum > 0) {
                $tRow = $pdo->prepare("SELECT id FROM table_layout WHERE table_number = ?");
                $tRow->execute([$tableNum]);
                $tRow = $tRow->fetch();
                $tableId = $tRow ? $tRow['id'] : null;
            }

            // Create order
            $pdo->prepare("INSERT INTO orders (user_id, table_id, status, total_price, notes) VALUES (?,?,'pending',?,?)")
                ->execute([$uid, $tableId, $total, $notes]);
            $orderId = $pdo->lastInsertId();

            // Insert order items
            foreach ($cartItems as $ci) {
                $pdo->prepare("INSERT INTO order_items (order_id, menu_item_id, name, price, quantity) VALUES (?,?,?,?,?)")
                    ->execute([$orderId, $ci['menu_item_id'], $ci['name'], $ci['price'], $ci['quantity']]);
            }

            // Determine payment status
            $payStatus = 'pending';
            $orderStatus = 'pending';
            if ($method === 'mpesa' && $mpesaCode) {
                $payStatus   = 'completed';
                $orderStatus = 'paid';
            }

            // Record payment
            $pdo->prepare("INSERT INTO payments (order_id, method, amount, reference, status, processed_by) VALUES (?,?,?,?,?,'Customer')")
                ->execute([$orderId, $method, $total, $mpesaCode ?: null, $payStatus]);

            // Update order status if paid
            if ($orderStatus === 'paid') {
                $pdo->prepare("UPDATE orders SET status = 'paid' WHERE id = ?")
                    ->execute([$orderId]);
            }

            // Clear cart
            $pdo->prepare("DELETE FROM cart WHERE user_id = ?")->execute([$uid]);

            // Success message
            if ($method === 'mpesa' && $mpesaCode) {
                $msg = "✅ Order #$orderId placed and M-Pesa payment confirmed! We're preparing your food.";
            } elseif ($method === 'mpesa') {
                $msg = "✅ Order #$orderId placed! Please complete M-Pesa payment to Till/Paybill and show confirmation to waiter.";
            } elseif ($method === 'card') {
                $msg = "✅ Order #$orderId placed! Card payment will be processed at the counter.";
            } else {
                $msg = "✅ Order #$orderId placed! Pay cash when your order is served.";
            }
            $msgType = 'success';
        } else {
            $msg = 'Your cart is empty — add items first.'; $msgType = 'error';
        }
    }
}

// ── FETCH DATA ────────────────────────────────────────────────────────────────
$cartStmt = $pdo->prepare("SELECT c.*, m.emoji FROM cart c LEFT JOIN menu m ON c.menu_item_id = m.id WHERE c.user_id = ? ORDER BY c.created_at");
$cartStmt->execute([$uid]);
$cartItems = $cartStmt->fetchAll();
$cartTotal = array_sum(array_map(fn($i) => $i['price'] * $i['quantity'], $cartItems));
$cartCount = array_sum(array_column($cartItems, 'quantity'));
$tables    = $pdo->query("SELECT table_number, capacity FROM table_layout ORDER BY table_number")->fetchAll();

// Recent orders
$myOrders = $pdo->prepare("SELECT o.*, p.method AS pay_method, p.status AS pay_status FROM orders o LEFT JOIN payments p ON p.order_id = o.id WHERE o.user_id = ? ORDER BY o.created_at DESC LIMIT 10");
$myOrders->execute([$uid]);
$myOrders = $myOrders->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Jiko House — Cart & Checkout</title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;600&family=DM+Sans:wght@300;400;500;600&family=DM+Mono&display=swap" rel="stylesheet">
<style>
  :root {
    --cream:#F9F3E8; --parchment:#EDE4D0; --warm-brown:#5C3D2E;
    --deep-brown:#3A2218; --rust:#C0622B; --gold:#C49A3C;
    --sage:#6B7C5E; --text:#2C1A0E; --text-muted:#7A5C45;
    --text-dim:#B09A85; --white:#FFFDF8; --red:#A83232;
    --shadow:0 4px 24px rgba(60,34,24,.10);
    --shadow-sm:0 2px 10px rgba(60,34,24,.08);
    --border:rgba(92,61,46,.12);
  }
  *{margin:0;padding:0;box-sizing:border-box;}
  body{background:var(--cream);font-family:'DM Sans',sans-serif;color:var(--text);min-height:100vh;}

  /* HEADER */
  header{background:var(--deep-brown);position:sticky;top:0;z-index:100;box-shadow:0 2px 14px rgba(0,0,0,.25);}
  .hdr{max-width:1000px;margin:0 auto;padding:14px 20px;display:flex;align-items:center;justify-content:space-between;}
  .logo-name{font-family:'Playfair Display',serif;font-size:22px;color:var(--gold);}
  .logo-sub{font-size:10px;letter-spacing:.2em;text-transform:uppercase;color:rgba(249,243,232,.35);margin-top:3px;}
  .back-btn{display:flex;align-items:center;gap:8px;color:rgba(249,243,232,.6);font-size:14px;text-decoration:none;transition:color .2s;}
  .back-btn:hover{color:var(--gold);}
  .hdr-user{font-size:13px;color:rgba(249,243,232,.5);}

  /* MAIN LAYOUT */
  .container{max-width:1000px;margin:0 auto;padding:28px 20px;display:grid;grid-template-columns:1fr 360px;gap:28px;align-items:start;}
  @media(max-width:760px){.container{grid-template-columns:1fr;}}

  /* FLASH */
  .flash-wrap{max-width:1000px;margin:16px auto 0;padding:0 20px;}
  .flash{padding:14px 18px;border-radius:12px;font-size:14px;font-weight:500;border:1px solid;}
  .flash.success{background:rgba(107,124,94,.12);border-color:rgba(107,124,94,.3);color:var(--sage);}
  .flash.warning{background:rgba(196,154,60,.1);border-color:rgba(196,154,60,.25);color:#8A6010;}
  .flash.error{background:rgba(168,50,50,.08);border-color:rgba(168,50,50,.2);color:var(--red);}

  /* CARD */
  .card{background:var(--white);border-radius:16px;box-shadow:var(--shadow);border:1px solid var(--border);overflow:hidden;margin-bottom:20px;}
  .card-head{padding:16px 20px 14px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;}
  .card-title{font-family:'Playfair Display',serif;font-size:18px;color:var(--deep-brown);}
  .card-sub{font-size:12px;color:var(--text-muted);}

  /* CART ITEMS TABLE */
  .cart-table{width:100%;}
  .ct-row{display:grid;grid-template-columns:50px 1fr auto auto auto;align-items:center;gap:12px;padding:14px 20px;border-bottom:1px solid rgba(92,61,46,.07);}
  .ct-row:last-child{border-bottom:none;}
  .ct-emoji{font-size:28px;text-align:center;}
  .ct-name{font-weight:600;color:var(--deep-brown);font-size:14px;}
  .ct-unit{font-size:12px;color:var(--text-muted);margin-top:2px;}
  .qty-ctrl{display:flex;align-items:center;gap:6px;}
  .qbtn{width:28px;height:28px;border-radius:7px;border:1.5px solid var(--border);background:var(--parchment);color:var(--warm-brown);font-size:16px;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all .15s;}
  .qbtn:hover{background:var(--warm-brown);color:#fff;border-color:var(--warm-brown);}
  .qnum{font-size:14px;font-weight:700;color:var(--deep-brown);min-width:20px;text-align:center;}
  .ct-price{font-family:'Playfair Display',serif;font-size:16px;color:var(--warm-brown);font-weight:600;white-space:nowrap;text-align:right;}
  .del-btn{background:none;border:none;color:rgba(168,50,50,.5);cursor:pointer;font-size:20px;padding:4px;}
  .del-btn:hover{color:var(--red);}
  .cart-empty{text-align:center;padding:50px 20px;}
  .ce-icon{font-size:48px;margin-bottom:14px;}
  .ce-title{font-family:'Playfair Display',serif;font-size:20px;color:var(--deep-brown);margin-bottom:8px;}
  .cart-footer{padding:16px 20px;border-top:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;}
  .cart-total{font-family:'Playfair Display',serif;font-size:22px;color:var(--deep-brown);}
  .cart-total span{font-size:13px;color:var(--text-muted);font-family:'DM Sans',sans-serif;font-weight:400;margin-right:6px;}
  .clear-btn{background:none;border:1.5px solid rgba(168,50,50,.25);color:var(--red);border-radius:8px;padding:7px 14px;font-family:'DM Sans',sans-serif;font-size:13px;font-weight:600;cursor:pointer;transition:all .18s;}
  .clear-btn:hover{background:rgba(168,50,50,.08);}

  /* CHECKOUT FORM */
  .form-group{margin-bottom:18px;}
  .form-label{display:block;font-size:11px;text-transform:uppercase;letter-spacing:.12em;color:var(--text-muted);font-weight:600;margin-bottom:7px;}
  .form-input,.form-select,.form-textarea{width:100%;background:var(--cream);border:1.5px solid rgba(92,61,46,.18);border-radius:10px;padding:11px 14px;font-family:'DM Sans',sans-serif;font-size:14px;color:var(--text);outline:none;transition:border-color .2s;}
  .form-input:focus,.form-select:focus,.form-textarea:focus{border-color:rgba(192,98,43,.5);}
  .form-textarea{resize:vertical;min-height:65px;}

  /* PAYMENT OPTIONS */
  .pay-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;margin-bottom:6px;}
  .pay-opt{border:2px solid var(--border);border-radius:12px;padding:14px 8px;cursor:pointer;transition:all .2s;text-align:center;position:relative;}
  .pay-opt:hover{border-color:rgba(192,98,43,.4);}
  .pay-opt.selected{border-color:var(--rust);background:rgba(192,98,43,.06);}
  .pay-opt input{position:absolute;opacity:0;width:0;height:0;}
  .pay-icon{font-size:26px;margin-bottom:5px;}
  .pay-name{font-size:12px;font-weight:700;color:var(--deep-brown);}
  .pay-desc{font-size:10px;color:var(--text-muted);margin-top:2px;}

  /* MPESA BOX */
  .mpesa-box{background:rgba(107,124,94,.08);border:1px solid rgba(107,124,94,.25);border-radius:10px;padding:14px 16px;margin-bottom:14px;display:none;}
  .mpesa-box.show{display:block;}
  .mpesa-title{font-size:13px;font-weight:600;color:var(--sage);margin-bottom:6px;}
  .mpesa-amount{font-family:'Playfair Display',serif;font-size:20px;color:var(--deep-brown);margin-bottom:8px;}
  .mpesa-steps{font-size:12px;color:var(--text-muted);line-height:1.8;}
  .mpesa-steps li{margin-left:14px;}
  .card-notice{background:rgba(74,144,217,.06);border:1px solid rgba(74,144,217,.2);border-radius:10px;padding:12px 16px;margin-bottom:14px;font-size:13px;color:#3A6FA8;display:none;}
  .card-notice.show{display:block;}

  /* PLACE ORDER BTN */
  .place-btn{width:100%;background:var(--rust);color:#fff;border:none;border-radius:12px;padding:15px;font-family:'DM Sans',sans-serif;font-size:16px;font-weight:600;cursor:pointer;transition:background .2s;margin-top:4px;}
  .place-btn:hover{background:var(--warm-brown);}
  .place-btn:disabled{background:var(--text-dim);cursor:not-allowed;}

  /* ORDER SUMMARY */
  .summary-rows{padding:0 20px 4px;}
  .sumrow{display:flex;justify-content:space-between;font-size:13px;padding:8px 0;border-bottom:1px solid rgba(92,61,46,.08);}
  .sumrow:last-child{border-bottom:none;font-weight:700;color:var(--deep-brown);font-size:16px;padding-top:12px;}

  /* MY ORDERS */
  .o-row{display:flex;align-items:center;justify-content:space-between;padding:13px 20px;border-bottom:1px solid rgba(92,61,46,.07);gap:12px;}
  .o-row:last-child{border-bottom:none;}
  .o-info{flex:1;}
  .o-id{font-family:'DM Mono',monospace;font-size:12px;color:var(--text-muted);}
  .o-date{font-size:11px;color:var(--text-dim);margin-top:2px;}
  .o-total{font-family:'Playfair Display',serif;font-size:16px;color:var(--deep-brown);font-weight:600;}
  .o-status{display:inline-block;padding:3px 10px;border-radius:5px;font-size:11px;font-weight:700;text-transform:uppercase;white-space:nowrap;}
  .s-pending{background:rgba(74,144,217,.12);color:#3A6FA8;}
  .s-cooking{background:rgba(224,160,32,.12);color:#A07020;}
  .s-ready{background:rgba(90,138,94,.12);color:#3A7A40;}
  .s-served,.s-paid{background:rgba(90,138,94,.08);color:var(--sage);}
  .s-cancelled{background:rgba(168,50,50,.08);color:var(--red);}
  .pay-badge{font-size:10px;font-weight:600;padding:2px 6px;border-radius:4px;margin-left:6px;}
  .pb-completed{background:rgba(107,124,94,.12);color:var(--sage);}
  .pb-pending{background:rgba(196,154,60,.1);color:#8A6010;}

  /* MENU LINK */
  .menu-link{display:block;background:var(--deep-brown);color:var(--gold);text-align:center;padding:12px;border-radius:10px;text-decoration:none;font-weight:600;font-size:14px;transition:background .2s;}
  .menu-link:hover{background:var(--warm-brown);}
</style>
</head>
<body>

<header>
  <div class="hdr">
    <div>
      <div class="logo-name">Jiko House</div>
      <div class="logo-sub">Cart & Checkout</div>
    </div>
    <a href="customer_menu.php" class="back-btn">← Back to Menu</a>
    <span class="hdr-user">👤 <?= htmlspecialchars($_SESSION['user_name'] ?? '') ?></span>
  </div>
</header>

<?php if ($msg): ?>
<div class="flash-wrap">
  <div class="flash <?= $msgType ?>"><?= htmlspecialchars($msg) ?></div>
</div>
<?php endif; ?>

<div class="container">

  <!-- LEFT COLUMN -->
  <div>

    <!-- CART ITEMS -->
    <div class="card">
      <div class="card-head">
        <span class="card-title">Your Cart</span>
        <span class="card-sub"><?= $cartCount ?> item<?= $cartCount != 1 ? 's' : '' ?></span>
      </div>

      <?php if (empty($cartItems)): ?>
      <div class="cart-empty">
        <div class="ce-icon">🛒</div>
        <div class="ce-title">Your cart is empty</div>
        <p style="color:var(--text-muted);font-size:14px;margin-bottom:18px">Browse the menu and add dishes.</p>
        <a href="customer_menu.php" class="menu-link" style="display:inline-block;padding:10px 28px">Browse Menu →</a>
      </div>
      <?php else: ?>
      <div class="cart-table">
        <?php foreach ($cartItems as $ci): ?>
        <div class="ct-row">
          <div class="ct-emoji"><?= $ci['emoji'] ?? '🍽' ?></div>
          <div>
            <div class="ct-name"><?= htmlspecialchars($ci['name']) ?></div>
            <div class="ct-unit">KSh <?= number_format($ci['price']) ?> each</div>
          </div>
          <div class="qty-ctrl">
            <form method="POST" style="display:inline">
              <input type="hidden" name="action" value="update_qty">
              <input type="hidden" name="cart_id" value="<?= $ci['id'] ?>">
              <input type="hidden" name="new_qty" value="<?= $ci['quantity'] - 1 ?>">
              <button type="submit" class="qbtn">−</button>
            </form>
            <span class="qnum"><?= $ci['quantity'] ?></span>
            <form method="POST" style="display:inline">
              <input type="hidden" name="action" value="update_qty">
              <input type="hidden" name="cart_id" value="<?= $ci['id'] ?>">
              <input type="hidden" name="new_qty" value="<?= $ci['quantity'] + 1 ?>">
              <button type="submit" class="qbtn">+</button>
            </form>
          </div>
          <div class="ct-price">KSh <?= number_format($ci['price'] * $ci['quantity']) ?></div>
          <form method="POST" style="display:inline">
            <input type="hidden" name="action" value="remove">
            <input type="hidden" name="cart_id" value="<?= $ci['id'] ?>">
            <button type="submit" class="del-btn" title="Remove">×</button>
          </form>
        </div>
        <?php endforeach; ?>
      </div>
      <div class="cart-footer">
        <div class="cart-total"><span>Total</span>KSh <?= number_format($cartTotal) ?></div>
        <form method="POST" onsubmit="return confirm('Clear entire cart?')">
          <input type="hidden" name="action" value="clear_cart">
          <button type="submit" class="clear-btn">🗑 Clear Cart</button>
        </form>
      </div>
      <?php endif; ?>
    </div>

    <!-- MY ORDERS -->
    <?php if (!empty($myOrders)): ?>
    <div class="card">
      <div class="card-head"><span class="card-title">My Order History</span></div>
      <?php foreach ($myOrders as $o):
        $sc = 's-' . preg_replace('/[^a-z]/', '', strtolower($o['status']));
        $pc = 'pb-' . ($o['pay_status'] ?? 'pending');
      ?>
      <div class="o-row">
        <div class="o-info">
          <div class="o-id">#<?= $o['id'] ?>
            <?php if ($o['pay_method']): ?>
              <span class="pay-badge <?= $pc ?>"><?= strtoupper($o['pay_method'] ?? '') ?></span>
            <?php endif; ?>
          </div>
          <div class="o-date"><?= date('d M Y, H:i', strtotime($o['created_at'])) ?></div>
        </div>
        <div class="o-total">KSh <?= number_format($o['total_price']) ?></div>
        <span class="o-status <?= $sc ?>"><?= htmlspecialchars($o['status']) ?></span>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

  </div>

  <!-- RIGHT COLUMN — CHECKOUT -->
  <?php if (!empty($cartItems)): ?>
  <div>
    <div class="card">
      <div class="card-head"><span class="card-title">Checkout</span></div>

      <!-- SUMMARY -->
      <div class="summary-rows">
        <?php foreach ($cartItems as $ci): ?>
        <div class="sumrow">
          <span><?= htmlspecialchars($ci['name']) ?> ×<?= $ci['quantity'] ?></span>
          <span>KSh <?= number_format($ci['price'] * $ci['quantity']) ?></span>
        </div>
        <?php endforeach; ?>
        <div class="sumrow">
          <span>Total</span>
          <span>KSh <?= number_format($cartTotal) ?></span>
        </div>
      </div>

      <form method="POST" style="padding:16px 20px 20px">
        <input type="hidden" name="action" value="checkout">

        <!-- TABLE -->
        <div class="form-group">
          <label class="form-label">Your Table (optional)</label>
          <select name="table_number" class="form-select">
            <option value="">— Takeaway / Not seated —</option>
            <?php foreach ($tables as $t): ?>
            <option value="<?= $t['table_number'] ?>">Table <?= $t['table_number'] ?> (<?= $t['capacity'] ?> seats)</option>
            <?php endforeach; ?>
          </select>
        </div>

        <!-- PAYMENT METHOD -->
        <div class="form-group">
          <label class="form-label">How would you like to pay?</label>
          <div class="pay-grid">
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
              <div class="pay-desc">Enter code</div>
            </label>
            <label class="pay-opt" id="opt-card" onclick="selectPay('card')">
              <input type="radio" name="payment_method" value="card">
              <div class="pay-icon">💳</div>
              <div class="pay-name">Card</div>
              <div class="pay-desc">At counter</div>
            </label>
          </div>
        </div>

        <!-- MPESA INSTRUCTIONS -->
        <div class="mpesa-box" id="mpesa-box">
          <div class="mpesa-title">📲 M-Pesa Instructions</div>
          <div class="mpesa-amount">KSh <?= number_format($cartTotal) ?></div>
          <ol class="mpesa-steps">
            <li>Go to M-Pesa on your phone</li>
            <li>Select <strong>Lipa na M-Pesa → Paybill</strong></li>
            <li>Enter our Business No. (from cashier)</li>
            <li>Enter amount: <strong>KSh <?= number_format($cartTotal) ?></strong></li>
            <li>Enter your PIN and confirm</li>
            <li>Paste the confirmation code below</li>
          </ol>
          <div class="form-group" style="margin-top:12px;margin-bottom:0">
            <label class="form-label">M-Pesa Confirmation Code</label>
            <input type="text" name="mpesa_code" id="mpesa-code" class="form-input"
                   placeholder="e.g. QJ7X2P4KLM"
                   style="font-family:'DM Mono',monospace;letter-spacing:.1em;font-size:16px;text-transform:uppercase"
                   maxlength="12" oninput="this.value=this.value.toUpperCase()">
          </div>
        </div>

        <!-- CARD NOTICE -->
        <div class="card-notice" id="card-notice">
          💳 Your order will be placed now. Please proceed to the counter with your order number to pay by card.
        </div>

        <!-- NOTES -->
        <div class="form-group">
          <label class="form-label">Special Instructions</label>
          <textarea name="notes" class="form-textarea" placeholder="Allergies, dietary needs, preferences, seating…"></textarea>
        </div>

        <button type="submit" class="place-btn" id="place-btn">
          Place Order · KSh <?= number_format($cartTotal) ?>
        </button>
      </form>
    </div>

    <a href="customer_menu.php" class="menu-link">← Continue Shopping</a>
  </div>
  <?php else: ?>
  <div>
    <div class="card" style="padding:24px;text-align:center">
      <div style="font-size:36px;margin-bottom:12px">🍽</div>
      <div style="font-family:'Playfair Display',serif;font-size:17px;color:var(--deep-brown);margin-bottom:8px">Ready to order?</div>
      <p style="font-size:13px;color:var(--text-muted);margin-bottom:18px">Add items from the menu to get started.</p>
      <a href="customer_menu.php" class="menu-link">Browse Menu →</a>
    </div>
  </div>
  <?php endif; ?>

</div>

<script>
function selectPay(method) {
  ['cash','mpesa','card'].forEach(m => {
    document.getElementById('opt-'+m)?.classList.remove('selected');
  });
  document.getElementById('opt-'+method)?.classList.add('selected');
  document.getElementById('mpesa-box').classList.toggle('show', method === 'mpesa');
  document.getElementById('card-notice').classList.toggle('show', method === 'card');

  const total = '<?= number_format($cartTotal) ?>';
  const labels = {
    cash:  'Place Order · Pay Cash · KSh '+total,
    mpesa: 'Confirm M-Pesa Order · KSh '+total,
    card:  'Place Order · Pay by Card · KSh '+total,
  };
  const btn = document.getElementById('place-btn');
  if (btn) btn.textContent = labels[method] || 'Place Order · KSh '+total;
}
</script>
</body>
</html>
