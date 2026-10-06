<?php
declare(strict_types=1);

function uncategorized_expenses(PDO $db, int $user, int $page = 1): array
{
    $groups = analyzer_query($db, "SELECT m.id, m.name, COUNT(*) AS purchases, SUM(ABS(t.amount_cents)) AS amount, MIN(t.transaction_date) AS first_date, MAX(t.transaction_date) AS last_date FROM analyzer_transactions t JOIN analyzer_merchants m ON m.id = t.merchant_id AND m.user_id = t.user_id JOIN analyzer_categories c ON c.id = m.category_id AND c.user_id = t.user_id WHERE t.user_id = ? AND t.kind = 'expense' AND c.name = 'Uncategorized' GROUP BY m.id, m.name ORDER BY amount DESC, m.name, m.id", [$user])->fetchAll();
    $total = array_sum(array_column($groups, 'amount')); $count = array_sum(array_column($groups, 'purchases'));
    $pages = max(1, (int) ceil(count($groups) / 25)); $page = max(1, min($page, $pages));
    $visible = array_slice($groups, ($page - 1) * 25, 25); $transactions = [];
    if ($visible) {
        $ids = array_column($visible, 'id'); $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = analyzer_query($db, "SELECT t.id, t.merchant_id, t.transaction_date, t.description, t.amount_cents, a.label AS account FROM analyzer_transactions t JOIN analyzer_accounts a ON a.id = t.account_id AND a.user_id = t.user_id WHERE t.user_id = ? AND t.kind = 'expense' AND t.merchant_id IN ($placeholders) ORDER BY t.transaction_date DESC, t.id DESC", [$user, ...$ids])->fetchAll();
        foreach ($rows as $row) { $transactions[(int) $row['merchant_id']][] = $row; }
    }
    return ['merchants' => $visible, 'transactions' => $transactions, 'total_cents' => (int) $total, 'purchase_count' => (int) $count, 'merchant_count' => count($groups), 'page' => $page, 'pages' => $pages];
}

/** Keep the existing merchant identity; one correction updates all matching history. */
function categorize_uncategorized(PDO $db, int $user, int $merchant, int $category, string $custom): void
{
    $db->beginTransaction();
    try {
        analyzer_query($db, 'SELECT id FROM users WHERE id = ? FOR UPDATE', [$user]);
        $row = analyzer_query($db, 'SELECT m.id, c.name AS category FROM analyzer_merchants m JOIN analyzer_categories c ON c.id = m.category_id AND c.user_id = m.user_id WHERE m.id = ? AND m.user_id = ? FOR UPDATE', [$merchant, $user])->fetch();
        if (!$row) { throw new InvalidArgumentException('Merchant not found.'); }
        if ($row['category'] !== 'Uncategorized') { throw new InvalidArgumentException('This merchant has already been categorized. Refresh to see the latest list.'); }
        if (trim($custom) !== '') { $category = get_category($db, $user, clean_label($custom, 80)); }
        $name = analyzer_query($db, 'SELECT name FROM analyzer_categories WHERE id = ? AND user_id = ?', [$category, $user])->fetchColumn();
        if ($name === false || strcasecmp($name, 'Uncategorized') === 0) { throw new InvalidArgumentException('Choose a category or enter a new one.'); }
        analyzer_query($db, 'UPDATE analyzer_merchants SET category_id = ?, needs_review = 0 WHERE id = ? AND user_id = ?', [$category, $merchant, $user]);
        analyzer_query($db, "UPDATE analyzer_ai_jobs SET status = 'overridden', lease_token = NULL WHERE merchant_id = ? AND user_id = ?", [$merchant, $user]);
        $db->commit();
    } catch (Throwable $error) { if ($db->inTransaction()) { $db->rollBack(); } throw $error; }
}
