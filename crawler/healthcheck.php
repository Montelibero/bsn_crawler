<?php

use MTLA\SnapshotHealthCheck;

require __DIR__ . '/vendor/autoload.php';

$publicDirectory = $argv[1] ?? '/data/public';
$maximumAgeSeconds = filter_var($argv[2] ?? 900, FILTER_VALIDATE_INT);

if ($maximumAgeSeconds === false || $maximumAgeSeconds <= 0) {
    fwrite(STDERR, "healthcheck failed: maximum age must be a positive integer\n");
    exit(2);
}

try {
    $status = (new SnapshotHealthCheck())->check($publicDirectory, $maximumAgeSeconds);
    print sprintf(
        "healthy generation=%s age=%ds accounts=%s\n",
        $status['generation'],
        $status['ageSeconds'],
        $status['accounts'] ?? 'unknown'
    );
} catch (Throwable $exception) {
    fwrite(STDERR, 'unhealthy: ' . $exception->getMessage() . "\n");
    exit(1);
}
