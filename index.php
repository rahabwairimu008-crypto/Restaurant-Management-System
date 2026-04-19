<?php
// index.php — Unified entry point, routes by role
session_start();
if (empty($_SESSION['user_id'])) {
    header('Location: login.php'); exit;
}
switch ($_SESSION['role']) {
    case 'admin':
    case 'cashier':   header('Location: admin_dashboard.php'); break;
    case 'waiter':    header('Location: waiter_pos.php');      break;
    case 'chef':
    case 'sous_chef': header('Location: kitchen_display.php'); break;
    case 'customer':  header('Location: customer_menu.php');   break;
    default:          header('Location: login.php');
}
exit;
