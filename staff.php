<?php
// staff.php — Staff Management (Admin only)
session_start();
require_once 'dbconfig.php';
if (empty($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php'); exit;
}
$pdo = getDB();
$activePage = 'staff';
$msg = ''; $msgType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        $email = strtolower(trim($_POST['email'] ?? ''));
        $exists = $pdo->prepare("SELECT id FROM users WHERE email=?");
        $exists->execute([$email]);
        if ($exists->fetch()) {
            $msg = 'Email already exists in the system.'; $msgType = 'error';
        } else {
            $pass = $_POST['password'] ?? 'staff123';
            $pdo->prepare("INSERT INTO users (name,email,phone,password_hash,role,status) VALUES (?,?,?,?,?,?)")
                ->execute([trim($_POST['name']),$email,trim($_POST['phone']),password_hash($pass,PASSWORD_DEFAULT),$_POST['role'],'active']);
            $msg = '✅ '.htmlspecialchars($_POST['name']).' added. Temp password: '.$pass; $msgType = 'success';
        }
    }
    if ($action === 'edit') {
        $pdo->prepare("UPDATE users SET name=?,email=?,phone=?,role=?,status=? WHERE id=?")
            ->execute([trim($_POST['name']),strtolower(trim($_POST['email'])),trim($_POST['phone']),$_POST['role'],$_POST['status'],(int)$_POST['user_id']]);
        if (!empty($_POST['new_password'])) {
            $pdo->prepare("UPDATE users SET password_hash=? WHERE id=?")
                ->execute([password_hash($_POST['new_password'],PASSWORD_DEFAULT),(int)$_POST['user_id']]);
        }
        $msg = '✅ Staff record updated.'; $msgType = 'success';
    }
    if ($action === 'delete') {
        $pdo->prepare("DELETE FROM users WHERE id=? AND role != 'customer'")->execute([(int)$_POST['user_id']]);
        $msg = '🗑 Staff member removed.'; $msgType = 'warning';
    }
    if ($action === 'set_status') {
        $pdo->prepare("UPDATE users SET status=? WHERE id=?")->execute([$_POST['status'],(int)$_POST['user_id']]);
        $msg = 'Status updated.'; $msgType = 'success';
    }
}

$staffRoles = ['admin','waiter','chef','sous_chef','cashier'];
$staff = $pdo->query("SELECT * FROM users WHERE role != 'customer' ORDER BY FIELD(status,'active','inactive'), name")->fetchAll();
$customers = $pdo->query("SELECT COUNT(*) FROM users WHERE role='customer'")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Jiko House — Staff</title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600&family=DM+Sans:wght@400;500;600&family=DM+Mono&display=swap" rel="stylesheet">
<style><?php readfile('_styles.css'); ?>
.role-chip{display:inline-block;background:var(--bg2);border-radius:5px;padding:2px 9px;font-size:11px;color:var(--brown);font-weight:600;text-transform:capitalize;}
.status-active{color:var(--sage);font-weight:600;font-size:12px;}
.status-inactive{color:var(--text-dim);font-size:12px;}
</style>
</head>
<body>
<?php include '_sidebar.php'; ?>
<div class="main">
  <div class="topbar">
    <h1 class="page-title">Staff Management</h1>
    <button class="btn btn-primary btn-sm" onclick="document.getElementById('add-modal').classList.add('open')">+ Add Staff</button>
  </div>
  <div class="content">
    <?php if ($msg): ?><div class="flash <?= $msgType ?>"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
    <div class="mini-stats">
      <div class="ms"><div class="ms-num"><?= count($staff) ?></div><div class="ms-label">Staff members</div></div>
      <div class="ms"><div class="ms-num"><?= count(array_filter($staff,fn($s)=>$s['status']==='active')) ?></div><div class="ms-label">Active</div></div>
      <div class="ms"><div class="ms-num"><?= $customers ?></div><div class="ms-label">Registered customers</div></div>
      <div class="ms"><div class="ms-num"><?= count(array_unique(array_column($staff,'role'))) ?></div><div class="ms-label">Roles in use</div></div>
    </div>
    <div class="card">
      <div class="card-head"><span class="card-title">All Staff</span></div>
      <table>
        <thead><tr><th>Name</th><th>Email</th><th>Phone</th><th>Role</th><th>Status</th><th>Joined</th><th>Actions</th></tr></thead>
        <tbody>
          <?php if (empty($staff)): ?>
          <tr><td colspan="7" class="empty-cell">No staff records yet.</td></tr>
          <?php else: foreach ($staff as $s): ?>
          <tr>
            <td style="font-weight:600;color:var(--deep)"><?= htmlspecialchars($s['name']) ?></td>
            <td class="muted" style="font-size:12px"><?= htmlspecialchars($s['email']) ?></td>
            <td class="muted" style="font-size:12px"><?= htmlspecialchars($s['phone']??'—') ?></td>
            <td><span class="role-chip"><?= str_replace('_',' ',$s['role']) ?></span></td>
            <td>
              <form method="POST" style="display:inline">
                <input type="hidden" name="action" value="set_status">
                <input type="hidden" name="user_id" value="<?= $s['id'] ?>">
                <select name="status" class="form-select" style="padding:4px 8px;font-size:12px;width:auto" onchange="this.form.submit()">
                  <option value="active" <?= $s['status']==='active'?'selected':'' ?>>Active</option>
                  <option value="inactive" <?= $s['status']==='inactive'?'selected':'' ?>>Inactive</option>
                </select>
              </form>
            </td>
            <td class="muted" style="font-size:12px"><?= date('d M Y', strtotime($s['created_at'])) ?></td>
            <td><div class="action-cell">
              <button class="icon-btn" onclick='openEdit(<?= json_encode($s) ?>)'>✏️</button>
              <?php if ($s['id'] != $_SESSION['user_id']): ?>
              <form method="POST" onsubmit="return confirm('Remove <?= htmlspecialchars(addslashes($s['name'])) ?>?')">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="user_id" value="<?= $s['id'] ?>">
                <button type="submit" class="icon-btn del">🗑</button>
              </form>
              <?php endif; ?>
            </div></td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- ADD MODAL -->
<div class="modal-overlay" id="add-modal" onclick="if(event.target===this)this.classList.remove('open')">
  <div class="modal">
    <div class="modal-head"><span class="modal-title">Add Staff Member</span>
      <button class="modal-close" onclick="document.getElementById('add-modal').classList.remove('open')">×</button></div>
    <form method="POST">
      <input type="hidden" name="action" value="add">
      <div class="modal-body">
        <div class="form-row">
          <div class="form-group"><label class="form-label">Full Name *</label><input type="text" name="name" class="form-input" required></div>
          <div class="form-group"><label class="form-label">Role *</label>
            <select name="role" class="form-select">
              <?php foreach ($staffRoles as $r): ?><option value="<?= $r ?>"><?= ucfirst(str_replace('_',' ',$r)) ?></option><?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="form-group"><label class="form-label">Email *</label><input type="email" name="email" class="form-input" required></div>
        <div class="form-row">
          <div class="form-group"><label class="form-label">Phone</label><input type="text" name="phone" class="form-input" placeholder="+254 7xx xxx xxx"></div>
          <div class="form-group"><label class="form-label">Password</label><input type="text" name="password" class="form-input" placeholder="staff123" value="staff123"></div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="document.getElementById('add-modal').classList.remove('open')">Cancel</button>
        <button type="submit" class="btn btn-primary">Add Staff</button>
      </div>
    </form>
  </div>
</div>

<!-- EDIT MODAL -->
<div class="modal-overlay" id="edit-modal" onclick="if(event.target===this)this.classList.remove('open')">
  <div class="modal">
    <div class="modal-head"><span class="modal-title">Edit Staff Member</span>
      <button class="modal-close" onclick="document.getElementById('edit-modal').classList.remove('open')">×</button></div>
    <form method="POST">
      <input type="hidden" name="action" value="edit">
      <input type="hidden" name="user_id" id="e-id">
      <div class="modal-body">
        <div class="form-row">
          <div class="form-group"><label class="form-label">Full Name *</label><input type="text" name="name" id="e-name" class="form-input" required></div>
          <div class="form-group"><label class="form-label">Role</label>
            <select name="role" id="e-role" class="form-select">
              <?php foreach ($staffRoles as $r): ?><option value="<?= $r ?>"><?= ucfirst(str_replace('_',' ',$r)) ?></option><?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="form-group"><label class="form-label">Email</label><input type="email" name="email" id="e-email" class="form-input"></div>
        <div class="form-row">
          <div class="form-group"><label class="form-label">Phone</label><input type="text" name="phone" id="e-phone" class="form-input"></div>
          <div class="form-group"><label class="form-label">Status</label>
            <select name="status" id="e-status" class="form-select">
              <option value="active">Active</option><option value="inactive">Inactive</option>
            </select>
          </div>
        </div>
        <div class="form-group"><label class="form-label">New Password (leave blank to keep)</label><input type="password" name="new_password" class="form-input" placeholder="Leave blank to keep current"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="document.getElementById('edit-modal').classList.remove('open')">Cancel</button>
        <button type="submit" class="btn btn-primary">Save Changes</button>
      </div>
    </form>
  </div>
</div>
<script>
function openEdit(s){
  document.getElementById('e-id').value=s.id;
  document.getElementById('e-name').value=s.name;
  document.getElementById('e-email').value=s.email;
  document.getElementById('e-role').value=s.role;
  document.getElementById('e-status').value=s.status;
  document.getElementById('e-phone').value=s.phone||'';
  document.getElementById('edit-modal').classList.add('open');
}
document.addEventListener('keydown',e=>{if(e.key==='Escape')document.querySelectorAll('.modal-overlay').forEach(m=>m.classList.remove('open'));});
</script>
</body></html>
