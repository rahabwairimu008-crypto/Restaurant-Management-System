<?php
// _sidebar.php — Shared sidebar included on every admin/staff page
// Usage: set $activePage = 'dashboard' (etc.) before including this file
$activePage = $activePage ?? '';

// Live badge counts
$pendingOrders = 0; $lowStock = 0; $cartCount = 0;
try {
    $db = getDB();
    $pendingOrders = (int)$db->query("SELECT COUNT(*) FROM orders WHERE status='pending'")->fetchColumn();
    $lowStock      = (int)$db->query("SELECT COUNT(*) FROM inventory WHERE quantity < min_threshold")->fetchColumn();
    $cartCount     = (int)$db->query("SELECT COUNT(*) FROM cart")->fetchColumn();
} catch (Exception $e) {}
?>
<aside class="sidebar">
  <div class="sb-logo">
    <div class="sb-logo-name">Jiko House</div>
    <div class="sb-logo-sub">Admin Console</div>
  </div>
  <nav class="sb-nav">
    <div class="sb-section">Main</div>
    <a href="admin_dashboard.php" class="sb-item <?= $activePage==='dashboard'    ?'active':'' ?>"><span class="sb-icon">📊</span> Dashboard</a>
    <a href="OrderController.php" class="sb-item <?= $activePage==='orders'       ?'active':'' ?>">
      <span class="sb-icon">📋</span> Orders
      <?php if ($pendingOrders > 0): ?><span class="sb-badge red"><?= $pendingOrders ?></span><?php endif; ?>
    </a>
    <a href="menu_management.php" class="sb-item <?= $activePage==='menu'         ?'active':'' ?>"><span class="sb-icon">🍽</span> Menu</a>
    <a href="categories.php"      class="sb-item <?= $activePage==='categories'   ?'active':'' ?>"><span class="sb-icon">🏷</span> Categories</a>
    <a href="reservations.php"    class="sb-item <?= $activePage==='reservations' ?'active':'' ?>"><span class="sb-icon">🪑</span> Reservations</a>
    <a href="cart.php"            class="sb-item <?= $activePage==='cart'         ?'active':'' ?>">
      <span class="sb-icon">🛒</span> Cart
      <?php if ($cartCount > 0): ?><span class="sb-badge"><?= $cartCount ?></span><?php endif; ?>
    </a>
    <div class="sb-section">Management</div>
    <a href="inventory.php"  class="sb-item <?= $activePage==='inventory' ?'active':'' ?>">
      <span class="sb-icon">📦</span> Inventory
      <?php if ($lowStock > 0): ?><span class="sb-badge red"><?= $lowStock ?></span><?php endif; ?>
    </a>
    <a href="staff.php"    class="sb-item <?= $activePage==='staff'    ?'active':'' ?>"><span class="sb-icon">👤</span> Staff</a>
    <a href="payments.php" class="sb-item <?= $activePage==='payments' ?'active':'' ?>"><span class="sb-icon">💳</span> Payments</a>
    <a href="reports.php"  class="sb-item <?= $activePage==='reports'  ?'active':'' ?>"><span class="sb-icon">📈</span> Reports</a>
    <div class="sb-section">System</div>
    <a href="settings.php" class="sb-item <?= $activePage==='settings' ?'active':'' ?>"><span class="sb-icon">⚙️</span> Settings</a>
    <a href="logout.php"   class="sb-item"><span class="sb-icon">🚪</span> Logout</a>
  </nav>
  <div class="sb-footer"><?= htmlspecialchars($_SESSION['user_name'] ?? 'Staff') ?> · v1.0</div>
</aside>
