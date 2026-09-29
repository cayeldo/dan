<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/auth.php';
$username = strtolower(trim($argv[1] ?? ''));
if (!in_array($username, ['don', 'dan'], true)) {
    fwrite(STDERR, "Usage: php deploy/create-setup-code.php don|dan\n");
    exit(1);
}
try {
    $code = issue_setup_code(database(), $username);
    echo "Private setup code for {$username} (expires in 24 hours):\n{$code}\n";
    echo "Give this code only to {$username}. Open the app and choose Create your password.\n";
} catch (Throwable $error) {
    fwrite(STDERR, "Could not create setup code. Check configuration, import the SQL, and confirm this account has no password yet.\n");
    exit(1);
}
