<?php
session_start();
require_once 'dbconfig.php';
if (empty($_SESSION['user_id']) || !in_array($_SESSION['role'],['admin','cashier','waiter'])) {
    header('Location: login.php'); exit;
}
$pdo = getDB();
$activePage = 'orders';
$msg = ''; $msgType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = $_POST['action'] ?? '';
    if ($a === 'update_status') {
        $pdo->prepare("UPDATE orders SET status=?, updated_at=NOW() WHERE id=?")
            ->execute([$_POST['status'], (int)$_POST['order_id']]);
        $msg = '✅ Order status updated.'; $msgType = 'success';
    }
    if ($a === 'delete') {
        $pdo->prepare("DELETE FROM order_items WHERE order_id=?")->execute([(int)$_POST['order_id']]);
        $pdo->prepare("DELETE FROM payments WHERE order_id=?")->execute([(int)$_POST['order_id']]);
        $pdo->prepare("DELETE FROM orders WHERE id=?")->execute([(int)$_POST['order_id']]);
        $msg = '🗑 Order deleted.'; $msgType = 'warning';
    }
    if ($a === 'add_order') {
        $pdo->prepare("INSERT INTO orders (table_id, waiter_name, guests, status, total_price, notes) VALUES (?,?,?,'pending',?,?)")
            ->execute([(int)$_POST['table_id'], trim($_POST['waiter_name']), (int)$_POST['guests'], (float)$_POST['total_price'], trim($_POST['notes'] ?? '')]);
        $msg = '✅ Order #'.$pdo->lastInsertId().' created.'; $msgType = 'success';
    }
}

$filterDate   = $_GET['date']   ?? date('Y-m-d');
$filterStatus = $_GET['status'] ?? '';
$search       = $_GET['q']      ?? '';

$where  = ['DATE(o.created_at) = :date'];
$params = [':date' => $filterDate];
if ($filterStatus) { $where[] = 'o.status = :s'; $params[':s'] = $filterStatus; }
if ($search)       { $where[] = '(CAST(o.id AS CHAR) LIKE :q OR IFNULL(o.waiter_name,"") LIKE :q)'; $params[':q'] = "%$search%"; }

$stmt = $pdo->prepare("SELECT o.*, tl.table_number,
    (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id=o.id) AS item_count
    FROM orders o LEFT JOIN table_layout tl ON o.table_id=tl.id
    WHERE ".implode(' AND ',$where)." ORDER BY o.created_at DESC");
$stmt->execute($params);
$orders = $stmt->fetchAll();

$counts = [];
foreach (['pending','cooking','ready','served','paid','cancelled'] as $s) {
    $counts[$s] = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE DATE(created_at)='$filterDate' AND status='$s'")->fetchColumn();
}
$tables = $pdo->query("SELECT id, table_number FROM table_layout ORDER BY table_number")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Jiko House — Orders</title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600&family=DM+Sans:wght@400;500;600&family=DM+Mono&display=swap" rel="stylesheet">
<style><?php include '_styles.css'; ?>
.ss{background:var(--bg);border:1.5px solid var(--border);border-radius:7px;padding:5px 8px;font-family:'DM Sans',sans-serif;font-size:12px;color:var(--text);outline:none;cursor:pointer;}
</style>
</head>
<body>
<?php include '_sidebar.php'; ?>
<div class="main">
  <div class="topbar">
    <h1 class="page-title">Orders</h1>
    <button class="btn btn-primary btn-sm" onclick="document.getElementById('new-modal').classList.add('open')">+ New Order</button>
  </div>
  <div class="content">
    <?php if ($msg): ?><div class="flash <?= $msgType ?>"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

    <div class="status-tabs">
      <a href="?date=<?= $filterDate ?>" class="stab <?= !$filterStatus?'active':'' ?>">All <span class="stab-c"><?= array_sum($counts) ?></span></a>
      <?php foreach ($counts as $s => $c): ?>
      <a href="?status=<?= $s ?>&date=<?= $filterDate ?>" class="stab <?= $filterStatus===$s?'active':'' ?>"><?= ucfirst($s) ?> <span class="stab-c"><?= $c ?></span></a>
      <?php endforeach; ?>
    </div>

    <form method="GET" class="toolbar">
      <?php if ($filterStatus): ?><input type="hidden" name="status" value="<?= htmlspecialchars($filterStatus) ?>"><?php endif; ?>
      <div class="search-box"><span>🔍</span><input name="q" type="text" placeholder="Search ID or waiter…" value="<?= htmlspecialchars($search) ?>"></div>
      <input type="date" name="date" value="<?= $filterDate ?>" onchange="this.form.submit()">
      <button type="submit" class="btn btn-secondary btn-sm">Go</button>
    </form>

    <div class="grid-full">
      <div class="card">
        <table>
          <thead><tr><th>ID</th><th>Table</th><th>Waiter</th><th>Items</th><th>Total</th><th>Status</th><th>Time</th><th>Actions</th></tr></thead>
          <tbody>
            <?php if (empty($orders)): ?>
            <tr><td colspan="8" class="empty-cell">No orders found.</td></tr>
            <?php else: foreach ($orders as $o):
              $sc = 's-'.preg_replace('/[^a-z]/','',strtolower($o['status'])); ?>
            <tr>
              <td class="mono muted">#<?= $o['id'] ?></td>
              <td><?= $o['table_number'] ? 'Table '.$o['table_number'] : '—' ?></td>
              <td><?= htmlspecialchars($o['waiter_name'] ?? '—') ?></td>
              <td><?= (int)$o['item_count'] ?></td>
              <td class="mono" style="font-weight:600;color:var(--deep)">KSh <?= number_format($o['total_price']) ?></td>
              <td><span class="badge <?= $sc ?>"><?= htmlspecialchars($o['status']) ?></span></td>
              <td class="mono muted" style="font-size:12px"><?= date('H:i', strtotime($o['created_at'])) ?></td>
              <td>
                <div class="action-cell">
                  <form method="POST" style="display:inline">
                    <input type="hidden" name="action" value="update_status">
                    <input type="hidden" name="order_id" value="<?= $o['id'] ?>">
                    <select name="status" class="ss" onchange="this.form.submit()">
                      <?php foreach (['pending','cooking','ready','served','paid','cancelled'] as $st): ?>
                      <option value="<?= $st ?>" <?= $o['status']===$st?'selected':'' ?>><?= ucfirst($st) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </form>
                  <form method="POST" onsubmit="return confirm('Delete order #<?= $o['id'] ?>?')">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="order_id" value="<?= $o['id'] ?>">
                    <button type="submit" class="icon-btn del">🗑</button>
                  </form>
                </div>
              </td>
            </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<div class="modal-overlay" id="new-modal" onclick="if(event.target===this)this.classList.remove('open')">
  <div class="modal">
    <div class="modal-head"><span class="modal-title">New Order</span><button class="modal-close" onclick="document.getElementById('new-modal').classList.remove('open')">×</button></div>
    <form method="POST"><input type="hidden" name="action" value="add_order">
      <div class="modal-body">
        <div class="form-row">
          <div class="form-group"><label class="form-label">Table *</label>
            <select name="table_id" class="form-select" required>
              <?php foreach ($tables as $t): ?><option value="<?= $t['id'] ?>">Table <?= $t['table_number'] ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="form-group"><label class="form-label">Guests</label><input type="number" name="guests" class="form-input" value="2" min="1"></div>
        </div>
        <div class="form-group"><label class="form-label">Waiter</label><input type="text" name="waiter_name" class="form-input" value="<?= htmlspecialchars($_SESSION['user_name'] ?? '') ?>"></div>
        <div class="form-group"><label class="form-label">Total (KSh)</label><input type="number" name="total_price" class="form-input" placeholder="0" step="0.01" min="0"></div>
        <div class="form-group"><label class="form-label">Notes</label><textarea name="notes" class="form-textarea" placeholder="Special instructions…"></textarea></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="document.getElementById('new-modal').classList.remove('open')">Cancel</button>
        <button type="submit" class="btn btn-primary">Create Order</button>
      </div>
    </form>
  </div>
</div>
<script>document.addEventListener('keydown',e=>{if(e.key==='Escape')document.querySelectorAll('.modal-overlay').forEach(m=>m.classList.remove('open'));});</script>
</body></html>
