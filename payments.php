<?php
session_start();
require_once 'dbconfig.php';
if (empty($_SESSION['user_id']) || !in_array($_SESSION['role'],['admin','cashier'])) {
    header('Location: login.php'); exit;
}
$pdo = getDB();
$activePage = 'payments';
$msg = ''; $msgType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = $_POST['action'] ?? '';
    if ($a === 'add') {
        $pdo->prepare("INSERT INTO payments (order_id,method,amount,reference,status,processed_by) VALUES (?,?,?,?,'completed',?)")
            ->execute([(int)$_POST['order_id'],$_POST['method'],(float)$_POST['amount'],trim($_POST['reference']??''),trim($_POST['processed_by']??$_SESSION['user_name'])]);
        $pdo->prepare("UPDATE orders SET status='paid', updated_at=NOW() WHERE id=?")->execute([(int)$_POST['order_id']]);
        $msg = '✅ Payment of KSh '.number_format((float)$_POST['amount']).' recorded.'; $msgType = 'success';
    }
    if ($a === 'refund') {
        $pdo->prepare("UPDATE payments SET status='refunded' WHERE id=?")->execute([(int)$_POST['payment_id']]);
        $msg = 'Payment refunded.'; $msgType = 'warning';
    }
}

$filterDate = $_GET['date'] ?? date('Y-m-d');
$payments = $pdo->prepare(
    "SELECT p.*, tl.table_number, o.total_price AS order_total
     FROM payments p
     LEFT JOIN orders o ON p.order_id=o.id
     LEFT JOIN table_layout tl ON o.table_id=tl.id
     WHERE DATE(p.created_at)=?
     ORDER BY p.created_at DESC");
$payments->execute([$filterDate]);
$payments = $payments->fetchAll();

$todayRev   = array_sum(array_column(array_filter($payments, fn($p) => $p['status']==='completed'), 'amount'));
$refunds    = array_sum(array_column(array_filter($payments, fn($p) => $p['status']==='refunded'), 'amount'));
$unpaidOrders = $pdo->query("SELECT id, total_price, table_id FROM orders WHERE status NOT IN ('paid','cancelled') ORDER BY created_at DESC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Jiko House — Payments</title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600&family=DM+Sans:wght@400;500;600&family=DM+Mono&display=swap" rel="stylesheet">
<style><?php include '_styles.css'; ?>
.mc{display:inline-block;background:var(--bg2);border-radius:5px;padding:2px 8px;font-size:11px;color:var(--brown);font-weight:600;text-transform:uppercase;}
.pc{display:inline-block;padding:3px 10px;border-radius:5px;font-size:11px;font-weight:700;text-transform:uppercase;}
.p-completed{background:rgba(90,138,94,.12);color:var(--sage);}
.p-pending{background:rgba(74,144,217,.12);color:#3A6FA8;}
.p-refunded{background:rgba(168,50,50,.08);color:var(--red);}
.p-failed{background:rgba(168,50,50,.08);color:var(--red);}
</style>
</head>
<body>
<?php include '_sidebar.php'; ?>
<div class="main">
  <div class="topbar">
    <h1 class="page-title">Payments</h1>
    <div class="topbar-right">
      <form method="GET"><input type="date" name="date" value="<?= $filterDate ?>" onchange="this.form.submit()"></form>
    </div>
  </div>
  <div class="content">
    <?php if ($msg): ?><div class="flash <?= $msgType ?>"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

    <div class="mini-stats">
      <div class="ms"><div class="ms-num">KSh <?= number_format($todayRev) ?></div><div class="ms-label">Collected <?= $filterDate===date('Y-m-d')?'today':date('d M',strtotime($filterDate)) ?></div></div>
      <div class="ms"><div class="ms-num"><?= count($payments) ?></div><div class="ms-label">Transactions</div></div>
      <div class="ms"><div class="ms-num"><?= count($unpaidOrders) ?></div><div class="ms-label">Unpaid orders</div></div>
      <div class="ms"><div class="ms-num">KSh <?= number_format(array_sum(array_column($unpaidOrders,'total_price'))) ?></div><div class="ms-label">Outstanding</div></div>
    </div>

    <div class="grid-2r">
      <!-- TRANSACTIONS TABLE -->
      <div class="card">
        <div class="card-head"><span class="card-title">Transactions — <?= date('d M Y',strtotime($filterDate)) ?></span></div>
        <table>
          <thead><tr><th>#</th><th>Order</th><th>Table</th><th>Method</th><th>Amount</th><th>Status</th><th>By</th><th>Time</th><th></th></tr></thead>
          <tbody>
            <?php if (empty($payments)): ?>
            <tr><td colspan="9" class="empty-cell">No transactions for this date.</td></tr>
            <?php else: foreach ($payments as $p): ?>
            <tr>
              <td class="mono muted">#<?= $p['id'] ?></td>
              <td class="mono muted">#<?= $p['order_id'] ?></td>
              <td><?= $p['table_number'] ? 'Table '.$p['table_number'] : '—' ?></td>
              <td><span class="mc"><?= htmlspecialchars($p['method']) ?></span></td>
              <td class="mono" style="font-weight:600;color:var(--deep)">KSh <?= number_format($p['amount']) ?></td>
              <td><span class="pc p-<?= $p['status'] ?>"><?= $p['status'] ?></span></td>
              <td class="muted" style="font-size:12px"><?= htmlspecialchars($p['processed_by'] ?? '—') ?></td>
              <td class="mono muted" style="font-size:11px"><?= date('H:i', strtotime($p['created_at'])) ?></td>
              <td>
                <?php if ($p['status']==='completed'): ?>
                <form method="POST" onsubmit="return confirm('Mark as refunded?')">
                  <input type="hidden" name="action" value="refund">
                  <input type="hidden" name="payment_id" value="<?= $p['id'] ?>">
                  <button type="submit" class="btn btn-sm" style="background:var(--red-bg);color:var(--red);border:1px solid rgba(168,50,50,.2)">Refund</button>
                </form>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>

      <!-- RECORD PAYMENT -->
      <div class="card" style="align-self:start">
        <div class="card-head"><span class="card-title">Record Payment</span></div>
        <form method="POST">
          <input type="hidden" name="action" value="add">
          <div style="padding:20px">
            <div class="form-group">
              <label class="form-label">Unpaid Order *</label>
              <select name="order_id" class="form-select" required>
                <option value="">— Select order —</option>
                <?php foreach ($unpaidOrders as $uo): ?>
                <option value="<?= $uo['id'] ?>">Order #<?= $uo['id'] ?> — KSh <?= number_format($uo['total_price']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-row">
              <div class="form-group">
                <label class="form-label">Method</label>
                <select name="method" class="form-select">
                  <option value="cash">Cash</option>
                  <option value="mpesa">M-Pesa</option>
                  <option value="card">Card</option>
                  <option value="other">Other</option>
                </select>
              </div>
              <div class="form-group">
                <label class="form-label">Amount (KSh) *</label>
                <input type="number" name="amount" class="form-input" placeholder="0" step="0.01" min="0" required>
              </div>
            </div>
            <div class="form-group">
              <label class="form-label">Reference / M-Pesa Code</label>
              <input type="text" name="reference" class="form-input" placeholder="e.g. QJ7X2P4KLM">
            </div>
            <div class="form-group">
              <label class="form-label">Processed By</label>
              <input type="text" name="processed_by" class="form-input" value="<?= htmlspecialchars($_SESSION['user_name'] ?? '') ?>">
            </div>
            <button type="submit" class="btn btn-primary" style="width:100%">Record Payment</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>
</body></html>
