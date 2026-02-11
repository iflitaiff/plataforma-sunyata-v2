<?php
/**
 * Clear OPcache - REMOVER APÓS USO
 */

header('Content-Type: text/plain; charset=utf-8');

echo "=== CACHE CLEAR ===\n\n";

// Clear OPcache
if (function_exists('opcache_reset')) {
    if (opcache_reset()) {
        echo "✅ OPcache cleared successfully\n";
    } else {
        echo "❌ Failed to clear OPcache\n";
    }
} else {
    echo "⚠️ OPcache not available\n";
}

// Clear stat cache
clearstatcache(true);
echo "✅ Stat cache cleared\n";

echo "\n=== Current Time ===\n";
echo date('Y-m-d H:i:s') . "\n";

echo "\n✅ Done! Now hard refresh your browser (Ctrl+Shift+R)\n";
