<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/config.php';

use Sunyata\Core\RateLimiter;

$limiter = new RateLimiter();

echo "Testing rate limiter...\n";

for ($i = 1; $i <= 12; $i++) {
    $result = $limiter->check('test:key', 10, 60);
    $status = $result['allowed'] ? '✅ ALLOWED' : '❌ BLOCKED';
    echo "Request $i: $status";
    if (!$result['allowed']) {
        echo " (retry_after={$result['retry_after']}s)";
    }
    echo "\n";
}
