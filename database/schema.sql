-- SERP UPI schema. The installer creates this database as serp_secure_upi.
CREATE DATABASE IF NOT EXISTS serp_secure_upi CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE serp_secure_upi;

CREATE TABLE users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  full_name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL,
  mobile CHAR(10) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  pin_hash VARCHAR(255) NOT NULL DEFAULT '',
  wallet_balance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  pin_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  pin_locked_until DATETIME NULL DEFAULT NULL,
  payer_vpa VARCHAR(120) NOT NULL DEFAULT '',
  role ENUM('user', 'admin') NOT NULL DEFAULT 'user',
  status ENUM('active', 'suspended') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL,
  last_login_at DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email),
  UNIQUE KEY uq_users_mobile (mobile)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE settings (
  skey VARCHAR(80) NOT NULL,
  svalue TEXT NOT NULL,
  PRIMARY KEY (skey)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE billers (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code VARCHAR(40) NOT NULL,
  name VARCHAR(160) NOT NULL,
  category ENUM('lic', 'electricity') NOT NULL,
  region VARCHAR(80) NOT NULL DEFAULT '',
  description VARCHAR(255) NOT NULL DEFAULT '',
  bbps_biller_id VARCHAR(40) NOT NULL DEFAULT '',
  bbps_customer_param VARCHAR(80) NOT NULL DEFAULT '',
  status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_billers_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE linked_accounts (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  biller_id INT UNSIGNED NOT NULL,
  account_number VARCHAR(16) NOT NULL,
  holder_name VARCHAR(120) NOT NULL,
  nickname VARCHAR(40) NOT NULL DEFAULT '',
  remind_days TINYINT UNSIGNED NOT NULL DEFAULT 3,
  status ENUM('active', 'removed') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_linked_account (user_id, biller_id, account_number),
  KEY idx_linked_user (user_id, status),
  CONSTRAINT fk_linked_user FOREIGN KEY (user_id) REFERENCES users (id),
  CONSTRAINT fk_linked_biller FOREIGN KEY (biller_id) REFERENCES billers (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE biller_records (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  biller_id INT UNSIGNED NOT NULL,
  account_number VARCHAR(16) NOT NULL,
  customer_name VARCHAR(120) NOT NULL,
  billing_period VARCHAR(40) NOT NULL,
  bill_number VARCHAR(40) NOT NULL,
  bill_date DATE NOT NULL,
  due_date DATE NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  late_fee DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  units INT UNSIGNED NULL DEFAULT NULL,
  plan_name VARCHAR(120) NOT NULL DEFAULT '',
  details VARCHAR(255) NOT NULL DEFAULT '',
  is_sample TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_biller_period (biller_id, account_number, billing_period),
  KEY idx_biller_lookup (biller_id, account_number, is_active, due_date),
  CONSTRAINT fk_record_biller FOREIGN KEY (biller_id) REFERENCES billers (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE bills (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  linked_account_id INT UNSIGNED NOT NULL,
  biller_id INT UNSIGNED NOT NULL,
  master_id INT UNSIGNED NULL DEFAULT NULL,
  bill_number VARCHAR(40) NOT NULL,
  billing_period VARCHAR(40) NOT NULL,
  bill_date DATE NOT NULL,
  due_date DATE NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  late_fee DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  units INT UNSIGNED NULL DEFAULT NULL,
  plan_name VARCHAR(120) NOT NULL DEFAULT '',
  details VARCHAR(255) NOT NULL DEFAULT '',
  customer_name VARCHAR(120) NOT NULL,
  account_number VARCHAR(16) NOT NULL,
  status ENUM('unpaid', 'overdue', 'paid') NOT NULL DEFAULT 'unpaid',
  fetched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  paid_at DATETIME NULL DEFAULT NULL,
  updated_at DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_user_period (user_id, biller_id, account_number, billing_period),
  KEY idx_bills_due (user_id, status, due_date),
  CONSTRAINT fk_bills_user FOREIGN KEY (user_id) REFERENCES users (id),
  CONSTRAINT fk_bills_account FOREIGN KEY (linked_account_id) REFERENCES linked_accounts (id),
  CONSTRAINT fk_bills_biller FOREIGN KEY (biller_id) REFERENCES billers (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payments (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  bill_id INT UNSIGNED NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  txn_ref VARCHAR(40) NOT NULL,
  upi_vpa VARCHAR(120) NOT NULL,
  utr VARCHAR(22) NULL DEFAULT NULL,
  status ENUM('initiated', 'pending_verification', 'success', 'failed', 'refunded') NOT NULL DEFAULT 'initiated',
  user_note VARCHAR(255) NOT NULL DEFAULT '',
  admin_note VARCHAR(255) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL,
  verified_by INT UNSIGNED NULL DEFAULT NULL,
  verified_at DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_payments_ref (txn_ref),
  KEY idx_payments_user (user_id, created_at),
  KEY idx_payments_status (status, created_at),
  KEY idx_payments_bill (bill_id),
  CONSTRAINT fk_payments_user FOREIGN KEY (user_id) REFERENCES users (id),
  CONSTRAINT fk_payments_bill FOREIGN KEY (bill_id) REFERENCES bills (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE wallet_entries (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  balance_after DECIMAL(12,2) NOT NULL,
  kind ENUM('topup', 'bill', 'refund', 'adjustment') NOT NULL,
  note VARCHAR(255) NOT NULL DEFAULT '',
  payment_id INT UNSIGNED NULL DEFAULT NULL,
  actor_id INT UNSIGNED NULL DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_wallet_user (user_id, created_at),
  CONSTRAINT fk_wallet_user FOREIGN KEY (user_id) REFERENCES users (id),
  CONSTRAINT fk_wallet_payment FOREIGN KEY (payment_id) REFERENCES payments (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE notifications (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  title VARCHAR(160) NOT NULL,
  body VARCHAR(500) NOT NULL,
  link VARCHAR(255) NOT NULL DEFAULT '',
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_notifications_user (user_id, is_read, created_at),
  CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE reminders (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  bill_id INT UNSIGNED NOT NULL,
  remind_on DATE NOT NULL,
  message VARCHAR(255) NOT NULL,
  status ENUM('pending', 'sent', 'dismissed') NOT NULL DEFAULT 'pending',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_reminder_day (bill_id, remind_on),
  KEY idx_reminders_due (status, remind_on),
  CONSTRAINT fk_reminders_user FOREIGN KEY (user_id) REFERENCES users (id),
  CONSTRAINT fk_reminders_bill FOREIGN KEY (bill_id) REFERENCES bills (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audit_logs (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  actor_id INT UNSIGNED NULL DEFAULT NULL,
  action VARCHAR(80) NOT NULL,
  entity VARCHAR(40) NOT NULL DEFAULT '',
  entity_id INT UNSIGNED NULL DEFAULT NULL,
  details VARCHAR(500) NOT NULL DEFAULT '',
  ip VARCHAR(45) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_audit_created (created_at),
  KEY idx_audit_actor (actor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
