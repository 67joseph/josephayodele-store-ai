-- Store-AI schema (MySQL 5.7+/8 or MariaDB 10.3+)
-- Import into an EXISTING, SELECTED database (phpMyAdmin: select your DB, then Import / SQL tab).
-- Safe to re-run: tables use IF NOT EXISTS and the category seed uses INSERT IGNORE.
-- Local dev only: CREATE DATABASE store_ai CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; USE store_ai;

-- ───────────── Users & Stores ─────────────
CREATE TABLE IF NOT EXISTS users (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(120) NOT NULL,
  email         VARCHAR(190) NOT NULL UNIQUE,
  phone         VARCHAR(30),
  password_hash VARCHAR(255) NOT NULL,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS store_types (
  id    SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name  VARCHAR(80) NOT NULL UNIQUE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS stores (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id       BIGINT UNSIGNED NOT NULL,
  store_type_id SMALLINT UNSIGNED NOT NULL,
  name          VARCHAR(150) NOT NULL,
  currency      CHAR(3) NOT NULL DEFAULT 'NGN',
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (store_type_id) REFERENCES store_types(id)
) ENGINE=InnoDB;

-- ───────────── Products & Inventory ─────────────
CREATE TABLE IF NOT EXISTS products (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  store_id      BIGINT UNSIGNED NOT NULL,
  name          VARCHAR(190) NOT NULL,
  sku           VARCHAR(64),
  cost_price    DECIMAL(14,2) NOT NULL DEFAULT 0,
  sale_price    DECIMAL(14,2) NOT NULL DEFAULT 0,
  quantity      INT NOT NULL DEFAULT 0,
  reorder_level INT NOT NULL DEFAULT 0,
  image_path    VARCHAR(255),
  is_active     TINYINT(1) NOT NULL DEFAULT 1,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_store_sku (store_id, sku),
  KEY idx_store_name (store_id, name),
  FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS stock_movements (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  store_id    BIGINT UNSIGNED NOT NULL,
  product_id  BIGINT UNSIGNED NOT NULL,
  change_qty  INT NOT NULL,
  reason      ENUM('initial','restock','sale','adjustment','return','ai_upload') NOT NULL,
  ref_id      BIGINT UNSIGNED NULL,
  note        VARCHAR(255),
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_product (product_id, created_at),
  FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ───────────── AI image uploads ─────────────
CREATE TABLE IF NOT EXISTS ai_uploads (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  store_id     BIGINT UNSIGNED NOT NULL,
  image_path   VARCHAR(255) NOT NULL,
  status       ENUM('pending','processed','confirmed','failed') NOT NULL DEFAULT 'pending',
  raw_response JSON NULL,
  created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ai_upload_items (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  upload_id      BIGINT UNSIGNED NOT NULL,
  suggested_name VARCHAR(190) NOT NULL,
  suggested_qty  INT NOT NULL DEFAULT 1,
  final_name     VARCHAR(190),
  final_qty      INT,
  cost_price     DECIMAL(14,2),
  sale_price     DECIMAL(14,2),
  product_id     BIGINT UNSIGNED NULL,
  FOREIGN KEY (upload_id) REFERENCES ai_uploads(id) ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ───────────── Sales ─────────────
CREATE TABLE IF NOT EXISTS sales (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  store_id       BIGINT UNSIGNED NOT NULL,
  customer_name  VARCHAR(150),
  total_amount   DECIMAL(14,2) NOT NULL DEFAULT 0,
  total_cost     DECIMAL(14,2) NOT NULL DEFAULT 0,
  profit         DECIMAL(14,2) NOT NULL DEFAULT 0,
  amount_paid    DECIMAL(14,2) NOT NULL DEFAULT 0,
  payment_mode   ENUM('cash','card','transfer','credit') NOT NULL DEFAULT 'cash',
  sold_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_store_date (store_id, sold_at),
  FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sale_items (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sale_id     BIGINT UNSIGNED NOT NULL,
  product_id  BIGINT UNSIGNED NOT NULL,
  quantity    INT NOT NULL,
  unit_cost   DECIMAL(14,2) NOT NULL,
  unit_price  DECIMAL(14,2) NOT NULL,
  line_total  DECIMAL(14,2) GENERATED ALWAYS AS (quantity * unit_price) STORED,
  line_profit DECIMAL(14,2) GENERATED ALWAYS AS (quantity * (unit_price - unit_cost)) STORED,
  FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB;

-- ───────────── Debts (both directions) ─────────────
CREATE TABLE IF NOT EXISTS debts (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  store_id      BIGINT UNSIGNED NOT NULL,
  direction     ENUM('owed_to_me','i_owe') NOT NULL,
  party_name    VARCHAR(150) NOT NULL,
  party_phone   VARCHAR(30),
  amount        DECIMAL(14,2) NOT NULL,
  amount_paid   DECIMAL(14,2) NOT NULL DEFAULT 0,
  due_date      DATE NULL,
  sale_id       BIGINT UNSIGNED NULL,
  note          VARCHAR(255),
  status        ENUM('open','partial','settled') NOT NULL DEFAULT 'open',
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_store_status (store_id, direction, status),
  FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE CASCADE,
  FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS debt_payments (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  debt_id       BIGINT UNSIGNED NOT NULL,
  amount        DECIMAL(14,2) NOT NULL,
  payment_mode  ENUM('cash','card','transfer') NOT NULL DEFAULT 'cash',
  paid_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (debt_id) REFERENCES debts(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ───────────── Expenses ─────────────
CREATE TABLE IF NOT EXISTS expenses (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  store_id      BIGINT UNSIGNED NOT NULL,
  category      VARCHAR(80) NOT NULL,
  amount        DECIMAL(14,2) NOT NULL,
  note          VARCHAR(255),
  spent_at      DATE NOT NULL,
  payment_mode  ENUM('cash','card','transfer') NOT NULL DEFAULT 'cash',
  FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ───────────── AI report chat ─────────────
CREATE TABLE IF NOT EXISTS chat_sessions (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  store_id    BIGINT UNSIGNED NOT NULL,
  title       VARCHAR(150),
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS chat_messages (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  session_id  BIGINT UNSIGNED NOT NULL,
  role        ENUM('user','assistant') NOT NULL,
  content     TEXT NOT NULL,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (session_id) REFERENCES chat_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ───────────── WhatsApp ─────────────
-- A WhatsApp number linked to a store (several staff numbers can share one store)
CREATE TABLE IF NOT EXISTS whatsapp_links (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id         BIGINT UNSIGNED NOT NULL,
  store_id        BIGINT UNSIGNED NOT NULL,
  wa_number       VARCHAR(20) NOT NULL UNIQUE,
  chat_session_id BIGINT UNSIGNED NULL,
  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- One-time codes the user sends to the bot ("LINK 123456") to connect their number
CREATE TABLE IF NOT EXISTS whatsapp_link_codes (
  code        CHAR(6) PRIMARY KEY,
  user_id     BIGINT UNSIGNED NOT NULL,
  store_id    BIGINT UNSIGNED NOT NULL,
  expires_at  DATETIME NOT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Action waiting for the user's YES / NO
CREATE TABLE IF NOT EXISTS whatsapp_pending (
  wa_number   VARCHAR(20) PRIMARY KEY,
  payload     TEXT NOT NULL,
  expires_at  DATETIME NOT NULL
) ENGINE=InnoDB;

-- Processed inbound message ids (WhatsApp retries deliveries) + simple rate limiting
CREATE TABLE IF NOT EXISTS whatsapp_inbox (
  wamid       VARCHAR(120) PRIMARY KEY,
  wa_number   VARCHAR(20) NOT NULL,
  created_at  DATETIME NOT NULL,
  KEY idx_number_time (wa_number, created_at)
) ENGINE=InnoDB;

-- ───────────── Seed store types ─────────────
INSERT IGNORE INTO store_types (name) VALUES
 ('Provisions / Grocery'),('Supermarket'),('Pharmacy'),('Fashion & Clothing'),
 ('Electronics & Phones'),('Beauty & Cosmetics'),('Building Materials'),
 ('Stationery & Books'),('Food & Beverages'),('Other');
