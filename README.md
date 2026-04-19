
# 🍽️ Jiko House — Restaurant Management System

**Full-stack PHP & MySQL web application for restaurant operations, inventory, and staff management.**

![PHP](https://img.shields.io/badge/PHP-7.4%2B-777BB4?style=flat-square&logo=php)
![MySQL](https://img.shields.io/badge/MySQL-5.7%2B-336791?style=flat-square&logo=mysql)
![License](https://img.shields.io/badge/License-MIT-green?style=flat-square)

---

## 📋 Overview

Jiko House is a modular, role-based restaurant management system built with **PHP 95.6%** and **CSS 4.4%**. It eliminates paper-based workflows and provides real-time visibility across front-of-house, kitchen, and management.

### Problems It Solves
|Problem | Solution|
|-----------|-----------|
- |**Manual order errors** | Digitized customer & waiter workflows|
- | **Slow kitchen communication** | Real-time Kitchen Display System (KDS)|
- | **Inventory chaos** | Automated stock tracking with alerts|
- | **Payment delays** | Multi-method checkout (Cash, Card, M-Pesa)|
- | **No visibility** | Analytics dashboard with reports|
-  |**Customer inconvenience** | Online ordering & reservations|

### ✨ Key Features
- 🔐 **Unified login** with 5 role-based dashboards
- 🛒 **Customer portal** — menu browsing, cart, checkout
- 🧾 **Waiter POS** — table management, order entry, billing
- 👨‍🍳 **Kitchen Display System** — real-time order queue
- 📊 **Admin dashboard** — analytics, staff, inventory, reports
- 💰 **Payment integration** — Cash, Card, M-Pesa ready
- 📅 **Reservations** — table bookings with notes
- 📦 **Inventory mgmt** — stock levels, supplier tracking, low-stock alerts

---

## 🛠️ Tech Stack

| Component | Technology |
|-----------|-----------|
| **Backend** | PHP 7.4+ (8.x recommended) |
| **Database** | MySQL 5.7+ / MariaDB 10.3+ |
| **Frontend** | HTML5, CSS3, Vanilla JavaScript |
| **Server** | Apache/Nginx with mod_rewrite |
| **Extensions** | PDO, PDO_MySQL, mbstring, json |

---

## 📁 File Structure

```
.
├── admin_dashboard.php       # Admin home with KPIs
├── menu_management.php       # Menu CRUD (items, emojis, badges)
├── categories.php            # Menu category management
├── inventory.php             # Stock tracking & alerts
├── staff.php                 # User/role management
├── payments.php              # Payment recording
├── reports.php               # Analytics & revenue
├── cart.php                  # Pending customer carts
├── OrderController.php       # Order management
├── reservations.php          # Table bookings
├── kitchen_display.php       # KDS for chefs
├── 
├── cart.php                  # Customer shopping cart
├── login.php                 # Authentication
├── index.php                 # Router (redirects by role)
├── logout.php                # Session destroyer
├── dbconfig.php              # Database config
├── setup.sql                 # Schema & seed data
├── _sidebar.php              # Navigation component
├── _styles.css               # Shared stylesheet
└── README.md                 # This file
```

---

## 🚀 Installation & Setup

### Prerequisites
```bash
✓ PHP 7.4+ enabled
✓ MySQL/MariaDB running
✓ Web server (Apache/Nginx)
✓ phpMyAdmin access (for database import)
```

### Step 1: Copy Files
```bash
# For local development (Apache)
cp -r Restaurant-Management-System/ /var/www/html/jiko/

# For InfinityFree hosting
# Upload all files via FTP to htdocs/rms/
```

### Step 2: Configure Database Connection
Edit `dbconfig.php`:
```php
define('DB_HOST',    'localhost');        // or sql311.infinityfree.com
define('DB_PORT',    '3306');
define('DB_NAME',    'restaurant_db');    // your database name
define('DB_USER',    'root');             // your DB user
define('DB_PASS',    '');                 // your password
define('DB_CHARSET', 'utf8mb4');
```

### Step 3: Create & Populate Database
1. **In phpMyAdmin:**
   - Create database: `restaurant_db`
   - Select database → Import tab
   - Choose `setup.sql` → Execute

2. **Or via MySQL CLI:**
   ```bash
   mysql -u root -p restaurant_db < setup.sql
   ```

### Step 4: Access System
```
http://localhost/jiko/login.php
```

---

## 🗄️ Database Schema

**10 Core Tables:**

| Table | Purpose |
|-------|---------|
| `users` | Staff (admin/waiter/chef/cashier) & customers |
| `categories` | Menu categories (Starters, Mains, etc.) |
| `menu` | Food & drink items with prices & emojis |
| `table_layout` | Restaurant tables (number, capacity, status) |
| `reservations` | Guest bookings with date/time |
| `orders` | Customer orders (status: pending→served) |
| `order_items` | Line items within each order |
| `cart` | Temporary customer shopping cart |
| `payments` | Payment transactions linked to orders |
| `inventory` | Stock items with suppliers & thresholds |

**Key Relationships:**
- `orders` → `users` (who ordered)
- `orders` → `table_layout` (which table)
- `order_items` → `menu` (what was ordered)
- `reservations` → `table_layout` (which table)
- `payments` → `orders` (payment for order)

---

## 👥 User Roles & Permissions

| Role | Dashboard | Key Functions | Redirects To |
|------|-----------|---------------|--------------|
| **Customer** | Menu portal | Browse, add to cart, checkout, reserve table | `customer_menu.php` |
| **Waiter** | POS terminal | Assign table, enter orders, send to kitchen, bill | `waiter_pos.php` |
| **Chef** | Kitchen display | View pending orders, mark cooking/ready | `kitchen_display.php` |
| **Sous Chef** | Kitchen display | Same as Chef | `kitchen_display.php` |
| **Cashier** | Admin panel | Process payments, view reports, manage inventory | `admin_dashboard.php` |
| **Admin** | Admin panel | Full system access (users, menu, reports, settings) | `admin_dashboard.php` |

---

## 🎯 Use Cases

- 🍔 **Restaurants & Cafés** — Streamline orders, kitchen communication, payments
- 🏫 **School/College Cafeterias** — Meal plans, inventory, student billing
- 🛒 **Shops & Kiosks** — POS, stock tracking, sales analytics
- 🎉 **Event Catering** — Reservations, staff coordination, invoicing

---

## 📖 API Reference

### Core Functions (in `admin_dashboard.php`)

#### `val($pdo, $sql, $p = [])`
Fetch single value from database.
```php
$count = val($pdo, "SELECT COUNT(*) FROM orders");
```

#### `rows($pdo, $sql, $p = [])`
Fetch multiple rows.
```php
$orders = rows($pdo, "SELECT * FROM orders WHERE status=?", ['pending']);
```

### Common Workflows

**Create Order:**
```php
$pdo->prepare("INSERT INTO orders (user_id, table_id, status, total_price) VALUES (?,?,'pending',?)")
    ->execute([$user_id, $table_id, $total]);
```

**Add Order Item:**
```php
$pdo->prepare("INSERT INTO order_items (order_id, menu_item_id, name, price, quantity) VALUES (?,?,?,?,?)")
    ->execute([$order_id, $item_id, $item_name, $price, $qty]);
```

**Update Order Status:**
```php
$pdo->prepare("UPDATE orders SET status=?, updated_at=NOW() WHERE id=?")
    ->execute(['ready', $order_id]);
```

---

## 🔒 Security Checklist

Before deploying to production:

- [ ] Change all default passwords in `staff.php`
- [ ] Move `dbconfig.php` outside web root (if possible)
- [ ] Use strong passwords for DB credentials
- [ ] Enable HTTPS on web server
- [ ] Set file permissions: `chmod 644 *.php`, `chmod 600 dbconfig.php`
- [ ] Remove demo data (optional)
- [ ] Enable error logging, disable error display
- [ ] Use `password_hash()` for all new accounts
- [ ] Implement rate limiting on login page
- [ ] Regular backups of database

---

## 🐛 Troubleshooting

| Issue | Solution |
|-------|----------|
| **Login fails** | Check `dbconfig.php` credentials; verify MySQL running |
| **404 errors** | Ensure files in correct directory; check Apache `mod_rewrite` |
| **Orders not in kitchen** | Check `status = 'pending'` in DB; refresh KDS page |
| **Emojis display as ?** | Set charset to `utf8mb4` in MySQL; reload page |
| **Inventory alerts missing** | Import `setup.sql` completely; set min_threshold values |
| **Cart not updating** | Check session is active; clear browser cache |

---

## 🚀 Future Enhancements

- [ ] **M-Pesa Daraja API** — Live payment callbacks
- [ ] **SMS/Email Notifications** — Reservation & order updates
- [ ] **Staff Analytics** — Performance tracking, shift reports
- [ ] **Multi-Branch** — Support for restaurant chains
- [ ] **Mobile App** — Native iOS/Android (React Native)
- [ ] **AI Recommendations** — Suggest items based on history
- [ ] **Receipt Printing** — Thermal printer integration

---

## 📞 Support & Contributing

For issues, suggestions, or contributions:
1. Open a **GitHub Issue** with detailed description
2. Include error logs and steps to reproduce
3. Submit a **Pull Request** for bug fixes or features

-

## 👨‍💻 Author

**rahabwairimu008-crypto**  
[GitHub Profile](https://github.com/rahabwairimu008-crypto)

---

## 🏆 Acknowledgments

- Built with PHP & MySQL from scratch
- Inspired by real restaurant workflows
- Special thanks to the open-source community

---

