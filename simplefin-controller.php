<?php
if (!defined('DAN_PORTAL')) { http_response_code(403); exit; }
require_once __DIR__ . '/simplefin.php';
$sfin = null; $sfinLink = null; $sfinAccounts = [];
try {
    $sfinDb = database();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 8192) { throw new InvalidArgumentException('The connection request is too large.'); }
        if (!hash_equals($_SESSION['csrf'], input('csrf'))) { http_response_code(403); throw new InvalidArgumentException('Your form expired. Reload and try again.'); }
        switch (input('action')) {
            case 'sfin_connect':
                simplefin_connect($userId, input('setup_token'));
                simplefin_fetch($sfinDb, $userId);
                $_SESSION['notice'] = 'SimpleFIN connected. Select your credit card below.';
                break;
            case 'sfin_refresh': simplefin_fetch($sfinDb, $userId); break;
            case 'sfin_enable':
                simplefin_enable($sfinDb, $userId, input('remote_key'), (int) input('account_id'), input('account_label'), input('start_date'));
                $_SESSION['notice'] = 'Automatic imports enabled. Your posted transactions now appear in the analyzer.';
                break;
            case 'sfin_pause': simplefin_pause($sfinDb, $userId, true); break;
            case 'sfin_resume': simplefin_pause($sfinDb, $userId, false); break;
            case 'sfin_disconnect':
                simplefin_disconnect($sfinDb, $userId);
                $_SESSION['notice'] = 'Disconnected from this app. Imported history is preserved. Revoke the app token in SimpleFIN to end its access there too.';
                break;
            default: throw new InvalidArgumentException('Unknown action.');
        }
        header('Location: /?page=connect', true, 303); exit;
    }
} catch (InvalidArgumentException $e) { $error = $e->getMessage(); }
catch (Throwable $e) { error_log('Dan SimpleFIN request failed (' . get_class($e) . ').'); $error = 'The connection request could not finish. Any saved connection and imported history are preserved. Try refreshing later.'; }
try {
    $sfin = simplefin_read($userId); $sfinLink = simplefin_link($sfinDb, $userId);
    $sfinAccounts = analyzer_query($sfinDb, 'SELECT a.id, a.label, MAX(t.transaction_date) AS last_date FROM analyzer_accounts a LEFT JOIN analyzer_transactions t ON t.account_id = a.id AND t.user_id = a.user_id WHERE a.user_id = ? GROUP BY a.id, a.label ORDER BY a.label', [$userId])->fetchAll();
} catch (Throwable $e) { $error ??= 'Connection settings are temporarily unavailable.'; }
