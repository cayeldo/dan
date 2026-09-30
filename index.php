<?php
declare(strict_types=1);
require __DIR__ . '/auth.php';

ini_set('display_errors', '0');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
$secure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
// Plain HTTP is supported only by PHP's local development server.
if (!$secure && PHP_SAPI !== 'cli-server') {
    http_response_code(403);
    exit('Please open this app using HTTPS.');
}
session_name('dan_session');
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax']);
session_start();
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; style-src 'self'; script-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
if ($secure) {
    header('Strict-Transport-Security: max-age=31536000');
}
if (isset($_SESSION['last_activity']) && time() - $_SESSION['last_activity'] > 1800) {
    $_SESSION = [];
    session_regenerate_id(true);
}
$_SESSION['last_activity'] = time();
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));

function input(string $key): string
{
    return isset($_POST[$key]) && is_string($_POST[$key]) ? $_POST[$key] : '';
}
function escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

if (isset($_SESSION['user'])) {
    require __DIR__ . '/portal.php';
    exit;
}

$setup = ($_GET['mode'] ?? '') === 'setup';
$error = null;
$username = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = strtolower(trim(input('username')));
    if (!hash_equals($_SESSION['csrf'], input('csrf'))) {
        http_response_code(403);
        $error = 'Your form expired. Please try again.';
    } elseif (input('action') === 'logout') {
        $_SESSION = [];
        session_regenerate_id(true);
        header('Location: /', true, 303);
        exit;
    } else {
        $password = input('password');
        if (!preg_match('/\A[a-z0-9_]{1,50}\z/', $username)) {
            $error = 'Enter your username.';
        } elseif ($setup && ($validation = password_error($password, input('confirmation'))) !== null) {
            $error = $validation;
        } elseif (strlen($password) > 72 || $password === '' || str_contains($password, "\0")) {
            $error = 'Enter a valid password.';
        } else {
            try {
                $user = authenticate(database(), $username, $password, $setup ? trim(input('setup_code')) : null);
                if ($user === null) {
                    $error = $setup
                        ? 'Unable to set up this account. Check your username and setup code. After repeated attempts, wait 15 minutes.'
                        : 'Unable to sign in. Check your username and password. First time here? Create your password below. After repeated attempts, wait 15 minutes.';
                } else {
                    session_regenerate_id(true);
                    $_SESSION = ['user' => $user, 'csrf' => bin2hex(random_bytes(32)), 'last_activity' => time()];
                    header('Location: /', true, 303);
                    exit;
                }
            } catch (Throwable $exception) {
                error_log('Dan authentication service failed: ' . get_class($exception));
                http_response_code(503);
                $error = 'Sign-in is temporarily unavailable. Please contact the app owner.';
            }
        }
    }
}
$signedIn = isset($_SESSION['user']);
$title = $signedIn ? 'You’re signed in' : ($setup ? 'Create your password' : 'Welcome back');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Sign in to Dan.">
    <title><?= escape($title) ?> · Dan</title>
    <link rel="stylesheet" href="/styles.css">
</head>
<body>
<header class="brand"><a href="/" aria-label="Dan home">dan<span>.</span></a><span class="brand-label">YOUR PRIVATE SPACE</span></header>
<main>
    <section class="card" aria-labelledby="heading">
        <div class="eyebrow"><?= $signedIn ? 'ALL SET' : ($setup ? 'FIRST TIME HERE' : 'ACCOUNT ACCESS') ?></div>
        <h1 id="heading"><?= escape($title) ?></h1>
        <p class="intro"><?= $signedIn ? 'Welcome, ' . escape($_SESSION['user']['username']) . '. Your account is ready.' : ($setup ? 'Choose a password to use whenever you sign in.' : 'Sign in to your account to continue.') ?></p>
        <?php if ($error !== null): ?><p class="error" role="alert"><?= escape($error) ?></p><?php endif; ?>
        <?php if ($signedIn): ?>
            <form method="post" action="/">
                <input type="hidden" name="csrf" value="<?= escape($_SESSION['csrf']) ?>">
                <input type="hidden" name="action" value="logout">
                <button type="submit">Sign out</button>
            </form>
        <?php else: ?>
            <form method="post" action="<?= $setup ? '/?mode=setup' : '/' ?>">
                <input type="hidden" name="csrf" value="<?= escape($_SESSION['csrf']) ?>">
                <label for="username">Username</label>
                <input id="username" name="username" type="text" autocomplete="username" autocapitalize="none" spellcheck="false" maxlength="50" value="<?= escape($username) ?>" required>
                <?php if ($setup): ?>
                    <label for="setup_code">Setup code</label>
                    <input id="setup_code" name="setup_code" type="password" autocomplete="off" maxlength="64" aria-describedby="setup-hint" required>
                    <p class="hint" id="setup-hint">Use the private code provided by the app owner.</p>
                <?php endif; ?>
                <label for="password"><?= $setup ? 'New password' : 'Password' ?></label>
                <input id="password" name="password" type="password" autocomplete="<?= $setup ? 'new-password' : 'current-password' ?>" <?= $setup ? 'minlength="12" aria-describedby="password-hint"' : '' ?> maxlength="72" required>
                <?php if ($setup): ?>
                    <p class="hint" id="password-hint">Use 12–72 characters. For accented characters or emoji, the limit may be shorter.</p>
                    <label for="confirmation">Confirm password</label>
                    <input id="confirmation" name="confirmation" type="password" autocomplete="new-password" minlength="12" maxlength="72" required>
                <?php endif; ?>
                <button type="submit"><?= $setup ? 'Create password & sign in' : 'Sign in' ?></button>
            </form>
            <p class="switch"><?= $setup ? 'Already set up?' : 'First time here?' ?> <a href="<?= $setup ? '/' : '/?mode=setup' ?>"><?= $setup ? 'Sign in' : 'Create your password' ?></a></p>
        <?php endif; ?>
    </section>
</main>
<footer>Dan · Private account access</footer>
</body>
</html>
