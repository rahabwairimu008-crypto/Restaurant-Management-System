<?php
// categories.php — Menu Categories
session_start();
require_once 'dbconfig.php';
$pdo = getDB();
$msg = ''; $msgType = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        $pdo->prepare("INSERT INTO categories (name,sort_order,active) VALUES (?,?,1)")->execute([trim($_POST['name']),(int)$_POST['sort_order']]);
        $msg = '✅ Category added.'; $msgType = 'success';
    }
    if ($action === 'edit') {
        $pdo->prepare("UPDATE categories SET name=?,sort_order=?,active=? WHERE id=?")->execute([trim($_POST['name']),(int)$_POST['sort_order'],(int)isset($_POST['active']),(int)$_POST['cat_id']]);
        $msg = '✅ Category updated.'; $msgType = 'success';
    }
    if ($action === 'delete') {
        $pdo->prepare("DELETE FROM categories WHERE id=?")->execute([(int)$_POST['cat_id']]);
        $msg = '🗑 Category deleted.'; $msgType = 'warning';
    }
}
$cats = $pdo->query("SELECT c.*, (SELECT COUNT(*) FROM menu m WHERE m.category_id=c.id) AS item_count FROM categories c ORDER BY sort_order")->fetchAll();
?><!DOCTYPE html><html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Jiko House — Categories</title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<style>
  :root{--bg:#F4EFE6;--bg2:#EDE5D8;--surface:#FFFDF8;--border:rgba(92,61,46,0.12);--brown:#5C3D2E;--deep:#3A2218;--rust:#C0622B;--gold:#C49A3C;--sage:#5A8A5E;--red:#A83232;--text:#2C1A0E;--text-muted:#7A5C45;--text-dim:#B09A85;--shadow:0 2px 16px rgba(60,34,24,0.08);}
  *{margin:0;padding:0;box-sizing:border-box;}
  body{background:var(--bg);font-family:'DM Sans',sans-serif;color:var(--text);min-height:100vh;display:flex;}
  aside{width:234px;background:var(--deep);flex-shrink:0;position:fixed;top:0;left:0;bottom:0;z-index:10;display:flex;flex-direction:column;}
  .sidebar-logo{padding:24px 20px 18px;border-bottom:1px solid rgba(255,255,255,0.07);}
  .logo-main{font-family:'Playfair Display',serif;font-size:20px;color:var(--gold);}
  .logo-sub{font-size:10px;color:rgba(240,228,210,0.35);letter-spacing:.18em;text-transform:uppercase;margin-top:3px;}
  nav{flex:1;padding:14px 10px;overflow-y:auto;}
  .nav-section{font-size:10px;text-transform:uppercase;letter-spacing:.16em;color:rgba(240,228,210,0.28);padding:14px 10px 5px;font-weight:600;}
  .nav-item{display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:9px;font-size:14px;color:rgba(240,228,210,0.62);transition:all .18s;margin-bottom:2px;text-decoration:none;}
  .nav-item:hover{background:rgba(255,255,255,0.07);color:rgba(240,228,210,0.95);}
  .nav-item.active{background:var(--rust);color:#fff;font-weight:500;}
  .nav-icon{font-size:15px;width:20px;text-align:center;flex-shrink:0;}
  .sidebar-footer{padding:14px;border-top:1px solid rgba(255,255,255,0.07);font-size:11px;color:rgba(240,228,210,0.28);text-align:center;}
  .main{margin-left:234px;flex:1;display:flex;flex-direction:column;}
  .topbar{background:var(--surface);border-bottom:1px solid var(--border);padding:0 28px;height:62px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:5;box-shadow:var(--shadow);}
  .page-title{font-family:'Playfair Display',serif;font-size:20px;color:var(--deep);}
  .btn{padding:9px 18px;border-radius:9px;font-family:'DM Sans',sans-serif;font-size:13px;font-weight:600;cursor:pointer;border:none;transition:all .18s;}
  .btn-primary{background:var(--rust);color:#fff;} .btn-primary:hover{background:#A8501E;}
  .btn-sm{padding:6px 12px;font-size:12px;border-radius:7px;}
  .content{padding:28px;}
  .flash{padding:12px 18px;border-radius:10px;font-size:14px;margin-bottom:20px;border:1px solid;font-weight:500;}
  .flash.success{background:rgba(90,138,94,0.1);border-color:rgba(90,138,94,0.25);color:#2A6B30;}
  .flash.warning{background:rgba(196,154,60,0.1);border-color:rgba(196,154,60,0.25);color:#8A6010;}
  .grid-2{display:grid;grid-template-columns:1fr 320px;gap:22px;}
  .card{background:var(--surface);border:1px solid var(--border);border-radius:14px;box-shadow:var(--shadow);overflow:hidden;}
  .card-head{padding:16px 20px 13px;border-bottom:1px solid var(--border);}
  .card-title{font-family:'Playfair Display',serif;font-size:16px;color:var(--deep);}
  table{width:100%;border-collapse:collapse;}
  th{padding:10px 16px;text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.1em;color:var(--text-dim);font-weight:600;border-bottom:1px solid var(--border);background:var(--bg);}
  td{padding:12px 16px;font-size:13px;border-bottom:1px solid var(--border);vertical-align:middle;}
  tr:last-child td{border-bottom:none;} tbody tr:hover td{background:var(--bg);}
  .icon-btn{width:30px;height:30px;border-radius:7px;border:1px solid var(--border);background:none;cursor:pointer;font-size:14px;display:flex;align-items:center;justify-content:center;transition:all .15s;color:var(--text-muted);}
  .icon-btn:hover{border-color:var(--brown);color:var(--text);background:var(--bg2);}
  .icon-btn.del:hover{border-color:var(--red);color:var(--red);}
  .action-cell{display:flex;gap:6px;}
  .status-on{color:var(--sage);font-weight:600;font-size:12px;} .status-off{color:var(--text-dim);font-size:12px;}
  .form-group{margin-bottom:16px;} .add-body{padding:20px;} .add-footer{padding:14px 20px;border-top:1px solid var(--border);}
  .form-label{display:block;font-size:11px;text-transform:uppercase;letter-spacing:.12em;color:var(--text-muted);font-weight:600;margin-bottom:6px;}
  .form-input{width:100%;background:var(--bg);border:1.5px solid var(--border);border-radius:9px;padding:10px 13px;font-family:'DM Sans',sans-serif;font-size:14px;color:var(--text);outline:none;}
  .form-input:focus{border-color:rgba(192,98,43,.5);}
  .cb-row{display:flex;align-items:center;gap:8px;font-size:14px;cursor:pointer;}
  .empty-cell{text-align:center;padding:32px;color:var(--text-dim);font-style:italic;}
</style>
</head><body>
<aside>
  <div class="sidebar-logo"><div class="logo-main">Jiko House</div><div class="logo-sub">Admin Console</div></div>
  <nav>
    <div class="nav-section">Main</div>
    <a href="admin_dashboard.php" class="nav-item"><span class="nav-icon">📊</span> Dashboard</a>
    <a href="OrderController.php" class="nav-item"><span class="nav-icon">📋</span> Orders</a>
    <a href="menu_management.php" class="nav-item"><span class="nav-icon">🍽</span> Menu</a>
    <a href="reservations.php" class="nav-item"><span class="nav-icon">🪑</span> Reservations</a>
    <a href="cart.php" class="nav-item"><span class="nav-icon">🛒</span> Cart</a>
    <div class="nav-section">Management</div>
    <a href="inventory.php" class="nav-item"><span class="nav-icon">📦</span> Inventory</a>
    <a href="staff.php" class="nav-item"><span class="nav-icon">👤</span> Staff</a>
    <a href="payments.php" class="nav-item"><span class="nav-icon">💳</span> Payments</a>
    <a href="reports.php" class="nav-item"><span class="nav-icon">📈</span> Reports</a>
    <a href="categories.php" class="nav-item active"><span class="nav-icon">🏷</span> Categories</a>
    <div class="nav-section">System</div>
    <a href="settings.php" class="nav-item"><span class="nav-icon">⚙️</span> Settings</a>
    <a href="login.php" class="nav-item"><span class="nav-icon">🚪</span> Logout</a>
  </nav>
  <div class="sidebar-footer">v1.0.0 · Jiko RMS</div>
</aside>
<div class="main">
  <div class="topbar"><h1 class="page-title">Menu Categories</h1></div>
  <div class="content">
    <?php if ($msg): ?><div class="flash <?= $msgType ?>"><?= $msg ?></div><?php endif; ?>
    <div class="grid-2">
      <div class="card">
        <div class="card-head"><div class="card-title">All Categories</div></div>
        <table>
          <thead><tr><th>#</th><th>Name</th><th>Items</th><th>Order</th><th>Active</th><th></th></tr></thead>
          <tbody>
            <?php if (empty($cats)): ?><tr><td colspan="6" class="empty-cell">No categories yet.</td></tr>
            <?php else: foreach ($cats as $c): ?>
            <tr>
              <td class="muted" style="font-family:'DM Mono',monospace;font-size:12px"><?= $c['id'] ?></td>
              <td style="font-weight:600;color:var(--deep)"><?= htmlspecialchars($c['name']) ?></td>
              <td><?= $c['item_count'] ?></td>
              <td class="muted"><?= $c['sort_order'] ?></td>
              <td><span class="<?= $c['active']?'status-on':'status-off' ?>"><?= $c['active']?'● Active':'○ Hidden' ?></span></td>
              <td><div class="action-cell">
                <button class="icon-btn" onclick='openEdit(<?= json_encode($c) ?>)'>✏️</button>
                <form method="POST" onsubmit="return confirm('Delete category?')">
                  <input type="hidden" name="action" value="delete"><input type="hidden" name="cat_id" value="<?= $c['id'] ?>">
                  <button type="submit" class="icon-btn del">🗑</button>
                </form>
              </div></td>
            </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
      <div>
        <div class="card" style="margin-bottom:16px">
          <div class="card-head"><div class="card-title">Add Category</div></div>
          <form method="POST"><input type="hidden" name="action" value="add">
            <div class="add-body">
              <div class="form-group"><label class="form-label">Name *</label><input type="text" name="name" class="form-input" required placeholder="e.g. Grills"></div>
              <div class="form-group"><label class="form-label">Sort Order</label><input type="number" name="sort_order" class="form-input" value="10"></div>
            </div>
            <div class="add-footer"><button type="submit" class="btn btn-primary" style="width:100%">Add Category</button></div>
          </form>
        </div>
        <div class="card" id="edit-card" style="display:none">
          <div class="card-head"><div class="card-title">Edit Category</div></div>
          <form method="POST"><input type="hidden" name="action" value="edit"><input type="hidden" name="cat_id" id="edit-id">
            <div class="add-body">
              <div class="form-group"><label class="form-label">Name *</label><input type="text" name="name" id="edit-name" class="form-input" required></div>
              <div class="form-group"><label class="form-label">Sort Order</label><input type="number" name="sort_order" id="edit-order" class="form-input"></div>
              <div class="form-group"><label class="cb-row"><input type="checkbox" name="active" id="edit-active" value="1"> Active</label></div>
            </div>
            <div class="add-footer" style="display:flex;gap:8px">
              <button type="button" class="btn btn-sm" style="background:var(--bg2);color:var(--brown)" onclick="document.getElementById('edit-card').style.display='none'">Cancel</button>
              <button type="submit" class="btn btn-primary" style="flex:1">Save</button>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>
</div>
<script>
function openEdit(c){
  document.getElementById('edit-id').value=c.id;
  document.getElementById('edit-name').value=c.name;
  document.getElementById('edit-order').value=c.sort_order;
  document.getElementById('edit-active').checked=!!parseInt(c.active);
  document.getElementById('edit-card').style.display='block';
  document.getElementById('edit-card').scrollIntoView({behavior:'smooth'});
}
</script>
</body></html>
