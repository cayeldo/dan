<?php
if (!defined('DAN_PORTAL')) { http_response_code(403); exit; }
$budget = null; $budgetValues = null; $budgetResources = null; $budgetRecommendation = null; $resourceValues = null; $travelFund = null; $travelFundSetting = null; $vacationSetting = null; $vacationFund = null; $vacationVersion = null; $vacationValues = null;
$budgetMonth = is_string($_GET['month'] ?? null) ? $_GET['month'] : gmdate('Y-m');
try {
    $budgetDb = database();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hash_equals($_SESSION['csrf'], input('csrf'))) { http_response_code(403); throw new InvalidArgumentException('Your form expired. Please try again.'); }
        $budgetMonth = input('budget_month');
        if (input('action') === 'save_budget') {
            $budgetValues = is_array($_POST['targets'] ?? null) ? $_POST['targets'] : [];
            save_budget($budgetDb, $userId, $budgetMonth, $budgetValues, input('budget_version'));
            $_SESSION['notice'] = 'Budget saved. These targets carry forward until your next saved change.';
        } elseif (input('action') === 'recommend_budget') {
            $resourceValues = $_POST;
            generate_budget_recommendation($budgetDb, $userId, $budgetMonth, input('cash_available'), input('cash_reserve'),
                is_array($_POST['priorities'] ?? null) ? $_POST['priorities'] : [], is_array($_POST['minimums'] ?? null) ? $_POST['minimums'] : [], input('budget_version'), input('resources_version'));
            $_SESSION['notice'] = 'Recommendation ready to compare. Your current budget has not changed.';
        } elseif (input('action') === 'save_vacation_fund') {
            $vacationValues = $_POST;
            if (!vacation_fund_setting($budgetDb, $userId) && input('vacation_confirmed') !== '1') { throw new InvalidArgumentException('Confirm the starting balance is money already set aside for your Vacation Fund.'); }
            save_vacation_fund($budgetDb, $userId, $budgetMonth, input('vacation_contribution'), input('vacation_opening'), input('budget_version'), input('vacation_version'));
            $_SESSION['notice'] = 'Vacation Fund saved. Your monthly contribution is separate from Travel expenses and carries forward until your next change.';
        } elseif (input('action') === 'apply_recommended_budget') {
            apply_budget_recommendation($budgetDb, $userId, $budgetMonth, input('recommendation_version'));
            $_SESSION['notice'] = 'Recommended budget applied. These targets carry forward until your next saved change.';
        } else { throw new InvalidArgumentException('Unknown budget action.'); }
        header('Location: /?page=budget&month=' . rawurlencode($budgetMonth) . (input('action') === 'save_budget' ? '' : (input('action') === 'save_vacation_fund' ? '#vacation-fund-heading' : '#recommended-budget')), true, 303); exit;
    }
    $budget = budget_plan($budgetDb, $userId, $budgetMonth);
} catch (InvalidArgumentException $exception) {
    $error = $exception->getMessage();
    if (budget_month_valid($budgetMonth)) { $budget = budget_plan($budgetDb, $userId, $budgetMonth); }
} catch (Throwable $exception) {
    error_log('Budget request failed: ' . get_class($exception));
    http_response_code(503); $error = 'Your budget is temporarily unavailable. Please try again shortly.';
}
if ($budget) {
    try {
        $travelFundSetting = analyzer_query($budgetDb, 'SELECT start_month, opening_cents FROM analyzer_travel_funds WHERE user_id = ?', [$userId])->fetch() ?: null;
        $travelFund = travel_fund_balance($budgetDb, $userId, $budgetMonth);
        $vacationSetting = vacation_fund_setting($budgetDb, $userId);
        $vacationFund = vacation_fund_balance($budgetDb, $userId, $budgetMonth);
        $vacationVersion = vacation_fund_version($budgetDb, $userId);
        $budgetResources = budget_resources($budgetDb, $userId, $budgetMonth);
        $budgetRecommendation = saved_budget_recommendation($budgetDb, $userId, $budget, $budgetResources);
    } catch (Throwable $exception) {
        error_log('Budget recommendation unavailable: ' . get_class($exception));
        $error = 'Recommendations are temporarily unavailable. Your current budget is still available.';
    }
}
