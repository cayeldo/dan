<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/auth.php';
require dirname(__DIR__) . '/analyzer.php';
try {
    if (!category_ai_config()['enabled']) { exit; }
    $db = database();
    queue_existing_unknowns($db);
    $count = run_category_ai($db);
    if ($count) { echo "Automatically categorized {$count} merchants.\n"; }
} catch (Throwable $error) {
    fwrite(STDERR, 'Category worker failed (' . get_class($error) . ").\n"); exit(1);
}
