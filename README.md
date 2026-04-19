Jiko House — Restaurant Management System  
### Full-stack PHP & MySQL Web Application

---

## 📌 Overview
This system is a modular restaurant and shop management application built with **PHP** and **MySQL**. It supports multiple user roles (Admin, Waiter, Chef, Cashier, Customer) and provides workflows for menu browsing, order placement, kitchen display, payments, and reporting.  

**Problems It Solves:**
- **Manual order errors**: Eliminates paper-based ordering by digitizing customer and waiter workflows.  
- **Slow kitchen communication**: Provides a real-time Kitchen Display System (KDS) so chefs see orders instantly.  
- **Inventory mismanagement**: Tracks stock levels, thresholds, and alerts admins when items run low.  
- **Payment delays**: Integrates multiple payment methods (Cash, Card, M-Pesa) for faster checkout.  
- **Limited visibility for management**: Offers an admin dashboard with revenue analytics, staff management, and reports.  
- **Customer inconvenience**: Enables online menu browsing, cart checkout, and reservation booking.  

**Key Features:**
- Unified login with role-based dashboards  
- Customer online ordering with cart and checkout  
- Waiter POS with table layout and billing  
- Kitchen Display System (KDS) for real-time order tracking  
- Admin dashboard with analytics, staff management, inventory, and reports  
- Integrated payment options (Cash, Card, M-Pesa API ready)  

---

## ⚙️ System Requirements
| Requirement | Minimum |
|-------------|----------|
| PHP         | 7.4+ (8.x recommended) |
| MySQL       | 5.7+ (MariaDB 10.3+ supported) |
| Web Server  | Apache/Nginx with mod_rewrite |
| Extensions  | PDO, PDO_MySQL, mbstring, json |
| Browser     | Chrome, Firefox, Edge |

---

## 🚀 Installation & Setup
1. **Copy Files**  
   Place all project files inside your web server root (`htdocs/rms/` for InfinityFree or `htdocs/jiko/` locally).

2. **Configure Database**  
   Edit `dbconfig.php` with your hosting credentials:
   ```php
   define('DB_HOST', 'sql311.infinityfree.com');
   define('DB_NAME', 'if0_41701018_restaurant_db');
   define('DB_USER', 'if0_41701018');
   define('DB_PASS', 'your_password_here');
   ```

3. **Create Database**  
   In phpMyAdmin:  
   - Create a database (e.g., `restaurant_db`).  
   - Import `setup.sql` to create tables and seed demo data.  
   - Import `inventory_items.sql` to load sample inventory.

4. **Reset Demo Passwords**  
   Visit `reset_passwords.php` once to set secure hashes.  
   Delete the file immediately after use.

5. **Open System**  
   Access via browser:  
   ```
   http://yourdomain.com/rms/login.php
   ```

---

## 🗄️ Database Schema
The system uses 10 tables:
- `users` — staff & customers with role separation  
- `categories` — menu categories  
- `menu` — food & drink items  
- `table_layout` — restaurant tables  
- `reservations` — bookings  
- `orders` — customer/waiter orders  
- `order_items` — line items per order  
- `cart` — temporary customer cart  
- `payments` — linked to orders  
- `inventory` — stock levels  

---

## 👥 User Roles
| Role      | Redirects To         | Access |
|-----------|----------------------|--------|
| Customer  | `customer_menu.php`  | Menu, cart, checkout |
| Waiter    | `waiter_pos.php`     | POS, send orders, billing |
| Chef      | `kitchen_display.php`| Order queue management |
| Cashier   | `admin_dashboard.php`| Payments, reports |
| Admin     | `admin_dashboard.php`| Full access |

---
## Use Cases
**Restaurants & Cafés** — streamline orders, payments, and kitchen communication.

**School/College Cafeterias** — manage student meal plans and inventory.

**Shops & Hubs** — track stock, sales, and customer accounts.

**Event Catering Services** — handle reservations, billing, and staff coordination.
-----
## Testing Workflow
**Customer** → Register/login, browse menu, add to cart, checkout.

**Waiter** → Assign table, place order, send to kitchen, process bill.

**Chef** → View pending orders, mark cooking/ready, notify waiter.

**Cashier** → Confirm payment, record transaction, generate receipt.

**Admin** → Monitor dashboard, view reports, manage staff, track inventory.

---

## Troubleshooting
- **Login fails** → Run `reset_passwords.php` and check `dbconfig.php`.  
- **404 error** → Ensure files are inside `htdocs/rms/` and URL path matches.  
- **Orders not showing in kitchen** → Confirm `status = 'pending'` in `orders`.  
- **Inventory alerts missing** → Import `inventory_items.sql` and set thresholds.  

---

## 🔒 Security Checklist
- Delete `reset_passwords.php` after first use.  
- Change all demo passwords via `staff.php`.  
- Use `password_hash()` for new accounts.  
- Enable HTTPS if deploying live.  
- Restrict database credentials and move `dbconfig.php` outside web root if possible.  

---
## Future Enhancements
Full M‑Pesa Daraja API integration with live callbacks.

SMS/email notifications for reservations and order updates.

Role‑based analytics (e.g., staff performance tracking).

Multi‑branch support for chain restaurants.



----
 
