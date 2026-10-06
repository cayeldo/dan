<?php
declare(strict_types=1);
if (!isset($_SESSION['user'])) { http_response_code(403); exit; }
define('DAN_PORTAL', true);
require __DIR__ . '/analyzer.php';
require __DIR__ . '/analytics.php';
require __DIR__ . '/monthly-reviews.php';
require __DIR__ . '/budgets.php';
require __DIR__ . '/budget-recommendations.php';
require __DIR__ . '/overview-insights.php';
require __DIR__ . '/uncategorized.php';
require __DIR__ . '/admin.php';
require __DIR__ . '/statements.php';

$userId = (int) $_SESSION['user']['id'];
$displayName = ucfirst($_SESSION['user']['username']);
$page = in_array($_GET['page'] ?? '', ['analyzer', 'budget', 'statements', 'connect', 'admin', 'setup', 'imports', 'uncategorized'], true) ? $_GET['page'] : 'home';
$notice = $_SESSION['notice'] ?? null;
unset($_SESSION['notice']);
$error = null;
$isAdmin = false;
try { $isAdmin = user_is_admin(database(), $userId); }
catch (Throwable $e) { error_log('Dan admin membership check unavailable.'); }
$accounts = []; $categories = []; $months = []; $imports = []; $report = null; $preview = null;
$accountFilter = max(0, (int) (is_scalar($_GET['account'] ?? null) ? $_GET['account'] : 0));
$month = is_string($_GET['month'] ?? null) ? $_GET['month'] : '';
$requestedMonth = $month;
$overviewInsights = null; $history = []; $dashboard = null; $comparison = null; $monthlyReview = null; $latestReview = null; $closedMonths = []; $budgetProgress = null; $statement = null;
$pending = $_SESSION['pending_import'] ?? null;
if ($pending && ($pending['user_id'] !== $userId || time() - $pending['created_at'] > 1800)) {
    unset($_SESSION['pending_import']); $pending = null;
}
function analyzer_url(string $month = '', int $account = 0): string
{
    return '/?' . http_build_query(array_filter(['page' => 'analyzer', 'month' => $month, 'account' => $account], fn($v) => $v !== '' && $v !== 0));
}
function csrf_field(): void
{
    echo '<input type="hidden" name="csrf" value="' . escape($_SESSION['csrf']) . '">';
}
function redirect_analyzer(string $month = '', int $account = 0): never
{
    header('Location: ' . analyzer_url($month, $account), true, 303); exit;
}

if ($page === 'uncategorized') { require __DIR__ . '/uncategorized-controller.php'; }
if ($page === 'budget') { require __DIR__ . '/budget-controller.php'; }
if ($page === 'admin') { require __DIR__ . '/admin-controller.php'; }
if ($page === 'connect') { require __DIR__ . '/simplefin-controller.php'; }

try {
    $db = in_array($page, ['analyzer', 'imports', 'statements'], true) ? database() : null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !in_array($page, ['connect', 'admin', 'budget', 'uncategorized'], true)) {
        if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > ANALYZER_MAX_BYTES + 65536) { throw new InvalidArgumentException('The upload is too large. Choose a CSV smaller than 2 MB.'); }
        if (!hash_equals($_SESSION['csrf'], input('csrf'))) {
            http_response_code(403); throw new InvalidArgumentException('Your form expired. Please try again.');
        }
        $action = input('action');
        if ($action === 'logout') {
            $_SESSION = []; session_regenerate_id(true); header('Location: /', true, 303); exit;
        }
        if (!in_array($page, ['analyzer', 'imports', 'statements'], true)) { throw new InvalidArgumentException('Open Admin / Setup to manage your uploads.'); }
        if ($action === 'upload') {
            $file = $_FILES['statement'] ?? null;
            if (!is_array($file) || !is_int($file['error'] ?? null) || $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
                throw new InvalidArgumentException('Choose a CSV smaller than 2 MB and try again.');
            }
            if (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'csv' || $file['size'] > ANALYZER_MAX_BYTES) {
                throw new InvalidArgumentException('Choose a CSV smaller than 2 MB.');
            }
            $accountId = (int) input('account_id');
            $accountLabel = '';
            if ($accountId > 0) {
                $accountLabel = analyzer_query($db, 'SELECT label FROM analyzer_accounts WHERE user_id = ? AND id = ?', [$userId, $accountId])->fetchColumn();
                if ($accountLabel === false) { throw new InvalidArgumentException('Choose one of your cards.'); }
            } else {
                $accountLabel = clean_label(input('account_label'), 80);
                $existing = analyzer_query($db, 'SELECT id FROM analyzer_accounts WHERE user_id = ? AND label = ?', [$userId, $accountLabel])->fetchColumn();
                if ($existing !== false) { $accountId = (int) $existing; }
            }
            $contents = file_get_contents($file['tmp_name']);
            $rows = parse_statement($contents, input('charge_sign'));
            $pending = ['user_id' => $userId, 'token' => bin2hex(random_bytes(16)), 'created_at' => time(),
                'filename' => mb_substr(basename($file['name']), 0, 180), 'file_hash' => hash('sha256', $contents),
                'account_id' => $accountId, 'account_label' => $accountLabel, 'rows' => $rows];
            preview_import($db, $userId, $pending); // Detect conflicting references before offering Save.
            $_SESSION['pending_import'] = $pending;
            header('Location: /?page=imports', true, 303); exit;
        } elseif ($action === 'confirm_import') {
            if (!$pending || !hash_equals($pending['token'], input('pending_token'))) { throw new InvalidArgumentException('This preview expired or was replaced. Upload your CSV again.'); }
            $result = save_import($db, $userId, $pending);
            unset($_SESSION['pending_import']);
            // The import is already committed. AI problems must not undo it.
            try { run_category_ai($db, $userId); }
            catch (Throwable $aiError) { error_log('Dan category AI will retry in background.'); }
            $_SESSION['notice'] = $result['added'] . ' transactions saved. ' . $result['skipped'] . ' duplicates skipped. Your reports are organized by transaction month.';
            redirect_analyzer($result['month']);
        } elseif ($action === 'discard_import') {
            if ($pending && !hash_equals($pending['token'], input('pending_token'))) { throw new InvalidArgumentException('This preview was replaced. Reload before discarding it.'); }
            unset($_SESSION['pending_import']); header('Location: /?page=imports', true, 303); exit;
        } elseif ($action === 'confirm_months') {
            if (input('coverage_confirmed') !== '1') { throw new InvalidArgumentException('Confirm that all posted transactions for every imported card are present.'); }
            $count = confirm_complete_months($db, $userId, input('complete_from'), input('complete_through'));
            $_SESSION['notice'] = $count . ' months confirmed complete. Their reviews will be saved automatically once prepared.';
            header('Location: /?page=imports&reviews=1#complete-months', true, 303); exit;
        } elseif ($action === 'reopen_month') {
            $reviewMonth = input('review_month');
            analyzer_query($db, 'UPDATE analyzer_month_closures SET complete = 0 WHERE user_id = ? AND month = ?', [$userId, $reviewMonth]);
            $_SESSION['notice'] = 'Month marked incomplete. Its saved review is hidden.';
            header('Location: /?page=imports&reviews=1#complete-months', true, 303); exit;
        } elseif ($action === 'retry_month_review') {
            $reviewMonth = input('review_month');
            if (!month_review_is_ready($db, $userId, $reviewMonth)) { throw new InvalidArgumentException('Confirm this month is complete before retrying.'); }
            // Successful reviews can never be regenerated through this action.
            analyzer_query($db, "UPDATE analyzer_month_reviews SET status = 'pending' WHERE user_id = ? AND month = ? AND status = 'failed'", [$userId, $reviewMonth]);
            $_SESSION['notice'] = 'Review retry requested. The saved result will appear when ready.';
            redirect_analyzer($reviewMonth);
        } elseif ($action === 'save_merchant') {
            update_merchant($db, $userId, (int) input('merchant_id'), input('merchant_name'), (int) input('category_id'), input('custom_category'));
            $_SESSION['notice'] = 'Merchant and category saved. This applies to your past reports and future matching transactions.';
            redirect_analyzer(preg_match('/\A20\d{2}-(0[1-9]|1[0-2])\z/', input('month')) ? input('month') : '', (int) input('account_filter'));
        } else { throw new InvalidArgumentException('Unknown action. Please try again.'); }
    }
} catch (InvalidArgumentException $exception) {
    $error = $exception->getMessage();
} catch (Throwable $exception) {
    error_log('Dan analyzer request failed: ' . get_class($exception));
    http_response_code(503); $error = 'The analyzer is temporarily unavailable. Please try again shortly.';
}

if (in_array($page, ['analyzer', 'imports', 'statements'], true)) {
    try {
        $db ??= database();
        $accounts = user_accounts($db, $userId);
        if ($accountFilter > 0 && !in_array($accountFilter, array_map('intval', array_column($accounts, 'id')), true)) {
            if ($page === 'statements') { http_response_code(403); throw new InvalidArgumentException('Choose one of your cards.'); }
            $accountFilter = 0;
        }
        if ($page === 'analyzer') {
            $months = report_months($db, $userId, $accountFilter);
            $month = preg_match('/\A20\d{2}-(0[1-9]|1[0-2])\z/', $requestedMonth) ? $requestedMonth : ($months[0] ?? gmdate('Y-m'));
            $report = monthly_report($db, $userId, $month, $accountFilter);
            $history = spending_history($db, $userId, $accountFilter);
            $comparison = spending_comparison($history, $month);
            $overviewInsights = monthly_insights($db, $userId, $history, $month, $accountFilter);
            $budgetProgress = budget_progress($db, $userId, $month);
            if ($accountFilter === 0 || count($accounts) === 1) { $monthlyReview = saved_month_review($db, $userId, $month); }
            $categories = analyzer_query($db, 'SELECT id, name FROM analyzer_categories WHERE user_id = ? ORDER BY name', [$userId])->fetchAll();
        }
        if ($page === 'statements') {
            $months = report_months($db, $userId, $accountFilter);
            $month = $requestedMonth !== '' ? $requestedMonth : ($months[0] ?? gmdate('Y-m'));
            $statement = spending_statement($db, $userId, $month, $accountFilter, $displayName);
            if (($_GET['download'] ?? '') === 'pdf') {
                $pdf = statement_pdf($statement);
                header('Content-Type: application/pdf');
                header('Content-Disposition: attachment; filename="kle-coin-' . $month . '-statement.pdf"');
                header('Content-Length: ' . strlen($pdf));
                echo $pdf; exit;
            }
        }
        $imports = analyzer_query($db, 'SELECT i.*, a.label AS account FROM analyzer_imports i JOIN analyzer_accounts a ON a.id = i.account_id WHERE i.user_id = ? ORDER BY i.id DESC LIMIT 5', [$userId])->fetchAll();
        if ($page === 'imports') {
            $months = report_months($db, $userId);
            $closedMonths = analyzer_query($db, 'SELECT c.*, r.status AS review_status FROM analyzer_month_closures c LEFT JOIN analyzer_month_reviews r ON r.user_id = c.user_id AND r.month = c.month WHERE c.user_id = ? ORDER BY c.month DESC', [$userId])->fetchAll();
            foreach ($closedMonths as &$closed) { $closed['complete'] = month_review_is_ready($db, $userId, $closed['month']); } unset($closed);
        }
        if ($pending && $page === 'imports') { $preview = preview_import($db, $userId, $pending); }
    } catch (InvalidArgumentException $exception) { $error = $exception->getMessage(); }
    catch (Throwable $exception) {
        error_log('Dan analyzer report failed: ' . get_class($exception));
        http_response_code(503); $error = 'The analyzer is temporarily unavailable. Please try again shortly.';
    }
}
if ($page === 'home') {
    try {
        $homeDb = database();
        $history = spending_history($homeDb, $userId);
        $dashboard = spending_dashboard($history);
        $overviewInsights = overview_insights($homeDb, $userId, $history);
        $savedMonths = analyzer_query($homeDb, "SELECT month FROM analyzer_month_reviews WHERE user_id = ? AND status = 'completed' ORDER BY month DESC LIMIT 12", [$userId])->fetchAll(PDO::FETCH_COLUMN);
        foreach ($savedMonths as $savedMonth) {
            $latestReview = saved_month_review($homeDb, $userId, $savedMonth);
            if ($latestReview) { break; }
        }
    } catch (Throwable $exception) {
        error_log('Dan dashboard failed: ' . get_class($exception));
        http_response_code(503); $error = 'Your spending overview is temporarily unavailable. Please try again shortly.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= escape(match ($page) { 'home' => $displayName . '’s portal', 'admin' => 'User administration', 'connect' => 'Connect your card', 'statements' => 'Statements', 'imports' => 'Imports & monthly reviews', 'setup' => 'Admin / Setup', 'budget' => 'Monthly budget', 'uncategorized' => 'Uncategorized expenses', default => 'Credit card analyzer' }) ?> · KLE Coin</title>
    <?php require __DIR__ . "/views/brand-head.php"; ?>
    <link rel="stylesheet" href="/styles.css?v=<?= substr(hash_file('sha256', __DIR__ . '/styles.css'), 0, 16) ?>">
    <link rel="stylesheet" href="/portal.css?v=<?= substr(hash_file('sha256', __DIR__ . '/portal.css'), 0, 16) ?>">
    <?php if ($page === 'budget'): ?><script src="/budget.js?v=<?= substr(hash_file('sha256', __DIR__ . '/budget.js'), 0, 16) ?>" defer></script><?php endif; ?>
    <?php if ($page === 'statements'): ?><link rel="stylesheet" href="/statement.css?v=<?= substr(hash_file('sha256', __DIR__ . '/statement.css'), 0, 16) ?>"><?php endif; ?>
    <script src="/charts.js?v=<?= substr(hash_file('sha256', __DIR__ . '/charts.js'), 0, 16) ?>" defer></script>
</head>
<body class="workspace">
<header class="topbar">
    <?php require __DIR__ . "/views/brand.php"; ?>
    <nav aria-label="Main navigation"><a href="/" <?= $page === 'home' ? 'aria-current="page"' : '' ?>>Overview</a><a href="/?page=analyzer" <?= $page === 'analyzer' ? 'aria-current="page"' : '' ?>>Credit card analyzer</a><a href="/?page=statements" <?= $page === 'statements' ? 'aria-current="page"' : '' ?>>Statements</a><a href="/?page=setup" <?= in_array($page, ['setup', 'imports', 'budget', 'connect', 'admin', 'uncategorized'], true) ? 'aria-current="page"' : '' ?>>Admin / Setup</a></nav>
    <div class="account-menu"><span class="avatar" aria-hidden="true"><?= escape(strtoupper(substr($displayName, 0, 1))) ?></span><span><?= escape($displayName) ?></span>
    <form method="post" action="/"><?php csrf_field(); ?><input type="hidden" name="action" value="logout"><button class="text-button" type="submit">Sign out</button></form></div>
</header>
<main class="workspace-main">
    <?php if ($notice): ?><div class="notice" role="status"><?= escape($notice) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="error" role="alert"><?= escape($error) ?></div><?php endif; ?>
    <?php if (in_array($page, ['setup', 'imports', 'budget', 'connect', 'admin', 'uncategorized'], true)) { require __DIR__ . '/views/setup-nav.php'; } ?>
    <?php require __DIR__ . '/views/' . $page . '.php'; ?>
</main>
<footer><span class="footer-brand">KLE Coin · Plan. Spend. Save. Grow.</span> <span>Signed in as <?= escape($displayName) ?></span></footer>
</body>
</html>
