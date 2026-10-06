<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/auth.php';
require dirname(__DIR__) . '/analyzer.php';
require dirname(__DIR__) . '/analytics.php';
require dirname(__DIR__) . '/budgets.php';
require dirname(__DIR__) . '/monthly-reviews.php';
require dirname(__DIR__) . '/budget-recommendations.php';
try { if (run_budget_advice(database())) { echo "Saved a budget explanation.\n"; } }
catch (Throwable $error) { fwrite(STDERR, 'Budget explanation worker failed (' . get_class($error) . ").\n"); exit(1); }
