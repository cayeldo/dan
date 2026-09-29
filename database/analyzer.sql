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
