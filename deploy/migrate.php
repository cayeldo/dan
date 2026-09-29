<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/auth.php';
try {
    $db = database();
    // This additive, idempotent migration never seeds or resets login accounts.
    $sql = file_get_contents(dirname(__DIR__) . '/database/analyzer.sql');
    foreach (explode(';', $sql) as $statement) {
        if (trim($statement) !== '') { $db->exec($statement); }
    }
    echo "Credit card analyzer tables are ready.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Migration failed (' . get_class($error) . "). No login accounts were changed.\n");
    exit(1);
}
