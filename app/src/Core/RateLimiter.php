<?php

namespace Sunyata\Core;

/**
 * Simple Redis-based rate limiter.
 *
 * Uses a sliding window counter per key (e.g., IP address).
 */
class RateLimiter
{
    private \Redis $redis;

    public function __construct()
    {
        $host = getenv('REDIS_SESSION_HOST') ?: '127.0.0.1';
        $port = (int)(getenv('REDIS_SESSION_PORT') ?: 6379);

        $this->redis = new \Redis();
        $this->redis->connect($host, $port);
    }

    /**
     * Check if a request is allowed under the rate limit.
     *
     * @param string $key    Unique key (e.g., "login:192.168.1.1")
     * @param int    $limit  Max attempts allowed
     * @param int    $window Window in seconds
     * @return array{allowed: bool, remaining: int, retry_after: int}
     */
    public function check(string $key, int $limit = 5, int $window = 900): array
    {
        // Disable rate limiting in development mode
        if (getenv('APP_ENV') === 'development') {
            return [
                'allowed' => true,
                'remaining' => 999,
                'retry_after' => 0,
            ];
        }

        $redisKey = "ratelimit:{$key}";
        $current = (int)$this->redis->get($redisKey);

        if ($current >= $limit) {
            $ttl = $this->redis->ttl($redisKey);
            return [
                'allowed' => false,
                'remaining' => 0,
                'retry_after' => max(0, $ttl),
            ];
        }

        // Increment counter (set TTL on first hit)
        $newCount = $this->redis->incr($redisKey);
        if ($newCount === 1) {
            $this->redis->expire($redisKey, $window);
        }

        return [
            'allowed' => true,
            'remaining' => max(0, $limit - $newCount),
            'retry_after' => 0,
        ];
    }
}
