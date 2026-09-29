<?php
if (!defined('DAN_PORTAL')) { http_response_code(403); exit; }
require_once __DIR__ . '/plaid.php';
$plaidRecord = null;
if (isset($_GET['api'])) {
    header('Content-Type: application/json');
    try {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); header('Allow: POST'); throw new InvalidArgumentException('Use POST.'); }
        if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 8192) { http_response_code(413); throw new InvalidArgumentException('Request too large.'); }
        if (!hash_equals($_SESSION['csrf'], input('csrf'))) { http_response_code(403); throw new InvalidArgumentException('Your session expired. Reload the page and try again.'); }
        plaid_config();
        $action = is_string($_GET['api']) ? $_GET['api'] : '';
        if ($action === 'link-token') {
            if (plaid_read($userId)) { throw new PlaidFailure('ALREADY_CONNECTED'); }
            if (time() - ($_SESSION['plaid_link_attempt'] ?? 0) < 10) { throw new PlaidFailure('BUSY'); }
            $_SESSION['plaid_link_attempt'] = time();
            $result = plaid_api('/link/token/create', plaid_link_payload($userId));
            if (!is_string($result['link_token'] ?? null) || !str_starts_with($result['link_token'], 'link-sandbox-')) { throw new PlaidFailure('INVALID_RESPONSE'); }
            $_SESSION['plaid_link_started'] = time();
            echo json_encode(['link_token' => $result['link_token']], JSON_THROW_ON_ERROR);
        } elseif ($action === 'exchange') {
            if (time() - ($_SESSION['plaid_link_started'] ?? 0) > 1800 && !plaid_read($userId)) { http_response_code(409); throw new InvalidArgumentException('Open Connect Credit Card to start a new session.'); }
            plaid_exchange($userId, input('public_token'));
            unset($_SESSION['plaid_link_started']);
            echo '{"connected":true}';
        } elseif ($action === 'transactions') {
            echo json_encode(plaid_refresh($userId), JSON_THROW_ON_ERROR);
        } else { http_response_code(404); throw new InvalidArgumentException('Unknown request.'); }
    } catch (InvalidArgumentException $error) {
        echo json_encode(['error' => $error->getMessage()]);
    } catch (Throwable $error) {
        http_response_code(502);
        error_log('Dan Plaid request failed (' . get_class($error) . ').');
        echo json_encode(['error' => plaid_error_message($error)]);
    }
    exit;
}
try { plaid_config(); $plaidRecord = plaid_read($userId); }
catch (Throwable $exception) { $error = plaid_error_message($exception); }
