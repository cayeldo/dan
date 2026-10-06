<?php
if (!defined('DAN_PORTAL')) { http_response_code(403); exit; }
$uncategorized = null; $categoryChoices = [];
$listPage = max(1, (int) (is_scalar($_GET['list_page'] ?? null) ? $_GET['list_page'] : 1));
try {
    $categoryDb = database();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hash_equals($_SESSION['csrf'], input('csrf'))) { http_response_code(403); throw new InvalidArgumentException('Your form expired. Reload and try again.'); }
        if (input('action') !== 'categorize_uncategorized') { throw new InvalidArgumentException('Unknown category action.'); }
        categorize_uncategorized($categoryDb, $userId, (int) input('merchant_id'), (int) input('category_id'), input('custom_category'));
        $_SESSION['notice'] = 'Category saved for this merchant across all months and future matching purchases.';
        header('Location: /?page=uncategorized&list_page=' . $listPage, true, 303); exit;
    }
} catch (InvalidArgumentException $e) { $error = $e->getMessage(); }
catch (Throwable $e) { error_log('Uncategorized update failed (' . get_class($e) . ').'); http_response_code(503); $error = 'Categories are temporarily unavailable. Please try again.'; }
try {
    $uncategorized = uncategorized_expenses($categoryDb, $userId, $listPage);
    $categoryChoices = analyzer_query($categoryDb, "SELECT id, name FROM analyzer_categories WHERE user_id = ? AND name <> 'Uncategorized' ORDER BY name", [$userId])->fetchAll();
} catch (Throwable $e) { error_log('Uncategorized list failed (' . get_class($e) . ').'); http_response_code(503); $error = 'Uncategorized expenses are temporarily unavailable. Please try again.'; }
