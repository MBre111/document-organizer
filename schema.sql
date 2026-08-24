CREATE DATABASE IF NOT EXISTS organizer
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE organizer;

CREATE TABLE IF NOT EXISTS documents (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(255) NULL,
  doc_type VARCHAR(64) NULL,
  source_org VARCHAR(128) NULL,
  doc_date DATE NULL,
  summary TEXT NULL,
  notes TEXT NULL,
  extra_json JSON NULL,
  review_status VARCHAR(32) NOT NULL DEFAULT 'inbox',
  case_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_status (review_status),
  INDEX idx_type (doc_type),
  INDEX idx_org (source_org),
  INDEX idx_date (doc_date),
  INDEX idx_case (case_id)
);

CREATE TABLE IF NOT EXISTS files (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  document_id INT UNSIGNED NULL,
  original_filename VARCHAR(180) NOT NULL,
  stored_path VARCHAR(512) NOT NULL,
  mime VARCHAR(128) NULL,
  byte_size BIGINT UNSIGNED NULL,
  sha256 CHAR(64) NULL,
  page_no INT UNSIGNED NOT NULL DEFAULT 1,
  source VARCHAR(32) NOT NULL DEFAULT 'upload',
  drive_file_id VARCHAR(128) NULL,
  ocr_text MEDIUMTEXT NULL,
  ocr_status VARCHAR(16) NULL,
  ocr_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_sha (sha256),
  INDEX idx_doc_page (document_id, page_no),
  CONSTRAINT fk_files_document
    FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS tags (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(64) NOT NULL UNIQUE
);

CREATE TABLE IF NOT EXISTS document_tags (
  document_id INT UNSIGNED NOT NULL,
  tag_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (document_id, tag_id),
  CONSTRAINT fk_dt_doc FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
  CONSTRAINT fk_dt_tag FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS entities (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  kind VARCHAR(32) NOT NULL,
  name VARCHAR(180) NOT NULL,
  notes TEXT NULL,
  extra_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY kind_name (kind, name)
);

CREATE TABLE IF NOT EXISTS document_entities (
  document_id INT UNSIGNED NOT NULL,
  entity_id INT UNSIGNED NOT NULL,
  role VARCHAR(64) NOT NULL DEFAULT '',
  PRIMARY KEY (document_id, entity_id, role),
  CONSTRAINT fk_de_doc FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
  CONSTRAINT fk_de_ent FOREIGN KEY (entity_id) REFERENCES entities(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS untrusted_facts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  document_id INT UNSIGNED NULL,
  entity_id INT UNSIGNED NULL,
  fact_key VARCHAR(64) NOT NULL,
  fact_value TEXT NOT NULL,
  prompt VARCHAR(180) NULL,
  options_json TEXT NULL,
  reason VARCHAR(255) NULL,
  importance VARCHAR(16) NOT NULL DEFAULT 'normal',
  status VARCHAR(16) NOT NULL DEFAULT 'open',
  resolved_note TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resolved_at DATETIME NULL,
  INDEX idx_uf_status (status),
  INDEX idx_uf_importance (importance),
  INDEX idx_uf_doc (document_id)
);

CREATE TABLE IF NOT EXISTS cases (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  case_number VARCHAR(64) NOT NULL,
  title VARCHAR(180) NULL,
  court VARCHAR(180) NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'open',
  aliases TEXT NULL,
  notes TEXT NULL,
  extra_json TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_case_number (case_number)
);

CREATE TABLE IF NOT EXISTS deadlines (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  document_id INT UNSIGNED NULL,
  case_id INT UNSIGNED NULL,
  entity_id INT UNSIGNED NULL,
  due_on DATE NOT NULL,
  due_at DATETIME NULL,
  kind VARCHAR(32) NOT NULL DEFAULT 'other',
  title VARCHAR(180) NOT NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'open',
  source_key VARCHAR(64) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_dl_due (status, due_on),
  INDEX idx_dl_doc (document_id, source_key)
);

CREATE TABLE IF NOT EXISTS document_links (
  from_id INT UNSIGNED NOT NULL,
  to_id INT UNSIGNED NOT NULL,
  kind VARCHAR(32) NOT NULL DEFAULT 'related',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (from_id, to_id, kind),
  INDEX idx_link_to (to_id)
);

CREATE TABLE IF NOT EXISTS journals (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  entry_date DATE NOT NULL,
  entry_at DATETIME NULL,
  title VARCHAR(180) NULL,
  body TEXT NOT NULL,
  source VARCHAR(32) NOT NULL DEFAULT 'dictation',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_journal_date (entry_date)
);

CREATE TABLE IF NOT EXISTS journal_entities (
  journal_id INT UNSIGNED NOT NULL,
  entity_id INT UNSIGNED NOT NULL,
  role VARCHAR(64) NOT NULL DEFAULT '',
  PRIMARY KEY (journal_id, entity_id, role),
  INDEX idx_je_ent (entity_id)
);

CREATE TABLE IF NOT EXISTS measurements (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  taken_on DATE NOT NULL,
  taken_at DATETIME NULL,
  kind VARCHAR(32) NOT NULL DEFAULT 'weight',
  value_num DECIMAL(8,2) NOT NULL,
  unit VARCHAR(16) NOT NULL DEFAULT 'lb',
  conditions VARCHAR(180) NULL,
  journal_id INT UNSIGNED NULL,
  notes VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ms_kind_date (kind, taken_on)
);

CREATE TABLE IF NOT EXISTS deadline_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  deadline_id INT UNSIGNED NOT NULL,
  title VARCHAR(180) NOT NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'open',
  sort_order INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_di_dl (deadline_id, status)
);

CREATE TABLE IF NOT EXISTS recurring_bills (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  document_id INT UNSIGNED NULL,
  entity_id INT UNSIGNED NULL,
  payee VARCHAR(180) NOT NULL,
  amount DECIMAL(10,2) NULL,
  currency VARCHAR(8) NOT NULL DEFAULT 'USD',
  cadence VARCHAR(16) NOT NULL DEFAULT 'monthly',
  day_of_month TINYINT UNSIGNED NULL,
  next_due DATE NOT NULL,
  last_spawned DATE NULL,
  source VARCHAR(32) NOT NULL DEFAULT 'manual',
  notes VARCHAR(255) NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_rb_next (status, next_due)
);

CREATE TABLE IF NOT EXISTS bank_accounts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  fintable_id VARCHAR(64) NOT NULL,
  connection_id VARCHAR(80) NULL,
  institution VARCHAR(180) NULL,
  name VARCHAR(180) NOT NULL,
  display_name VARCHAR(180) NULL,
  type VARCHAR(80) NULL,
  currency VARCHAR(8) NOT NULL DEFAULT 'USD',
  balance DECIMAL(12,2) NULL,
  balance_available DECIMAL(12,2) NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  extra_json TEXT NULL,
  synced_at DATETIME NULL,
  UNIQUE KEY uq_ba_ft (fintable_id)
);

CREATE TABLE IF NOT EXISTS bank_transactions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  fintable_id VARCHAR(64) NOT NULL,
  account_id INT UNSIGNED NOT NULL,
  posted_on DATE NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  currency VARCHAR(8) NOT NULL DEFAULT 'USD',
  description VARCHAR(255) NULL,
  merchant VARCHAR(180) NULL,
  category VARCHAR(80) NULL,
  extra_json TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_bt_ft (fintable_id),
  INDEX idx_tx_date (posted_on),
  INDEX idx_tx_acct (account_id, posted_on)
);

CREATE TABLE IF NOT EXISTS app_state (
  k VARCHAR(64) NOT NULL,
  v TEXT NULL,
  PRIMARY KEY (k)
);

CREATE TABLE IF NOT EXISTS budget_categories (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(32) NOT NULL,
  name VARCHAR(80) NOT NULL,
  kind VARCHAR(16) NOT NULL DEFAULT 'expense',
  sort_order INT UNSIGNED NOT NULL DEFAULT 0,
  status VARCHAR(16) NOT NULL DEFAULT 'active',
  keywords TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_bc_slug (slug)
);

CREATE TABLE IF NOT EXISTS budget_lines (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  month_key CHAR(7) NOT NULL,
  category_id INT UNSIGNED NOT NULL,
  planned DECIMAL(10,2) NOT NULL DEFAULT 0,
  UNIQUE KEY uq_bl_month_cat (month_key, category_id),
  INDEX idx_bl_month (month_key)
);

CREATE TABLE IF NOT EXISTS money_rules (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  match_kind VARCHAR(32) NOT NULL DEFAULT 'merchant',
  match_value VARCHAR(180) NOT NULL,
  category_id INT UNSIGNED NULL,
  recurring_bill_id INT UNSIGNED NULL,
  is_transfer TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_mr_kind (match_kind)
);

CREATE TABLE IF NOT EXISTS habits (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(32) NOT NULL,
  name VARCHAR(80) NOT NULL,
  keywords TEXT NULL,
  sort_order INT UNSIGNED NOT NULL DEFAULT 0,
  status VARCHAR(16) NOT NULL DEFAULT 'active',
  UNIQUE KEY uq_habit_slug (slug)
);

CREATE TABLE IF NOT EXISTS habit_logs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  habit_id INT UNSIGNED NOT NULL,
  done_on DATE NOT NULL,
  journal_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_habit_day (habit_id, done_on)
);
