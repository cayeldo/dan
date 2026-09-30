<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/auth.php';
require dirname(__DIR__) . '/analyzer.php';
require dirname(__DIR__) . '/monthly-reviews.php';
try {
    $count = run_month_review(database());
    if ($count) { echo "Saved one monthly spending review.\n"; }
} catch (Throwable $error) {
    fwrite(STDERR, 'Monthly review worker failed (' . get_class($error) . ").\n"); exit(1);
}
