<?php
/**
 * Cleanup expired form drafts.
 * Run weekly via cron:
 * 0 3 * * 0 php /var/www/sunyata/app/scripts/cleanup-drafts.php >> /var/log/sunyata-cron.log 2>&1
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/config.php';

use Sunyata\Services\DraftService;

try {
    $service = new DraftService();
    $deleted = $service->cleanExpired();

    $msg = date('[Y-m-d H:i:s]') . " cleanup-drafts: {$deleted} expired drafts deleted\n";
    echo $msg;
} catch (\Exception $e) {
    $msg = date('[Y-m-d H:i:s]') . " cleanup-drafts ERROR: " . $e->getMessage() . "\n";
    echo $msg;
    error_log($msg);
    exit(1);
}
