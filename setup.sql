-- ============================================================
--  JIKO HOUSE RESTAURANT MANAGEMENT SYSTEM
--  Full Database Setup for: restaurant_db
--  Run this entire file in phpMyAdmin or MySQL CLI
--  Command: mysql -u root -p restaurant_db < setup.sql
-- ============================================================

-- Create & select database
CREATE DATABASE IF NOT EXISTS restaurant_db
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE restaurant_db;

-- ============================================================
--  DROP ALL TABLES (clean slate)
-- ============================================================
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS payments;
DROP TABLE IF EXISTS order_items;
DROP TABLE IF EXISTS orders;
DROP TABLE IF EXISTS cart;
DROP TABLE IF EXISTS reservations;
DROP TABLE IF EXISTS menu;
DROP TABLE IF EXISTS categories;
DROP TABLE IF EXISTS table_layout;
DROP TABLE IF EXISTS inventory;
DROP TABLE IF EXISTS users;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
--  TABLE: users
--  Stores both staff (admin/waiter/chef etc.) and customers
-- ============================================================
CREATE TABLE users (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(120)    NOT NULL,
    email         VARCHAR(120)    NOT NULL UNIQUE,
    phone         VARCHAR(30)     DEFAULT NULL,
    password_hash VARCHAR(255)    NOT NULL,
    role          ENUM('customer','admin','waiter','chef','sous_chef','cashier')
                                  NOT NULL DEFAULT 'customer',
    status        ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at    TIMESTAMP       DEFAULT CURRENT_TIMESTAMP
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ============================================================
--  TABLE: categories
--  Menu categories (Starters, Mains, Drinks etc.)
-- ============================================================
CREATE TABLE categories (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(80)  NOT NULL,
    sort_order  INT          NOT NULL DEFAULT 0,
    active      TINYINT(1)   NOT NULL DEFAULT 1,
    created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ============================================================
--  TABLE: menu
--  All food and drink items
-- ============================================================
CREATE TABLE menu (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    category_id INT             DEFAULT NULL,
    name        VARCHAR(120)    NOT NULL,
    description TEXT            DEFAULT NULL,
    price       DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
    emoji       VARCHAR(20)     CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT '🍽',
    badge       VARCHAR(40)     DEFAULT NULL,
    available   TINYINT(1)      NOT NULL DEFAULT 1,
    spicy       TINYINT(1)      NOT NULL DEFAULT 0,
    created_at  TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP       DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ============================================================
--  TABLE: table_layout
--  Physical restaurant tables
-- ============================================================
CREATE TABLE table_layout (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    table_number INT  NOT NULL UNIQUE,
    capacity     INT  NOT NULL DEFAULT 4,
    status       ENUM('available','occupied','reserved','maintenance')
                      NOT NULL DEFAULT 'available',
    updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ============================================================
--  TABLE: reservations
--  Table bookings
-- ============================================================
CREATE TABLE reservations (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    table_id         INT          DEFAULT NULL,
    guest_name       VARCHAR(120) NOT NULL,
    guest_phone      VARCHAR(30)  DEFAULT NULL,
    guest_email      VARCHAR(120) DEFAULT NULL,
    party_size       INT          NOT NULL DEFAULT 1,
    reservation_date DATE         NOT NULL,
    reservation_time TIME         NOT NULL,
    notes            TEXT         DEFAULT NULL,
    status           ENUM('confirmed','seated','cancelled','no_show')
                                  NOT NULL DEFAULT 'confirmed',
    created_at       TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (table_id) REFERENCES table_layout(id) ON DELETE SET NULL
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ============================================================
--  TABLE: orders
--  Customer orders (from waiter POS or customer app)
-- ============================================================
CREATE TABLE orders (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    user_id      INT             DEFAULT NULL,
    table_id     INT             DEFAULT NULL,
    waiter_name  VARCHAR(80)     DEFAULT NULL,
    guests       INT             NOT NULL DEFAULT 1,
    status       ENUM('pending','cooking','ready','served','paid','cancelled')
                                 NOT NULL DEFAULT 'pending',
    notes        TEXT            DEFAULT NULL,
    total_price  DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
    created_at   TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP       DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id)  REFERENCES users(id)         ON DELETE SET NULL,
    FOREIGN KEY (table_id) REFERENCES table_layout(id)  ON DELETE SET NULL
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ============================================================
--  TABLE: order_items
--  Individual line items inside each order
-- ============================================================
CREATE TABLE order_items (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    order_id     INT             NOT NULL,
    menu_item_id INT             NOT NULL,
    name         VARCHAR(120)    NOT NULL,
    price        DECIMAL(10,2)   NOT NULL,
    quantity     INT             NOT NULL DEFAULT 1,
    note         VARCHAR(255)    DEFAULT NULL,
    FOREIGN KEY (order_id)     REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (menu_item_id) REFERENCES menu(id)   ON DELETE RESTRICT
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ============================================================
--  TABLE: cart
--  Temporary cart for customer online ordering
-- ============================================================
CREATE TABLE cart (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    user_id      INT             NOT NULL,
    menu_item_id INT             NOT NULL,
    name         VARCHAR(120)    NOT NULL,
    price        DECIMAL(10,2)   NOT NULL,
    quantity     INT             NOT NULL DEFAULT 1,
    created_at   TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id)      REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (menu_item_id) REFERENCES menu(id)  ON DELETE CASCADE
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ============================================================
--  TABLE: payments
--  Payment records linked to orders
-- ============================================================
CREATE TABLE payments (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    order_id     INT             NOT NULL,
    method       ENUM('cash','mpesa','card','other') NOT NULL DEFAULT 'cash',
    amount       DECIMAL(10,2)   NOT NULL,
    reference    VARCHAR(120)    DEFAULT NULL,
    status       ENUM('pending','completed','failed','refunded')
                                 NOT NULL DEFAULT 'pending',
    processed_by VARCHAR(80)     DEFAULT NULL,
    created_at   TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ============================================================
--  TABLE: inventory
--  Kitchen stock levels
-- ============================================================
CREATE TABLE inventory (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    name           VARCHAR(120)   NOT NULL,
    unit           VARCHAR(30)    NOT NULL DEFAULT 'kg',
    quantity       DECIMAL(10,3)  NOT NULL DEFAULT 0.000,
    min_threshold  DECIMAL(10,3)  NOT NULL DEFAULT 1.000,
    cost_per_unit  DECIMAL(10,2)  NOT NULL DEFAULT 0.00,
    supplier       VARCHAR(120)   DEFAULT NULL,
    updated_at     TIMESTAMP      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;


-- ============================================================
--  SEED DATA
-- ============================================================

-- ── USERS ─────────────────────────────────────────────────
-- No default users. Register your own account via the login page.
-- The first admin account must be created directly in phpMyAdmin:
--
--   INSERT INTO users (name, email, password_hash, role, status)
--   VALUES ('Your Name', 'you@email.com', '<hash>', 'admin', 'active');
--
-- Generate a hash with: php -r "echo password_hash('yourpassword', PASSWORD_DEFAULT);"


-- ── CATEGORIES ────────────────────────────────────────────
INSERT INTO categories (name, sort_order, active) VALUES
('Starters',  1, 1),
('Mains',     2, 1),
('Sides',     3, 1),
('Drinks',    4, 1),
('Desserts',  5, 1);

-- ── MENU ITEMS ────────────────────────────────────────────
INSERT INTO menu (category_id, name, description, price, emoji, badge, available, spicy) VALUES
-- Starters (cat 1)
(1, 'Coastal Mandazi',    'Fluffy coconut-spiced doughnuts served with tamarind dip',       150.00, '🥐', 'Chef''s Pick', 1, 0),
(1, 'Beef Samosa',        'Crispy pastry filled with spiced minced beef & onions (3 pcs)', 120.00, '🥟', NULL,           1, 0),
(1, 'Oxtail Soup',        'Slow-simmered oxtail with root vegetables and herbs',            200.00, '🍲', 'New',          1, 1),
(1, 'Chicken Wings',      'Marinated wings grilled with smoky peri-peri sauce (6 pcs)',     280.00, '🍗', NULL,           1, 1),
-- Mains (cat 2)
(2, 'Nyama Choma',        'Slow-roasted goat ribs, charcoal-grilled to order',              850.00, '🥩', 'Chef''s Pick', 1, 0),
(2, 'Ugali & Sukuma Set', 'Stone-ground ugali with braised sukuma wiki and tilapia',        450.00, '🍱', NULL,           1, 0),
(2, 'Coastal Biriani',    'Fragrant pilau rice layered with tender mutton & raita',         650.00, '🍛', 'New',          1, 1),
(2, 'Tilapia Fry',        'Whole tilapia deep-fried, served with ugali and kachumbari',     550.00, '🐟', NULL,           1, 0),
(2, 'Matumbo Stew',       'Slow-cooked tripe in tomato & herb sauce with chapati',          380.00, '🍲', NULL,           1, 0),
-- Sides (cat 3)
(3, 'Steamed Rice',       'Plain long-grain steamed rice',                                  80.00,  '🍚', NULL,           1, 0),
(3, 'Chapati (2 pcs)',    'Soft layered wholewheat flatbread',                              60.00,  '🫓', NULL,           1, 0),
(3, 'Kachumbari',         'Fresh tomato, onion and coriander salad',                        50.00,  '🥗', NULL,           1, 0),
-- Drinks (cat 4)
(4, 'Masala Chai',        'Cardamom, ginger & cinnamon brewed with whole milk',             80.00,  '☕', NULL,           1, 0),
(4, 'Fresh Passion Juice','Cold-pressed passion fruit, lightly sweetened',                  120.00, '🥭', NULL,           1, 0),
(4, 'Dawa Cocktail',      'Kenyan classic — vodka, lime, honey & ginger',                  280.00, '🍹', 'Popular',      1, 0),
(4, 'Fresh Mango Juice',  'Hand-blended mango, served chilled',                             100.00, '🥤', NULL,           1, 0),
(4, 'Mineral Water',      'Still or sparkling, 500ml',                                      60.00,  '💧', NULL,           1, 0),
-- Desserts (cat 5)
(5, 'Coconut Ice Cream',  'House-made coconut ice cream with a hint of vanilla',            180.00, '🍨', NULL,           1, 0),
(5, 'Mahamri & Honey',    'Sweet fried dough served warm with wild honey',                  120.00, '🍯', NULL,           1, 0);

-- ── TABLE LAYOUT ──────────────────────────────────────────
INSERT INTO table_layout (table_number, capacity, status) VALUES
(1, 4, 'available'),
(2, 4, 'available'),
(3, 2, 'available'),
(4, 6, 'available'),
(5, 4, 'available'),
(6, 2, 'available'),
(7, 2, 'available'),
(8, 8, 'available');

-- ── INVENTORY ─────────────────────────────────────────────
INSERT INTO inventory (name, unit, quantity, min_threshold, cost_per_unit, supplier) VALUES
('Goat Meat',       'kg',    18.500,  5.000, 850.00, 'Kariuki Butchery'),
('Tilapia Fish',    'kg',    12.000,  4.000, 400.00, 'Nairobi Fish Market'),
('Rice (Basmati)',  'kg',    32.000, 10.000, 120.00, 'Naivas Wholesale'),
('Unga (Maize)',    'kg',    25.000,  8.000,  60.00, 'Unga Limited'),
('Sukuma Wiki',     'bunch',  4.000,  8.000,  20.00, 'Wakulima Market'),
('Cooking Oil',     'L',      2.500,  3.000, 180.00, 'Bidco Distributors'),
('Passion Fruit',   'kg',     9.000,  3.000,  60.00, 'Wakulima Market'),
('Mangoes',         'kg',     6.000,  2.000,  80.00, 'Wakulima Market'),
('Whole Milk',      'L',     20.000,  5.000,  70.00, 'Brookside Dairy'),
('Wheat Flour',     'kg',    15.000,  4.000,  80.00, 'Unga Limited'),
('Coconut Milk',    'L',      8.000,  2.000,  90.00, 'Naivas Wholesale'),
('Cardamom Pods',   'g',    180.000, 50.000,   0.50, 'Spice World'),
('Fresh Ginger',    'kg',     2.500,  1.000, 120.00, 'Wakulima Market'),
('Tomatoes',        'kg',     3.500,  4.000,  50.00, 'Wakulima Market'),
('Onions',          'kg',    10.000,  3.000,  40.00, 'Wakulima Market'),
('Charcoal',        'kg',    40.000, 10.000,  30.00, 'Local Supplier'),
('LPG Gas',         'kg',    12.000,  5.000, 200.00, 'Total Energies');

-- ── SAMPLE RESERVATION ────────────────────────────────────
INSERT INTO reservations (table_id, guest_name, guest_phone, party_size, reservation_date, reservation_time, notes, status) VALUES
(4, 'James Mwangi', '+254712345678', 6, CURDATE(), '13:00:00', 'Anniversary dinner', 'confirmed'),
(2, 'Sarah Otieno',  '+254720987654', 3, CURDATE(), '14:30:00', 'Window seat please', 'confirmed');

-- ============================================================
--  VERIFY SETUP
-- ============================================================
SELECT 'SETUP COMPLETE' AS status;
SELECT TABLE_NAME, TABLE_ROWS
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = 'restaurant_db'
ORDER BY TABLE_NAME;
