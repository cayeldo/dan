-- Additive migration: existing users and passwords are not changed.
CREATE TABLE IF NOT EXISTS analyzer_categories (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(80) NOT NULL,
    UNIQUE KEY category_name (user_id, name),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS analyzer_accounts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    label VARCHAR(80) NOT NULL,
    UNIQUE KEY account_label (user_id, label),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS analyzer_merchants (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL,
    category_id BIGINT UNSIGNED NOT NULL,
    needs_review TINYINT(1) NOT NULL DEFAULT 0,
    UNIQUE KEY merchant_name (user_id, name),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES analyzer_categories(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS analyzer_aliases (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    match_key CHAR(64) NOT NULL,
    merchant_id BIGINT UNSIGNED NOT NULL,
    UNIQUE KEY alias_key (user_id, match_key),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (merchant_id) REFERENCES analyzer_merchants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS analyzer_imports (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    account_id BIGINT UNSIGNED NOT NULL,
    filename VARCHAR(180) NOT NULL,
    file_hash CHAR(64) NOT NULL,
    row_count INT UNSIGNED NOT NULL,
    added_count INT UNSIGNED NOT NULL DEFAULT 0,
    skipped_count INT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY imported_file (account_id, file_hash),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (account_id) REFERENCES analyzer_accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS analyzer_transactions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    account_id BIGINT UNSIGNED NOT NULL,
    import_id BIGINT UNSIGNED NOT NULL,
    merchant_id BIGINT UNSIGNED DEFAULT NULL,
    transaction_date DATE NOT NULL,
    description VARCHAR(500) NOT NULL,
    memo TEXT NOT NULL,
    amount_cents BIGINT NOT NULL,
    kind VARCHAR(12) NOT NULL,
    bank_reference VARCHAR(100) DEFAULT NULL,
    dedupe_key CHAR(64) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY account_transaction (account_id, dedupe_key),
    KEY user_month (user_id, transaction_date),
    KEY merchant_month (merchant_id, transaction_date),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (account_id) REFERENCES analyzer_accounts(id) ON DELETE CASCADE,
    FOREIGN KEY (import_id) REFERENCES analyzer_imports(id) ON DELETE CASCADE,
    FOREIGN KEY (merchant_id) REFERENCES analyzer_merchants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Remember a CSV identity matched to a saved bank-feed transaction.
CREATE TABLE IF NOT EXISTS analyzer_csv_feed_matches (
    user_id BIGINT UNSIGNED NOT NULL,
    account_id BIGINT UNSIGNED NOT NULL,
    transaction_id BIGINT UNSIGNED NOT NULL,
    dedupe_key CHAR(64) NOT NULL,
    UNIQUE KEY csv_feed_identity (account_id, dedupe_key),
    UNIQUE KEY csv_feed_transaction (transaction_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (account_id) REFERENCES analyzer_accounts(id) ON DELETE CASCADE,
    FOREIGN KEY (transaction_id) REFERENCES analyzer_transactions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS analyzer_ai_jobs (
    merchant_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'pending',
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    available_at DATETIME NOT NULL,
    lease_token CHAR(32) DEFAULT NULL,
    model VARCHAR(80) DEFAULT NULL,
    category_name VARCHAR(80) DEFAULT NULL,
    confidence VARCHAR(10) DEFAULT NULL,
    last_error VARCHAR(32) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY ai_queue (status, available_at),
    FOREIGN KEY (merchant_id) REFERENCES analyzer_merchants(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS analyzer_simplefin_links (
    user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    account_id BIGINT UNSIGNED NOT NULL,
    remote_key CHAR(64) NOT NULL,
    remote_id VARCHAR(255) NOT NULL,
    start_date DATE NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    UNIQUE KEY simplefin_local_account (account_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (account_id) REFERENCES analyzer_accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Retain CSV boundaries even if a connection is replaced or disconnected.
CREATE TABLE IF NOT EXISTS analyzer_simplefin_history (
    account_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    start_date DATE NOT NULL,
    CONSTRAINT simplefin_history_account FOREIGN KEY (account_id) REFERENCES analyzer_accounts(id) ON DELETE CASCADE,
    CONSTRAINT simplefin_history_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Review eligibility: user-confirmed coverage or a scheduled imported-data snapshot.
CREATE TABLE IF NOT EXISTS analyzer_month_closures (
    user_id BIGINT UNSIGNED NOT NULL,
    month CHAR(7) NOT NULL,
    complete TINYINT(1) NOT NULL DEFAULT 1,
    data_hash CHAR(64) NOT NULL,
    source VARCHAR(24) NOT NULL,
    confirmed_at DATETIME NOT NULL,
    PRIMARY KEY (user_id, month),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One persisted review per person and calendar month, across their imported cards.
CREATE TABLE IF NOT EXISTS analyzer_month_reviews (
    user_id BIGINT UNSIGNED NOT NULL,
    month CHAR(7) NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'pending',
    input_json MEDIUMTEXT DEFAULT NULL,
    input_hash CHAR(64) DEFAULT NULL,
    result_json MEDIUMTEXT DEFAULT NULL,
    model VARCHAR(80) DEFAULT NULL,
    prompt_version INT UNSIGNED NOT NULL DEFAULT 1,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    started_at DATETIME DEFAULT NULL,
    completed_at DATETIME DEFAULT NULL,
    last_error VARCHAR(32) DEFAULT NULL,
    PRIMARY KEY (user_id, month),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Category membership and targets are saved per month for future comparisons.
CREATE TABLE IF NOT EXISTS analyzer_budgets (
    user_id BIGINT UNSIGNED NOT NULL,
    month CHAR(7) NOT NULL,
    groups_json MEDIUMTEXT NOT NULL,
    targets_json MEDIUMTEXT NOT NULL,
    revision INT UNSIGNED NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (user_id, month),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Freeze elapsed inherited months without turning them into future target changes.
CREATE TABLE IF NOT EXISTS analyzer_budget_snapshots (
    user_id BIGINT UNSIGNED NOT NULL,
    month CHAR(7) NOT NULL,
    groups_json MEDIUMTEXT NOT NULL,
    targets_json MEDIUMTEXT NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (user_id, month),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- User-entered monthly resources and category minimums, effective from this month.
CREATE TABLE IF NOT EXISTS analyzer_budget_resources (
    user_id BIGINT UNSIGNED NOT NULL,
    month CHAR(7) NOT NULL,
    cash_cents BIGINT NOT NULL,
    reserve_cents BIGINT NOT NULL,
    preferences_json MEDIUMTEXT NOT NULL,
    revision INT UNSIGNED NOT NULL DEFAULT 1,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (user_id, month),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A proposal never changes active targets until its owner explicitly applies it.
CREATE TABLE IF NOT EXISTS analyzer_budget_recommendations (
    user_id BIGINT UNSIGNED NOT NULL,
    month CHAR(7) NOT NULL,
    fingerprint CHAR(64) NOT NULL,
    proposal_json MEDIUMTEXT NOT NULL,
    ai_status VARCHAR(16) NOT NULL DEFAULT 'pending',
    ai_json MEDIUMTEXT DEFAULT NULL,
    model VARCHAR(80) DEFAULT NULL,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    started_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL,
    applied_at DATETIME DEFAULT NULL,
    PRIMARY KEY (user_id, month),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Opt-in travel reserve begins with an explicit user-entered balance, never inferred old savings.
CREATE TABLE IF NOT EXISTS analyzer_travel_funds (
    user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    category_id BIGINT UNSIGNED NOT NULL,
    start_month CHAR(7) NOT NULL,
    opening_cents BIGINT NOT NULL,
    created_at DATETIME NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES analyzer_categories(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
