<?php
// inventory.php — Inventory Management
session_start();
require_once 'dbconfig.php';

if (empty($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin','cashier'])) {
    header('Location: login.php'); exit;
}

$pdo = getDB();
$activePage = 'inventory';
$msg = ''; $msgType = '';

// ── ACTIONS ───────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Add new inventory item
    if ($action === 'add') {
        $name     = trim($_POST['name']     ?? '');
        $unit     = trim($_POST['unit']     ?? 'kg');
        $qty      = (float)($_POST['qty']   ?? 0);
        $min      = (float)($_POST['min']   ?? 1);
        $cost     = (float)($_POST['cost']  ?? 0);
        $supplier = trim($_POST['supplier'] ?? '');

        if (!$name) {
            $msg = 'Item name is required.'; $msgType = 'error';
        } else {
            $pdo->prepare(
                "INSERT INTO inventory (name, unit, quantity, min_threshold, cost_per_unit, supplier)
                 VALUES (?, ?, ?, ?, ?, ?)"
            )->execute([$name, $unit, $qty, $min, $cost, $supplier]);
            $msg = "✅ " . htmlspecialchars($name) . " added to inventory.";
            $msgType = 'success';
        }
    }

    // Restock — add quantity to existing item
    if ($action === 'restock') {
        $id  = (int)$_POST['item_id'];
        $qty = (float)$_POST['qty'];
        if ($qty <= 0) {
            $msg = 'Enter a quantity greater than 0.'; $msgType = 'error';
        } else {
            $pdo->prepare("UPDATE inventory SET quantity = quantity + ?, updated_at = NOW() WHERE id = ?")
                ->execute([$qty, $id]);
            $msg = '✅ Stock updated.'; $msgType = 'success';
        }
    }

    // Edit item details
    if ($action === 'edit') {
        $id       = (int)$_POST['item_id'];
        $name     = trim($_POST['name']     ?? '');
        $unit     = trim($_POST['unit']     ?? 'kg');
        $qty      = (float)($_POST['qty']   ?? 0);
        $min      = (float)($_POST['min']   ?? 1);
        $cost     = (float)($_POST['cost']  ?? 0);
        $supplier = trim($_POST['supplier'] ?? '');

        $pdo->prepare(
            "UPDATE inventory
             SET name=?, unit=?, quantity=?, min_threshold=?, cost_per_unit=?, supplier=?, updated_at=NOW()
             WHERE id=?"
        )->execute([$name, $unit, $qty, $min, $cost, $supplier, $id]);
        $msg = "✅ " . htmlspecialchars($name) . " updated."; $msgType = 'success';
    }

    // Delete item
    if ($action === 'delete') {
        $id = (int)$_POST['item_id'];
        $row = $pdo->prepare("SELECT name FROM inventory WHERE id=?");
        $row->execute([$id]);
        $row = $row->fetch();
        $pdo->prepare("DELETE FROM inventory WHERE id=?")->execute([$id]);
        $msg = '🗑 ' . htmlspecialchars($row['name'] ?? 'Item') . ' removed.';
        $msgType = 'warning';
    }
}

// ── FETCH DATA ────────────────────────────────────────────────────────────────
$search     = trim($_GET['q'] ?? '');
$filterUnit = trim($_GET['unit'] ?? '');

$where  = ['1=1'];
$params = [];
if ($search) {
    $where[]  = '(name LIKE ? OR supplier LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($filterUnit) {
    $where[]  = 'unit = ?';
    $params[] = $filterUnit;
}

$stmt = $pdo->prepare(
    "SELECT * FROM inventory WHERE " . implode(' AND ', $where) . " ORDER BY name ASC"
);
$stmt->execute($params);
$inventory = $stmt->fetchAll();

// Recalculate status for display
foreach ($inventory as &$item) {
    if ($item['quantity'] <= 0) {
        $item['status'] = 'out';
    } elseif ($item['quantity'] < $item['min_threshold'] * 0.5) {
        $item['status'] = 'critical';
    } elseif ($item['quantity'] < $item['min_threshold']) {
        $item['status'] = 'low';
    } else {
        $item['status'] = 'ok';
    }
}
unset($item);

// Summary stats
$totalItems    = count($inventory);
$lowItems      = count(array_filter($inventory, fn($i) => in_array($i['status'], ['low','critical','out'])));
$totalValue    = array_sum(array_map(fn($i) => $i['quantity'] * $i['cost_per_unit'], $inventory));
$units         = $pdo->query("SELECT DISTINCT unit FROM inventory ORDER BY unit")->fetchAll(PDO::FETCH_COLUMN);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Jiko House — Inventory</title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600&family=DM+Sans:wght@300;400;500;600&family=DM+Mono&display=swap" rel="stylesheet">
<link rel="stylesheet" href="_styles.css">
<style>
  /* Inventory-specific styles */
  .status-pill       { display:inline-block; padding:3px 10px; border-radius:5px; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; }
  .sp-ok             { background:rgba(90,138,94,.12);  color:var(--sage); }
  .sp-low            { background:rgba(192,122,32,.12); color:var(--amber); }
  .sp-critical       { background:rgba(168,50,50,.12);  color:var(--red); }
  .sp-out            { background:rgba(168,50,50,.08);  color:var(--red); opacity:.8; }
  .restock-form      { display:flex; gap:6px; align-items:center; }
  .restock-input     { width:80px; background:var(--bg); border:1.5px solid var(--border); border-radius:7px; padding:5px 9px; font-family:'DM Mono',monospace; font-size:13px; color:var(--text); outline:none; }
  .restock-input:focus { border-color:rgba(192,98,43,.5); }
  .restock-btn       { background:var(--sage); color:#fff; border:none; border-radius:7px; padding:5px 11px; font-family:'DM Sans',sans-serif; font-size:12px; font-weight:600; cursor:pointer; transition:background .15s; white-space:nowrap; }
  .restock-btn:hover { background:#3A7A40; }
  .cost-cell         { font-family:'DM Mono',monospace; font-size:12px; color:var(--text-muted); }
</style>
</head>
<body>

<?php include '_sidebar.php'; ?>

<div class="main">
  <div class="topbar">
    <h1 class="page-title">Inventory</h1>
    <button class="btn btn-primary btn-sm" onclick="document.getElementById('add-modal').classList.add('open')">+ Add Item</button>
  </div>

  <div class="content">
    <?php if ($msg): ?>
    <div class="flash <?= $msgType ?>"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>

    <!-- LOW STOCK ALERT -->
    <?php if ($lowItems > 0): ?>
    <div class="alert-banner">
      <span class="alert-icon">⚠️</span>
      <div class="alert-body">
        <div class="alert-title"><?= $lowItems ?> item<?= $lowItems > 1 ? 's' : '' ?> need restocking</div>
        <div class="alert-chips">
          <?php foreach (array_filter($inventory, fn($i) => in_array($i['status'], ['low','critical','out'])) as $li):
            $chip = htmlspecialchars($li['name']) . ' — ' . $li['quantity'] . ' ' . htmlspecialchars($li['unit']) . ' left';
          ?>
          <span class="alert-chip"><?= $chip ?></span>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- SUMMARY STATS -->
    <div class="mini-stats">
      <div class="ms">
        <div class="ms-num"><?= $totalItems ?></div>
        <div class="ms-label">Total items</div>
      </div>
      <div class="ms">
        <div class="ms-num"><?= count(array_filter($inventory, fn($i) => $i['status']==='ok')) ?></div>
        <div class="ms-label">Well stocked</div>
      </div>
      <div class="ms">
        <div class="ms-num" style="color:var(--amber)"><?= $lowItems ?></div>
        <div class="ms-label">Need restocking</div>
      </div>
      <div class="ms">
        <div class="ms-num">KSh <?= number_format($totalValue) ?></div>
        <div class="ms-label">Total stock value</div>
      </div>
    </div>

    <!-- TOOLBAR -->
    <form method="GET" class="toolbar">
      <div class="search-box">
        <span>🔍</span>
        <input type="text" name="q" placeholder="Search items or suppliers…" value="<?= htmlspecialchars($search) ?>">
      </div>
      <?php if (!empty($units)): ?>
      <select name="unit" class="form-select" style="width:auto;padding:8px 13px">
        <option value="">All units</option>
        <?php foreach ($units as $u): ?>
        <option value="<?= htmlspecialchars($u) ?>" <?= $filterUnit===$u?'selected':'' ?>><?= htmlspecialchars($u) ?></option>
        <?php endforeach; ?>
      </select>
      <?php endif; ?>
      <button type="submit" class="btn btn-secondary btn-sm">Filter</button>
      <?php if ($search || $filterUnit): ?>
      <a href="inventory.php" class="btn btn-secondary btn-sm">✕ Clear</a>
      <?php endif; ?>
    </form>

    <!-- INVENTORY TABLE -->
    <div class="grid-full">
      <div class="card">
        <?php if (empty($inventory)): ?>
        <div class="empty-cell" style="padding:50px">
          <div style="font-size:40px;margin-bottom:14px">📦</div>
          <div style="font-family:'Playfair Display',serif;font-size:18px;color:var(--deep);margin-bottom:8px">No inventory items yet</div>
          <div style="font-size:14px;color:var(--text-muted);margin-bottom:18px">Add your first stock item using the button above.</div>
          <button class="btn btn-primary btn-sm" onclick="document.getElementById('add-modal').classList.add('open')">+ Add First Item</button>
        </div>
        <?php else: ?>
        <table>
          <thead>
            <tr>
              <th>Item</th>
              <th>Supplier</th>
              <th>Unit</th>
              <th>In Stock</th>
              <th>Minimum</th>
              <th>Cost/Unit</th>
              <th>Status</th>
              <th>Restock</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($inventory as $item): ?>
            <tr>
              <td style="font-weight:600;color:var(--deep)"><?= htmlspecialchars($item['name']) ?></td>
              <td class="muted" style="font-size:12px"><?= htmlspecialchars($item['supplier'] ?? '—') ?></td>
              <td class="muted" style="font-size:12px"><?= htmlspecialchars($item['unit']) ?></td>
              <td>
                <span class="mono" style="font-weight:600;color:var(--deep)"><?= $item['quantity'] ?></span>
              </td>
              <td class="muted mono"><?= $item['min_threshold'] ?></td>
              <td class="cost-cell">KSh <?= number_format($item['cost_per_unit']) ?></td>
              <td>
                <span class="status-pill sp-<?= $item['status'] ?>">
                  <?= $item['status'] === 'ok' ? 'In Stock' : ucfirst($item['status']) ?>
                </span>
              </td>
              <td>
                <form method="POST" class="restock-form">
                  <input type="hidden" name="action" value="restock">
                  <input type="hidden" name="item_id" value="<?= $item['id'] ?>">
                  <input type="number" name="qty" class="restock-input" placeholder="+qty" min="0.1" step="0.5" required>
                  <button type="submit" class="restock-btn">Add</button>
                </form>
              </td>
              <td>
                <div class="action-cell">
                  <button class="icon-btn" title="Edit" onclick='openEdit(<?= json_encode($item) ?>)'>✏️</button>
                  <form method="POST" onsubmit="return confirm('Delete <?= htmlspecialchars(addslashes($item['name'])) ?> from inventory?')">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="item_id" value="<?= $item['id'] ?>">
                    <button type="submit" class="icon-btn del" title="Delete">🗑</button>
                  </form>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
    </div>

  </div><!-- /.content -->
</div><!-- /.main -->


<!-- ── ADD ITEM MODAL ────────────────────────────────────────────────────── -->
<div class="modal-overlay" id="add-modal" onclick="if(event.target===this)this.classList.remove('open')">
  <div class="modal">
    <div class="modal-head">
      <span class="modal-title">Add Inventory Item</span>
      <button class="modal-close" onclick="document.getElementById('add-modal').classList.remove('open')">×</button>
    </div>
    <form method="POST">
      <input type="hidden" name="action" value="add">
      <div class="modal-body">
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Item Name *</label>
            <input type="text" name="name" class="form-input" required placeholder="e.g. Tomatoes">
          </div>
          <div class="form-group">
            <label class="form-label">Unit *</label>
            <select name="unit" class="form-select">
              <option value="kg">kg</option>
              <option value="g">g</option>
              <option value="L">L (litres)</option>
              <option value="ml">ml</option>
              <option value="bunch">bunch</option>
              <option value="pcs">pcs</option>
              <option value="box">box</option>
              <option value="bag">bag</option>
              <option value="crate">crate</option>
            </select>
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Current Quantity</label>
            <input type="number" name="qty" class="form-input" placeholder="0" step="0.5" min="0" value="0">
          </div>
          <div class="form-group">
            <label class="form-label">Minimum Threshold</label>
            <input type="number" name="min" class="form-input" placeholder="e.g. 5" step="0.5" min="0" value="1">
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Cost per Unit (KSh)</label>
            <input type="number" name="cost" class="form-input" placeholder="0" step="0.01" min="0" value="0">
          </div>
          <div class="form-group">
            <label class="form-label">Supplier</label>
            <input type="text" name="supplier" class="form-input" placeholder="Supplier name">
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="document.getElementById('add-modal').classList.remove('open')">Cancel</button>
        <button type="submit" class="btn btn-primary">Add Item</button>
      </div>
    </form>
  </div>
</div>


<!-- ── EDIT ITEM MODAL ───────────────────────────────────────────────────── -->
<div class="modal-overlay" id="edit-modal" onclick="if(event.target===this)this.classList.remove('open')">
  <div class="modal">
    <div class="modal-head">
      <span class="modal-title">Edit Item</span>
      <button class="modal-close" onclick="document.getElementById('edit-modal').classList.remove('open')">×</button>
    </div>
    <form method="POST">
      <input type="hidden" name="action" value="edit">
      <input type="hidden" name="item_id" id="e-id">
      <div class="modal-body">
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Item Name *</label>
            <input type="text" name="name" id="e-name" class="form-input" required>
          </div>
          <div class="form-group">
            <label class="form-label">Unit</label>
            <select name="unit" id="e-unit" class="form-select">
              <option value="kg">kg</option>
              <option value="g">g</option>
              <option value="L">L (litres)</option>
              <option value="ml">ml</option>
              <option value="bunch">bunch</option>
              <option value="pcs">pcs</option>
              <option value="box">box</option>
              <option value="bag">bag</option>
              <option value="crate">crate</option>
            </select>
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Current Quantity</label>
            <input type="number" name="qty" id="e-qty" class="form-input" step="0.5" min="0">
          </div>
          <div class="form-group">
            <label class="form-label">Minimum Threshold</label>
            <input type="number" name="min" id="e-min" class="form-input" step="0.5" min="0">
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Cost per Unit (KSh)</label>
            <input type="number" name="cost" id="e-cost" class="form-input" step="0.01" min="0">
          </div>
          <div class="form-group">
            <label class="form-label">Supplier</label>
            <input type="text" name="supplier" id="e-supplier" class="form-input">
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="document.getElementById('edit-modal').classList.remove('open')">Cancel</button>
        <button type="submit" class="btn btn-primary">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<script>
function openEdit(item) {
  document.getElementById('e-id').value       = item.id;
  document.getElementById('e-name').value     = item.name;
  document.getElementById('e-unit').value     = item.unit;
  document.getElementById('e-qty').value      = item.quantity;
  document.getElementById('e-min').value      = item.min_threshold;
  document.getElementById('e-cost').value     = item.cost_per_unit;
  document.getElementById('e-supplier').value = item.supplier || '';
  document.getElementById('edit-modal').classList.add('open');
}
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') {
    document.querySelectorAll('.modal-overlay').forEach(m => m.classList.remove('open'));
  }
});
</script>
</body>
</html>