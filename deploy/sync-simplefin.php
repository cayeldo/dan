<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/auth.php';
require dirname(__DIR__) . '/analyzer.php';
require dirname(__DIR__) . '/simplefin.php';
try {
    $db = database();
    // One due connection per minute bounds runtime and shares the existing deployment cron.
    foreach (analyzer_query($db, 'SELECT user_id FROM analyzer_simplefin_links WHERE enabled = 1 ORDER BY user_id')->fetchAll(PDO::FETCH_COLUMN) as $user) {
        $user = (int) $user;
        $record = simplefin_read($user);
        if (!$record || $record['next_sync'] > time()) { continue; }
        simplefin_fetch($db, $user, null, true);
        echo "SimpleFIN scheduled refresh completed.\n";
        break;
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'SimpleFIN worker failed (' . get_class($error) . ").\n"); exit(1);
}
