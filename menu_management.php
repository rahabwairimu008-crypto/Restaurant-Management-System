<?php
// menu_management.php — Menu CRUD (Admin)
session_start();
require_once 'dbconfig.php';
if (empty($_SESSION['user_id']) || !in_array($_SESSION['role'],['admin','cashier'])) {
    header('Location: login.php'); exit;
}
$pdo = getDB();
// CRITICAL: force utf8mb4 so emojis survive round-trips
$pdo->exec("SET NAMES 'utf8mb4' COLLATE 'utf8mb4_unicode_ci'");
$activePage = 'menu';
$msg = ''; $msgType = '';

// ── ACTIONS ───────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'edit') {
        $name      = trim($_POST['name'] ?? '');
        $desc      = trim($_POST['desc'] ?? '');
        $price     = (float)($_POST['price'] ?? 0);
        $cat_id    = (int)($_POST['cat_id'] ?? 0) ?: null;
        $emoji     = trim($_POST['emoji'] ?? '🍽');
        $badge     = trim($_POST['badge'] ?? '') ?: null;
        $spicy     = isset($_POST['spicy']) ? 1 : 0;
        $available = isset($_POST['available']) ? 1 : 0;

        if (!$name || $price <= 0) {
            $msg = 'Name and a valid price are required.'; $msgType = 'error';
        } elseif ($action === 'add') {
            $pdo->prepare("INSERT INTO menu (category_id,name,description,price,emoji,badge,spicy,available) VALUES (?,?,?,?,?,?,?,?)")
                ->execute([$cat_id,$name,$desc,$price,$emoji,$badge,$spicy,$available]);
            $msg = "✅ \"{$name}\" added to the menu."; $msgType = 'success';
        } else {
            $pdo->prepare("UPDATE menu SET category_id=?,name=?,description=?,price=?,emoji=?,badge=?,spicy=?,available=?,updated_at=NOW() WHERE id=?")
                ->execute([$cat_id,$name,$desc,$price,$emoji,$badge,$spicy,$available,(int)$_POST['item_id']]);
            $msg = "✅ \"{$name}\" updated."; $msgType = 'success';
        }
    }
    if ($action === 'delete') {
        $pdo->prepare("DELETE FROM menu WHERE id=?")->execute([(int)$_POST['item_id']]);
        $msg = '🗑 Item removed.'; $msgType = 'warning';
    }
    if ($action === 'toggle') {
        $pdo->prepare("UPDATE menu SET available = 1 - available WHERE id=?")->execute([(int)$_POST['item_id']]);
        $msg = 'Availability updated.'; $msgType = 'success';
    }
}

$cats = $pdo->query("SELECT * FROM categories ORDER BY sort_order")->fetchAll();
$filterCat = (int)($_GET['cat'] ?? 0);
$search    = trim($_GET['q'] ?? '');

$where = []; $params = [];
if ($filterCat) { $where[] = 'm.category_id = ?'; $params[] = $filterCat; }
if ($search)    { $where[] = '(m.name LIKE ? OR m.description LIKE ?)'; $params[] = "%$search%"; $params[] = "%$search%"; }
$sql = "SELECT m.*, c.name AS cat_name FROM menu m LEFT JOIN categories c ON m.category_id=c.id"
     . ($where ? ' WHERE '.implode(' AND ',$where) : '')
     . " ORDER BY c.sort_order, m.name";
$stmt = $pdo->prepare($sql); $stmt->execute($params);
$items = $stmt->fetchAll();

$total  = count($pdo->query("SELECT id FROM menu")->fetchAll());
$avail  = count($pdo->query("SELECT id FROM menu WHERE available=1")->fetchAll());
$hidden = $total - $avail;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Jiko House — Menu</title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600&family=DM+Sans:wght@400;500;600&family=DM+Mono&display=swap" rel="stylesheet">
<style>
<?php readfile('_styles.css'); ?>
.emoji-picker{display:flex;gap:5px;flex-wrap:wrap;margin-top:8px;}
.emoji-opt{width:36px;height:36px;border-radius:8px;border:1.5px solid var(--border);background:var(--bg);cursor:pointer;font-size:20px;display:flex;align-items:center;justify-content:center;transition:all .15s;}
.emoji-opt:hover,.emoji-opt.sel{border-color:var(--rust);background:rgba(192,98,43,.08);}
.avail-on{background:rgba(90,138,94,.12);color:var(--sage);font-size:11px;font-weight:700;padding:3px 9px;border-radius:5px;border:none;cursor:pointer;}
.avail-off{background:rgba(168,50,50,.08);color:var(--red);font-size:11px;font-weight:700;padding:3px 9px;border-radius:5px;border:none;cursor:pointer;}
.badge-chip{display:inline-block;padding:2px 8px;border-radius:4px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;}
.bc-chef{background:rgba(196,154,60,.15);color:#8A6010;} .bc-new{background:rgba(90,138,94,.12);color:var(--sage);}
.stats-row{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:22px;}
.stat-card{background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:18px 20px;box-shadow:var(--shadow);animation:fadeUp .4s ease both;}
.sc-val{font-family:'Playfair Display',serif;font-size:26px;color:var(--deep);line-height:1;}
.sc-lbl{font-size:11px;color:var(--text-muted);margin-top:4px;}
.toolbar{background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:14px 18px;margin-bottom:18px;display:flex;gap:12px;align-items:center;flex-wrap:wrap;box-shadow:var(--shadow);}
.search-box{display:flex;align-items:center;gap:8px;background:var(--bg);border:1.5px solid var(--border);border-radius:8px;padding:8px 13px;flex:1;min-width:180px;}
.search-box input{border:none;background:none;outline:none;font-family:'DM Sans',sans-serif;font-size:14px;color:var(--text);flex:1;}
.search-box input::placeholder{color:var(--text-dim);}
.cat-pills{display:flex;gap:6px;flex-wrap:wrap;}
.cat-pill{padding:6px 14px;border-radius:20px;font-size:12px;font-weight:600;cursor:pointer;border:1.5px solid var(--border);background:none;color:var(--text-muted);transition:all .18s;font-family:'DM Sans',sans-serif;text-decoration:none;}
.cat-pill.active{background:var(--brown);color:#fff;border-color:var(--brown);}
.cat-pill:hover:not(.active){border-color:var(--rust);color:var(--rust);}
.item-emoji{font-size:26px;} .item-desc{font-size:12px;color:var(--text-muted);margin-top:2px;max-width:280px;}
</style>
</head>
<body>
<?php include '_sidebar.php'; ?>
<div class="main">
  <div class="topbar">
    <h1 class="page-title">Menu Management</h1>
    <div class="topbar-right">
      <a href="?action=reset" class="btn btn-secondary btn-sm" onclick="return confirm('Reset to defaults?')">↺ Reset</a>
      <button class="btn btn-primary btn-sm" onclick="openAdd()">+ Add Item</button>
    </div>
  </div>
  <div class="content">
    <?php if ($msg): ?><div class="flash <?= $msgType ?>"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

    <!-- STATS -->
    <div class="stats-row">
      <div class="stat-card"><div class="sc-val"><?= $total ?></div><div class="sc-lbl">Total items</div></div>
      <div class="stat-card"><div class="sc-val"><?= $avail ?></div><div class="sc-lbl">Available</div></div>
      <div class="stat-card"><div class="sc-val"><?= $hidden ?></div><div class="sc-lbl">Hidden</div></div>
      <div class="stat-card"><div class="sc-val"><?= count($cats) ?></div><div class="sc-lbl">Categories</div></div>
    </div>

    <!-- TOOLBAR -->
    <form method="GET" class="toolbar">
      <div class="search-box"><span>🔍</span><input name="q" type="text" placeholder="Search items…" value="<?= htmlspecialchars($search) ?>"></div>
      <div class="cat-pills">
        <a href="menu_management.php" class="cat-pill <?= !$filterCat?'active':'' ?>">All</a>
        <?php foreach ($cats as $c): ?>
        <a href="?cat=<?= $c['id'] ?><?= $search?"&q=".urlencode($search):'' ?>" class="cat-pill <?= $filterCat==$c['id']?'active':'' ?>"><?= htmlspecialchars($c['name']) ?></a>
        <?php endforeach; ?>
      </div>
      <button type="submit" class="btn btn-secondary btn-sm">Filter</button>
    </form>

    <!-- TABLE -->
    <div class="card">
      <?php if (empty($items)): ?>
      <div style="text-align:center;padding:50px;color:var(--text-dim)">
        <div style="font-size:40px;margin-bottom:14px">🍽</div>
        <div style="font-family:'Playfair Display',serif;font-size:18px;color:var(--deep);margin-bottom:8px">No items found</div>
        <button class="btn btn-primary btn-sm" onclick="openAdd()">+ Add first item</button>
      </div>
      <?php else: ?>
      <table>
        <thead><tr><th></th><th>Item</th><th>Category</th><th>Price</th><th>Badge</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
          <?php foreach ($items as $item): ?>
          <tr>
            <td class="item-emoji"><?= $item['emoji'] ?><?= $item['spicy']?' 🌶':''; ?></td>
            <td>
              <div style="font-weight:600;color:var(--deep)"><?= htmlspecialchars($item['name']) ?></div>
              <div class="item-desc"><?= htmlspecialchars($item['description'] ?? '') ?></div>
            </td>
            <td><span style="background:var(--bg2);border-radius:5px;padding:2px 8px;font-size:11px;color:var(--brown);font-weight:600"><?= htmlspecialchars($item['cat_name'] ?? '—') ?></span></td>
            <td class="mono" style="font-weight:600;color:var(--deep)">KSh <?= number_format($item['price']) ?></td>
            <td><?php if ($item['badge']): $bc = stripos($item['badge'],'chef')!==false?'bc-chef':'bc-new'; ?>
              <span class="badge-chip <?= $bc ?>"><?= htmlspecialchars($item['badge']) ?></span>
              <?php else: ?><span style="color:var(--text-dim);font-size:12px">—</span><?php endif; ?>
            </td>
            <td>
              <form method="POST" style="display:inline">
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="item_id" value="<?= $item['id'] ?>">
                <button type="submit" class="<?= $item['available']?'avail-on':'avail-off' ?>">
                  <?= $item['available']?'● Available':'○ Hidden' ?>
                </button>
              </form>
            </td>
            <td><div class="action-cell">
              <button class="icon-btn" onclick='openEdit(<?= json_encode($item) ?>)'>✏️</button>
              <form method="POST" onsubmit="return confirm('Delete «<?= htmlspecialchars(addslashes($item['name'])) ?>»?')">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="item_id" value="<?= $item['id'] ?>">
                <button type="submit" class="icon-btn del">🗑</button>
              </form>
            </div></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- ADD/EDIT MODAL -->
<div class="modal-overlay" id="modal" onclick="if(event.target===this)closeModal()">
  <div class="modal">
    <div class="modal-head">
      <span class="modal-title" id="modal-title">Add Menu Item</span>
      <button class="modal-close" onclick="closeModal()">×</button>
    </div>
    <form method="POST" id="item-form">
      <input type="hidden" name="action" id="f-action" value="add">
      <input type="hidden" name="item_id" id="f-id">
      <div class="modal-body">
        <div class="form-row">
          <div class="form-group"><label class="form-label">Name *</label><input type="text" name="name" id="f-name" class="form-input" required placeholder="e.g. Pilau Rice"></div>
          <div class="form-group"><label class="form-label">Price (KSh) *</label><input type="number" name="price" id="f-price" class="form-input" placeholder="0" min="1" required></div>
        </div>
        <div class="form-group"><label class="form-label">Description</label><textarea name="desc" id="f-desc" class="form-textarea" placeholder="Short description…"></textarea></div>
        <div class="form-row">
          <div class="form-group"><label class="form-label">Category</label>
            <select name="cat_id" id="f-cat" class="form-select">
              <option value="">— None —</option>
              <?php foreach ($cats as $c): ?><option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="form-group"><label class="form-label">Badge</label>
            <select name="badge" id="f-badge" class="form-select">
              <option value="">None</option>
              <option value="Chef's Pick">Chef's Pick</option>
              <option value="New">New</option>
              <option value="Popular">Popular</option>
              <option value="Seasonal">Seasonal</option>
              <option value="Special">Special</option>
            </select>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Emoji Icon</label>
          <input type="text" name="emoji" id="f-emoji" class="form-input" placeholder="🍽" maxlength="8" style="font-size:20px">
          <div class="emoji-picker">
            <?php foreach (['🍽','🥩','🍛','🍲','🥟','🥐','🍱','🍜','🫕','🥗','🍳','🥘','🌮','🍖','🍗','☕','🥭','🧃','🍺','🍹','🥛','🍨','🎂','🍰','🫖','🥤'] as $e): ?>
            <div class="emoji-opt" onclick="pickEmoji('<?= $e ?>')"><?= $e ?></div>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="form-group">
          <div style="display:flex;gap:20px">
            <label style="display:flex;align-items:center;gap:8px;font-size:14px;cursor:pointer">
              <input type="checkbox" name="spicy" id="f-spicy" style="accent-color:var(--rust)"> 🌶 Spicy
            </label>
            <label style="display:flex;align-items:center;gap:8px;font-size:14px;cursor:pointer">
              <input type="checkbox" name="available" id="f-available" checked style="accent-color:var(--rust)"> ✅ Available
            </label>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
        <button type="submit" class="btn btn-primary" id="f-submit">Add Item</button>
      </div>
    </form>
  </div>
</div>

<script>
function openAdd() {
  document.getElementById('modal-title').textContent = 'Add Menu Item';
  document.getElementById('f-action').value = 'add';
  document.getElementById('f-id').value = '';
  document.getElementById('f-submit').textContent = 'Add Item';
  document.getElementById('item-form').reset();
  document.getElementById('f-available').checked = true;
  document.querySelectorAll('.emoji-opt').forEach(e => e.classList.remove('sel'));
  document.getElementById('modal').classList.add('open');
}
function openEdit(item) {
  document.getElementById('modal-title').textContent = 'Edit Menu Item';
  document.getElementById('f-action').value = 'edit';
  document.getElementById('f-id').value = item.id;
  document.getElementById('f-submit').textContent = 'Save Changes';
  document.getElementById('f-name').value = item.name;
  document.getElementById('f-price').value = item.price;
  document.getElementById('f-desc').value = item.description || '';
  document.getElementById('f-cat').value = item.category_id || '';
  document.getElementById('f-badge').value = item.badge || '';
  document.getElementById('f-emoji').value = item.emoji || '🍽';
  document.getElementById('f-spicy').checked = !!parseInt(item.spicy);
  document.getElementById('f-available').checked = !!parseInt(item.available);
  document.querySelectorAll('.emoji-opt').forEach(el => el.classList.toggle('sel', el.textContent.trim() === item.emoji));
  document.getElementById('modal').classList.add('open');
}
function closeModal() { document.getElementById('modal').classList.remove('open'); }
function pickEmoji(e) {
  document.getElementById('f-emoji').value = e;
  document.querySelectorAll('.emoji-opt').forEach(el => el.classList.toggle('sel', el.textContent.trim() === e));
}
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal(); });
</script>
</body>
</html>
