<?php
declare(strict_types=1);
if (!isset($_SESSION['user'])) { http_response_code(403); exit; }
define('DAN_PORTAL', true);
require __DIR__ . '/analyzer.php';
require __DIR__ . '/analytics.php';

$userId = (int) $_SESSION['user']['id'];
$displayName = ucfirst($_SESSION['user']['username']);
$page = in_array($_GET['page'] ?? '', ['analyzer', 'plaid', 'connect'], true) ? $_GET['page'] : 'home';
$notice = $_SESSION['notice'] ?? null;
unset($_SESSION['notice']);
$error = null;
$accounts = []; $categories = []; $months = []; $imports = []; $report = null; $preview = null;
$accountFilter = max(0, (int) (is_scalar($_GET['account'] ?? null) ? $_GET['account'] : 0));
$month = is_string($_GET['month'] ?? null) ? $_GET['month'] : '';
$requestedMonth = $month;
$history = []; $dashboard = null; $comparison = null;
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

if ($page === 'plaid') { require __DIR__ . '/plaid-controller.php'; }
if ($page === 'connect') { require __DIR__ . '/simplefin-controller.php'; }

try {
    $db = $page === 'analyzer' ? database() : null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $page !== 'connect') {
        if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > ANALYZER_MAX_BYTES + 65536) { throw new InvalidArgumentException('The upload is too large. Choose a CSV smaller than 2 MB.'); }
        if (!hash_equals($_SESSION['csrf'], input('csrf'))) {
            http_response_code(403); throw new InvalidArgumentException('Your form expired. Please try again.');
        }
        $action = input('action');
        if ($action === 'logout') {
            $_SESSION = []; session_regenerate_id(true); header('Location: /', true, 303); exit;
        }
        if ($page !== 'analyzer') { throw new InvalidArgumentException('Open the analyzer to manage your statements.'); }
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
            redirect_analyzer();
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
            unset($_SESSION['pending_import']); redirect_analyzer();
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

if ($page === 'analyzer') {
    try {
        $db ??= database();
        $accounts = user_accounts($db, $userId);
        if ($accountFilter > 0 && !in_array($accountFilter, array_map('intval', array_column($accounts, 'id')), true)) {
            $accountFilter = 0;
        }
        $months = report_months($db, $userId, $accountFilter);
        $month = preg_match('/\A20\d{2}-(0[1-9]|1[0-2])\z/', $requestedMonth) ? $requestedMonth : ($months[0] ?? gmdate('Y-m'));
        $report = monthly_report($db, $userId, $month, $accountFilter);
        $history = spending_history($db, $userId, $accountFilter);
        $comparison = spending_comparison($history, $month);
        $categories = analyzer_query($db, 'SELECT id, name FROM analyzer_categories WHERE user_id = ? ORDER BY name', [$userId])->fetchAll();
        $imports = analyzer_query($db, 'SELECT i.*, a.label AS account FROM analyzer_imports i JOIN analyzer_accounts a ON a.id = i.account_id WHERE i.user_id = ? ORDER BY i.id DESC LIMIT 5', [$userId])->fetchAll();
        if ($pending) { $preview = preview_import($db, $userId, $pending); }
    } catch (InvalidArgumentException $exception) { $error = $exception->getMessage(); }
    catch (Throwable $exception) {
        error_log('Dan analyzer report failed: ' . get_class($exception));
        http_response_code(503); $error = 'The analyzer is temporarily unavailable. Please try again shortly.';
    }
}
if ($page === 'home') {
    try {
        $history = spending_history(database(), $userId);
        $dashboard = spending_dashboard($history);
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
    <title><?= $page === 'home' ? escape($displayName) . '’s portal' : ($page === 'plaid' ? 'Connect Credit Card · Sandbox' : ($page === 'connect' ? 'Connect your card' : 'Credit card analyzer')) ?> · Dan</title>
    <link rel="stylesheet" href="/styles.css">
    <link rel="stylesheet" href="/portal.css">
</head>
<body class="workspace">
<header class="topbar">
    <a class="wordmark" href="/" aria-label="Dan home">dan<span>.</span></a>
    <nav aria-label="Main navigation"><a href="/" <?= $page === 'home' ? 'aria-current="page"' : '' ?>>Overview</a><a href="/?page=analyzer" <?= $page === 'analyzer' ? 'aria-current="page"' : '' ?>>Credit card analyzer</a><a href="/?page=connect" <?= $page === 'connect' ? 'aria-current="page"' : '' ?>>Connect card</a></nav>
    <div class="account-menu"><span class="avatar" aria-hidden="true"><?= escape(strtoupper(substr($displayName, 0, 1))) ?></span><span><?= escape($displayName) ?></span>
    <form method="post" action="/"><?php csrf_field(); ?><input type="hidden" name="action" value="logout"><button class="text-button" type="submit">Sign out</button></form></div>
</header>
<main class="workspace-main">
    <?php if ($notice): ?><div class="notice" role="status"><?= escape($notice) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="error" role="alert"><?= escape($error) ?></div><?php endif; ?>
    <?php require __DIR__ . '/views/' . $page . '.php'; ?>
</main>
<footer>Your space. Your records. <span>Signed in as <?= escape($displayName) ?></span></footer>
</body>
</html>
