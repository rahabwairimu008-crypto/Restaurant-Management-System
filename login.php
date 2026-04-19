<?php
// login.php — Unified login + customer registration
session_start();
require_once 'dbconfig.php';  // ← only once

$pdo = getDB();

// Already logged in → go to correct page
if (!empty($_SESSION['user_id'])) {
    header('Location: index.php'); exit;
}

$error   = '';
$success = '';
$tab     = $_GET['tab'] ?? 'login';

// ── HANDLE LOGIN ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_submit'])) {
    $email    = strtolower(trim($_POST['email']    ?? ''));
    $password = $_POST['password'] ?? '';
    $wantRole = $_POST['role']     ?? '';   // role the user selected on the form

    if (!$email || !$password) {
        $error = 'Please enter your email and password.';

    } else {
        // Fetch user by email (case-insensitive)
        $stmt = $pdo->prepare("SELECT * FROM users WHERE LOWER(email) = ? LIMIT 1");
        $stmt->execute([strtolower($email)]);
        $user = $stmt->fetch();

        if (!$user) {
            $error = 'No account found with that email address.';
        } elseif ($user['status'] !== 'active') {
            $error = 'This account is inactive. Please contact the administrator.';

        } elseif (!password_verify($password, $user['password_hash'])) {
            $error = 'Incorrect password. Please try again.';

        } elseif ($wantRole && $user['role'] !== $wantRole) {
            // Role mismatch — user picked wrong role on the selector
            $error = 'This account is not registered as a ' . ucfirst(str_replace('_',' ',$wantRole)) . '. Your role is: ' . ucfirst(str_replace('_',' ',$user['role'])) . '.';

        } else {
            // ✅ Login success
            $_SESSION['user_id']   = $user['id'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['role']      = $user['role'];
            $_SESSION['email']     = $user['email'];

            // Redirect by role
            switch ($user['role']) {
                case 'admin':
                case 'cashier':
                    header('Location: admin_dashboard.php'); break;
                case 'waiter':
                    header('Location: Waiter_pos.php');      break;
                case 'chef':
                case 'sous_chef':
                    header('Location: kitchen_display.php'); break;
                case 'customer':
                    header('Location: customer_menu.php');   break;
                default:
                    header('Location: index.php');
            }
            exit;
        }
    }
    $tab = 'login';
}

// ── HANDLE REGISTER (customers only) ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register_submit'])) {
    $name     = trim($_POST['name']         ?? '');
    $email    = strtolower(trim($_POST['reg_email']    ?? ''));
    $phone    = trim($_POST['phone']        ?? '');
    $password = $_POST['reg_password']      ?? '';
    $confirm  = $_POST['reg_confirm']       ?? '';
    $tab      = 'register';

    if (!$name || !$email || !$password) {
        $error = 'Name, email and password are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        // ← FIXED: uses "id" not "user_id" (matches setup.sql schema)
        $check = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $check->execute([$email]);
        if ($check->fetch()) {
            $error = 'An account with this email already exists. Please sign in.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $pdo->prepare(
                "INSERT INTO users (name, email, phone, password_hash, role, status)
                 VALUES (?, ?, ?, ?, 'customer', 'active')"
            )->execute([$name, $email, $phone, $hash]);

            $success = 'Account created! You can now sign in below.';
            $tab = 'login';
        }
    }
}

// ── Role options shown in the selector ───────────────────────────────────────
$roleOptions = [
    ''          => '— Select your role —',
    'customer'  => '🛒 Customer',
    'admin'     => '👔 Admin',
    'cashier'   => '💳 Cashier',
    'waiter'    => '🍽 Waiter',
    'chef'      => '👨‍🍳 Chef',
    'sous_chef' => '🧑‍🍳 Sous Chef',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Jiko House — Sign In</title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;600&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
  :root {
    --bg:      #1C0F07;
    --surface: #2A1A0D;
    --surface2:#322010;
    --border:  rgba(196,154,60,.20);
    --gold:    #C49A3C;
    --goldlt:  #E8C46A;
    --rust:    #C0622B;
    --text:    #F2E8D5;
    --muted:   #9A7A60;
    --dim:     #5C4030;
    --sage:    #5A8A5E;
    --red:     #E84040;
  }
  *, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }

  body {
    background: var(--bg);
    font-family: 'DM Sans', sans-serif;
    color: var(--text);
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
    background-image:
      radial-gradient(ellipse at 25% 15%, rgba(192,98,43,.14) 0%, transparent 50%),
      radial-gradient(ellipse at 78% 85%, rgba(196,154,60,.09) 0%, transparent 50%);
  }

  .wrap { width:100%; max-width:460px; }

  /* LOGO */
  .logo-section { text-align:center; margin-bottom:30px; }
  .logo-name    { font-family:'Playfair Display',serif; font-size:42px; color:var(--gold); letter-spacing:.05em; line-height:1; }
  .logo-sub     { font-size:11px; letter-spacing:.22em; text-transform:uppercase; color:var(--dim); margin-top:7px; }
  .ornament     { margin-top:12px; display:flex; align-items:center; justify-content:center; gap:12px; color:var(--dim); }
  .ornament::before, .ornament::after { content:''; width:50px; height:1px; background:var(--border); }

  /* CARD */
  .card { background:var(--surface); border:1px solid var(--border); border-radius:18px; padding:32px 30px 28px; box-shadow:0 16px 60px rgba(0,0,0,.5); }

  /* TABS */
  .tab-bar { display:grid; grid-template-columns:1fr 1fr; background:rgba(255,255,255,.04); border:1px solid var(--border); border-radius:10px; padding:3px; margin-bottom:22px; }
  .tab-btn  { padding:9px; border:none; border-radius:8px; background:none; font-family:'DM Sans',sans-serif; font-size:13px; font-weight:600; color:var(--muted); cursor:pointer; transition:all .2s; }
  .tab-btn.active { background:var(--rust); color:#fff; }

  /* PANELS */
  .panel        { display:none; }
  .panel.active { display:block; }

  /* ALERTS */
  .alert         { padding:11px 14px; border-radius:8px; font-size:13px; font-weight:500; margin-bottom:18px; display:flex; align-items:flex-start; gap:8px; line-height:1.5; }
  .alert-error   { background:rgba(232,64,64,.12); border:1px solid rgba(232,64,64,.3); color:var(--red); }
  .alert-success { background:rgba(90,138,94,.12);  border:1px solid rgba(90,138,94,.3); color:#5A8A5E; }

  /* ROLE SELECTOR */
  .role-section  { margin-bottom:18px; }
  .role-label    { display:block; font-size:11px; text-transform:uppercase; letter-spacing:.14em; color:var(--muted); font-weight:600; margin-bottom:10px; }
  .role-grid     { display:grid; grid-template-columns:1fr 1fr; gap:8px; }
  .role-opt      { position:relative; }
  .role-opt input[type=radio] { position:absolute; opacity:0; width:0; height:0; }
  .role-opt label {
    display:flex; flex-direction:column; align-items:center; justify-content:center;
    gap:5px; padding:12px 8px; border:2px solid rgba(196,154,60,.12);
    border-radius:10px; cursor:pointer; transition:all .2s;
    background:rgba(255,255,255,.03); text-align:center;
  }
  .role-opt label:hover { border-color:rgba(196,154,60,.3); background:rgba(255,255,255,.05); }
  .role-opt input:checked + label { border-color:var(--rust); background:rgba(192,98,43,.1); }
  .role-icon  { font-size:22px; }
  .role-name  { font-size:12px; font-weight:600; color:var(--text); }
  .role-desc  { font-size:10px; color:var(--muted); }
  /* Customer gets full-width */
  .role-opt.full { grid-column:1/-1; }
  .role-opt.full label { flex-direction:row; gap:10px; padding:12px 18px; justify-content:flex-start; }

  /* FORM FIELDS */
  .field        { margin-bottom:16px; }
  .field label  { display:block; font-size:11px; text-transform:uppercase; letter-spacing:.14em; color:var(--muted); font-weight:600; margin-bottom:7px; }
  .field input  { width:100%; background:rgba(255,255,255,.05); border:1.5px solid var(--border); border-radius:10px; padding:12px 14px; font-family:'DM Sans',sans-serif; font-size:15px; color:var(--text); outline:none; transition:border-color .2s; }
  .field input:focus { border-color:rgba(196,154,60,.55); }
  .field input::placeholder { color:var(--dim); }
  .row-2 { display:grid; grid-template-columns:1fr 1fr; gap:12px; }

  /* SUBMIT BTN */
  .submit-btn { width:100%; background:var(--rust); color:#fff; border:none; border-radius:11px; padding:14px; font-family:'DM Sans',sans-serif; font-size:15px; font-weight:600; letter-spacing:.05em; cursor:pointer; transition:background .2s; margin-top:4px; }
  .submit-btn:hover { background:#A8501E; }

  /* DIVIDER */
  .or-line { display:flex; align-items:center; gap:10px; margin:18px 0; font-size:12px; color:var(--dim); }
  .or-line::before, .or-line::after { content:''; flex:1; height:1px; background:var(--border); }

  /* DEMO QUICK FILL */
  .demo-title { font-size:10px; text-transform:uppercase; letter-spacing:.14em; color:var(--dim); font-weight:600; margin-bottom:8px; }
  .demo-grid  { display:grid; grid-template-columns:1fr 1fr; gap:6px; }
  .demo-item  { background:rgba(255,255,255,.03); border:1px solid rgba(196,154,60,.08); border-radius:8px; padding:9px 11px; cursor:pointer; transition:all .15s; }
  .demo-item:hover { border-color:rgba(196,154,60,.25); background:rgba(255,255,255,.05); }
  .demo-role  { font-size:10px; color:var(--dim); text-transform:uppercase; letter-spacing:.1em; }
  .demo-creds { font-size:12px; color:var(--muted); margin-top:3px; font-family:monospace; }

  /* PASSWORD WRAPPER */
  .pw-wrap       { position:relative; }
  .pw-wrap input { padding-right: 42px; }
  .pw-eye        { position:absolute; right:13px; top:50%; transform:translateY(-50%); background:none; border:none; cursor:pointer; color:var(--dim); padding:0; line-height:1; font-size:17px; }
  .pw-eye:hover  { color:var(--muted); }

  .switch-link { width:100%; background:transparent; border:1.5px solid var(--border); border-radius:11px; padding:11px; font-family:'DM Sans',sans-serif; font-size:13px; font-weight:600; color:var(--muted); cursor:pointer; transition:all .2s; margin-top:10px; }
  .switch-link:hover { border-color:var(--gold); color:var(--goldlt); }

  .footer { text-align:center; margin-top:18px; font-size:11px; color:var(--dim); }
</style>
</head>
<body>
<div class="wrap">

  <div class="logo-section">
    <div class="logo-name">Jiko House</div>
    <div class="logo-sub">Restaurant Management System</div>
    <div class="ornament">✦</div>
  </div>

  <div class="card">

    <!-- TABS -->
    <div class="tab-bar">
      <button class="tab-btn <?= $tab==='login'    ? 'active' : '' ?>" onclick="showTab('login')">Sign In</button>
      <button class="tab-btn <?= $tab==='register' ? 'active' : '' ?>" onclick="showTab('register')">Create Account</button>
    </div>

    <!-- ALERTS -->
    <?php if ($error):   ?><div class="alert alert-error">⚠ <?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success">✅ <?= htmlspecialchars($success) ?></div><?php endif; ?>

    <!-- ── LOGIN PANEL ────────────────────────────────────────────────────── -->
    <div class="panel <?= $tab==='login' ? 'active' : '' ?>" id="panel-login">
      <form method="POST" action="login.php" id="login-form">

        <!-- ROLE SELECTOR — user picks their role first -->
        <div class="role-section">
          <span class="role-label">I am signing in as</span>
          <div class="role-grid">

            <div class="role-opt">
              <input type="radio" name="role" id="role-admin" value="admin" <?= ($_POST['role']??'')==='admin'?'checked':'' ?>>
              <label for="role-admin">
                <span class="role-icon">👔</span>
                <span class="role-name">Admin</span>
                <span class="role-desc">Full access</span>
              </label>
            </div>

            <div class="role-opt">
              <input type="radio" name="role" id="role-cashier" value="cashier" <?= ($_POST['role']??'')==='cashier'?'checked':'' ?>>
              <label for="role-cashier">
                <span class="role-icon">💳</span>
                <span class="role-name">Cashier</span>
                <span class="role-desc">Payments & reports</span>
              </label>
            </div>

            <div class="role-opt">
              <input type="radio" name="role" id="role-waiter" value="waiter" <?= ($_POST['role']??'')==='waiter'?'checked':'' ?>>
              <label for="role-waiter">
                <span class="role-icon">🍽</span>
                <span class="role-name">Waiter</span>
                <span class="role-desc">Floor & orders</span>
              </label>
            </div>

            <div class="role-opt">
              <input type="radio" name="role" id="role-chef" value="chef" <?= ($_POST['role']??'')==='chef'?'checked':'' ?>>
              <label for="role-chef">
                <span class="role-icon">👨‍🍳</span>
                <span class="role-name">Chef</span>
                <span class="role-desc">Kitchen display</span>
              </label>
            </div>

            <div class="role-opt">
              <input type="radio" name="role" id="role-sous_chef" value="sous_chef" <?= ($_POST['role']??'')==='sous_chef'?'checked':'' ?>>
              <label for="role-sous_chef">
                <span class="role-icon">🧑‍🍳</span>
                <span class="role-name">Sous Chef</span>
                <span class="role-desc">Kitchen display</span>
              </label>
            </div>

            <div class="role-opt full">
              <input type="radio" name="role" id="role-customer" value="customer" <?= ($_POST['role']??'')==='customer'?'checked':'' ?>>
              <label for="role-customer">
                <span class="role-icon">🛒</span>
                <div>
                  <span class="role-name">Customer</span>
                  <span class="role-desc" style="display:block">Order food & view menu</span>
                </div>
              </label>
            </div>

          </div>
        </div>

        <!-- CREDENTIALS -->
        <div class="field">
          <label for="email">Email Address</label>
          <input type="email" id="email" name="email"
                 placeholder="you@example.com"
                 value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                 autocomplete="email" required>
        </div>
        <div class="field">
          <label for="password">Password</label>
          <div class="pw-wrap">
            <input type="password" id="password" name="password"
                   placeholder="••••••••"
                   autocomplete="current-password" required>
            <button type="button" class="pw-eye" onclick="togglePw('password',this)" title="Show/hide password">👁</button>
          </div>
        </div>

        <button type="submit" name="login_submit" class="submit-btn" id="sign-in-btn">Sign In →</button>
      </form>

      <button type="button" class="switch-link" onclick="showTab('register')">
        New customer? Create an account →
      </button>
    </div>

    <!-- ── REGISTER PANEL ──────────────────────────────────────────────────── -->
    <div class="panel <?= $tab==='register' ? 'active' : '' ?>" id="panel-register">
      <form method="POST" action="login.php">
        <div class="field">
          <label>Full Name *</label>
          <input type="text" name="name" placeholder="Jane Doe"
                 value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" required>
        </div>
        <div class="row-2">
          <div class="field">
            <label>Email *</label>
            <input type="email" name="reg_email" placeholder="you@example.com"
                   value="<?= htmlspecialchars($_POST['reg_email'] ?? '') ?>" required>
          </div>
          <div class="field">
            <label>Phone</label>
            <input type="tel" name="phone" placeholder="+254 7xx xxx xxx"
                   value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
          </div>
        </div>
        <div class="row-2">
          <div class="field">
            <label>Password * (min 6)</label>
            <div class="pw-wrap">
              <input type="password" id="reg_password" name="reg_password" placeholder="••••••••" required>
              <button type="button" class="pw-eye" onclick="togglePw('reg_password',this)" title="Show/hide password">👁</button>
            </div>
          </div>
          <div class="field">
            <label>Confirm Password *</label>
            <div class="pw-wrap">
              <input type="password" id="reg_confirm" name="reg_confirm" placeholder="••••••••" required>
              <button type="button" class="pw-eye" onclick="togglePw('reg_confirm',this)" title="Show/hide password">👁</button>
            </div>
          </div>
        </div>
        <button type="submit" name="register_submit" class="submit-btn">Create Account →</button>
      </form>

      <button type="button" class="switch-link" onclick="showTab('login')">
        Already have an account? Sign in →
      </button>
    </div>

  </div><!-- /.card -->

  <div class="footer">Jiko House RMS v1.0 · Secure Portal</div>
</div>

<script>
function showTab(tab) {
  document.querySelectorAll('.tab-btn').forEach((b, i) => {
    b.classList.toggle('active', (i === 0 && tab === 'login') || (i === 1 && tab === 'register'));
  });
  document.getElementById('panel-login').classList.toggle('active', tab === 'login');
  document.getElementById('panel-register').classList.toggle('active', tab === 'register');
}

// Toggle password visibility
function togglePw(fieldId, btn) {
  const input = document.getElementById(fieldId);
  if (!input) return;
  const isHidden = input.type === 'password';
  input.type = isHidden ? 'text' : 'password';
  btn.textContent = isHidden ? '🙈' : '👁';
}

// Fill credentials AND select the matching role card
function fillDemo(email, pass, role) {
  document.getElementById('email').value    = email;
  document.getElementById('password').value = pass;
  // Check the right radio button
  const radio = document.getElementById('role-' + role);
  if (radio) radio.checked = true;
  updateBtn(role);
}

// Update button text when role is selected
document.querySelectorAll('input[name="role"]').forEach(r => {
  r.addEventListener('change', () => updateBtn(r.value));
});
function updateBtn(role) {
  const labels = {
    admin:     'Sign In as Admin →',
    cashier:   'Sign In as Cashier →',
    waiter:    'Sign In as Waiter →',
    chef:      'Sign In as Chef →',
    sous_chef: 'Sign In as Sous Chef →',
    customer:  'Sign In & Order Food →',
  };
  document.getElementById('sign-in-btn').textContent = labels[role] || 'Sign In →';
}
</script>
</body>
</html>