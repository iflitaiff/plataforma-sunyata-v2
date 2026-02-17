<?php
/**
 * Test Redis Cache Implementation
 */

require_once __DIR__ . '/app/vendor/autoload.php';
require_once __DIR__ . '/app/config/config.php';

use Sunyata\Core\Cache;

echo "=== Redis Cache Test ===\n\n";

// Test 1: Cache availability
echo "Test 1: Cache Availability\n";
if (Cache::isAvailable()) {
    echo "  ✅ Redis is connected and responsive\n";
} else {
    echo "  ⚠️  Redis is not available (degraded mode)\n";
}
echo "\n";

// Test 2: Basic set/get
echo "Test 2: Basic Set/Get\n";
$testKey = 'test:basic';
$testValue = ['message' => 'Hello Cache', 'timestamp' => time()];

Cache::set($testKey, $testValue, 60);
$retrieved = Cache::get($testKey);

if ($retrieved !== null && $retrieved['message'] === 'Hello Cache') {
    echo "  ✅ Set/Get working correctly\n";
    echo "  Retrieved: " . json_encode($retrieved) . "\n";
} else {
    echo "  ❌ Set/Get failed\n";
}
echo "\n";

// Test 3: Cache::remember pattern
echo "Test 3: Cache::remember Pattern\n";
$computations = 0;

$value1 = Cache::remember('test:remember', function() use (&$computations) {
    $computations++;
    return ['computed' => true, 'value' => 42];
}, 60);

$value2 = Cache::remember('test:remember', function() use (&$computations) {
    $computations++;
    return ['computed' => true, 'value' => 42];
}, 60);

if ($computations === 1) {
    echo "  ✅ Remember pattern working (computed only once)\n";
    echo "  Computations: $computations (expected: 1)\n";
} else {
    echo "  ❌ Remember pattern failed (computed $computations times)\n";
}
echo "\n";

// Test 4: TTL expiration (quick test with 1 second)
echo "Test 4: TTL Expiration (1 second TTL)\n";
Cache::set('test:ttl', 'expires soon', 1);
$beforeExpiry = Cache::get('test:ttl');
sleep(2);
$afterExpiry = Cache::get('test:ttl');

if ($beforeExpiry !== null && $afterExpiry === null) {
    echo "  ✅ TTL working correctly\n";
    echo "  Before expiry: '$beforeExpiry'\n";
    echo "  After expiry: " . var_export($afterExpiry, true) . "\n";
} else {
    echo "  ❌ TTL not working as expected\n";
}
echo "\n";

// Test 5: Delete
echo "Test 5: Delete\n";
Cache::set('test:delete', 'will be deleted', 60);
$exists = Cache::has('test:delete');
Cache::delete('test:delete');
$stillExists = Cache::has('test:delete');

if ($exists && !$stillExists) {
    echo "  ✅ Delete working correctly\n";
} else {
    echo "  ❌ Delete failed\n";
}
echo "\n";

// Test 6: Pattern delete
echo "Test 6: Pattern Delete\n";
Cache::set('test:pattern:1', 'value1', 60);
Cache::set('test:pattern:2', 'value2', 60);
Cache::set('test:other', 'keep this', 60);

$deleted = Cache::deletePattern('test:pattern:*');
$otherStillExists = Cache::has('test:other');

if ($deleted === 2 && $otherStillExists) {
    echo "  ✅ Pattern delete working ($deleted keys deleted)\n";
} else {
    echo "  ❌ Pattern delete failed (deleted: $deleted, expected: 2)\n";
}
echo "\n";

// Test 7: MetricsHelper with cache
echo "Test 7: MetricsHelper Cache Integration\n";
use Sunyata\Helpers\MetricsHelper;

$metrics = new MetricsHelper(true); // Enable cache

// First call - should hit database
$start1 = microtime(true);
$overview1 = $metrics->getOverview();
$time1 = (microtime(true) - $start1) * 1000;

// Second call - should hit cache (much faster)
$start2 = microtime(true);
$overview2 = $metrics->getOverview();
$time2 = (microtime(true) - $start2) * 1000;

echo "  First call (DB):    " . round($time1, 2) . "ms\n";
echo "  Second call (Cache): " . round($time2, 2) . "ms\n";

if ($time2 < $time1 * 0.5) { // Cache should be at least 2x faster
    echo "  ✅ MetricsHelper caching working (cache is " . round($time1/$time2, 1) . "x faster)\n";
} else {
    echo "  ⚠️  Cache speedup unclear (ratio: " . round($time1/$time2, 1) . "x)\n";
}
echo "\n";

// Clean up
echo "Cleanup: Deleting test keys...\n";
Cache::deletePattern('test:*');
Cache::delete('metrics:overview');

echo "\n=== All Tests Complete ===\n";
