<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/auth.php';
try {
    $db = database();
    // This additive, idempotent migration never seeds or resets login accounts.
    foreach (['analyzer.sql', 'admin.sql'] as $migration) {
        $sql = file_get_contents(dirname(__DIR__) . '/database/' . $migration);
        foreach (explode(';', $sql) as $statement) {
            if (trim($statement) !== '') { $db->exec($statement); }
        }
    }
    require_once dirname(__DIR__) . '/analyzer.php';
    $repaired = repair_payment_reversals($db);
    $configured = configure_dan_statement_periods($db);
    echo "Payment reversals corrected: {$repaired}. Dan statement dates " . ($configured ? 'configured' : 'already configured or no unambiguous card found') . ".\n";
    echo "Application tables are ready.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Migration failed (' . get_class($error) . "). No login accounts were changed.\n");
    exit(1);
}
