<?php
if (!defined('DAN_PORTAL')) { http_response_code(403); exit; }
$adminUsers = []; $issuedSetup = null;
if (!$isAdmin) {
    unset($_SESSION['issued_setup']);
    http_response_code(403); $error = 'This area is available only to administrators.';
} else {
    try {
        $adminDb = database();
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 8192) { throw new InvalidArgumentException('The request is too large.'); }
            if (!hash_equals($_SESSION['csrf'], input('csrf'))) {
                http_response_code(403); throw new InvalidArgumentException('Your form expired. Reload and try again.');
            }
            $action = input('action');
            if (!in_array($action, ['create_user', 'generate_setup_code'], true)) { throw new InvalidArgumentException('Unknown admin action.'); }
            $_SESSION['issued_setup'] = admin_setup_code($adminDb, $userId, input('username'), $action === 'create_user');
            header('Location: /?page=admin', true, 303); exit;
        }
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $issuedSetup = $_SESSION['issued_setup'] ?? null;
            unset($_SESSION['issued_setup']);
            if ($issuedSetup && time() - $issuedSetup['issued_at'] > 300) { $issuedSetup = null; }
        }
    } catch (DomainException $e) {
        $isAdmin = false; $issuedSetup = null; unset($_SESSION['issued_setup']);
        http_response_code(403); $error = 'This area is available only to administrators.';
    } catch (InvalidArgumentException $e) { $error = $e->getMessage(); }
    catch (Throwable $e) {
        error_log('Dan admin request failed (' . get_class($e) . ').');
        http_response_code(503); $error = 'User management is temporarily unavailable. Reload to check the user list before trying again.';
    }
}

if ($isAdmin) {
    try { $adminUsers = admin_user_list($adminDb, $userId); }
    catch (Throwable $e) { http_response_code(503); $error ??= 'The user list is temporarily unavailable.'; }
}
