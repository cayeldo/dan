<?php
if (!defined('DAN_PORTAL')) { http_response_code(403); exit; }
$budget = null; $budgetValues = null;
$budgetMonth = is_string($_GET['month'] ?? null) ? $_GET['month'] : gmdate('Y-m');
try {
    $budgetDb = database();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hash_equals($_SESSION['csrf'], input('csrf'))) { http_response_code(403); throw new InvalidArgumentException('Your form expired. Please try again.'); }
        if (input('action') !== 'save_budget') { throw new InvalidArgumentException('Unknown budget action.'); }
        $budgetMonth = input('budget_month');
        $budgetValues = is_array($_POST['targets'] ?? null) ? $_POST['targets'] : [];
        save_budget($budgetDb, $userId, $budgetMonth, $budgetValues, input('budget_version'));
        $_SESSION['notice'] = 'Budget saved. These targets carry forward until your next saved change.';
        header('Location: /?page=budget&month=' . rawurlencode($budgetMonth), true, 303); exit;
    }
    $budget = budget_plan($budgetDb, $userId, $budgetMonth);
} catch (InvalidArgumentException $exception) {
    $error = $exception->getMessage();
    if (budget_month_valid($budgetMonth)) { $budget = budget_plan($budgetDb, $userId, $budgetMonth); }
} catch (Throwable $exception) {
    error_log('Budget request failed: ' . get_class($exception));
    http_response_code(503); $error = 'Your budget is temporarily unavailable. Please try again shortly.';
}
