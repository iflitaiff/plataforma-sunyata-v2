<?php
/**
 * Redis Cache Wrapper
 *
 * Provides simple caching interface with automatic fallback to no-cache
 * if Redis is unavailable (graceful degradation).
 *
 * @package Sunyata\Core
 */

namespace Sunyata\Core;

use Redis;
use Exception;

class Cache
{
    private static ?Redis $redis = null;
    private static bool $enabled = true;
    private static bool $connectionFailed = false;

    /**
     * Initialize Redis connection (lazy)
     */
    private static function connect(): ?Redis
    {
        // Return cached connection
        if (self::$redis !== null) {
            return self::$redis;
        }

        // Don't retry if previous connection failed
        if (self::$connectionFailed) {
            return null;
        }

        try {
            $redis = new Redis();

            // Connect to Redis (default: localhost:6379)
            $host = getenv('REDIS_HOST') ?: '127.0.0.1';
            $port = (int)(getenv('REDIS_PORT') ?: 6379);
            $timeout = 2.0; // 2 second timeout

            $connected = $redis->connect($host, $port, $timeout);

            if (!$connected) {
                throw new Exception("Failed to connect to Redis at {$host}:{$port}");
            }

            // Test connection
            $redis->ping();

            // Set prefix for all keys
            $redis->setOption(Redis::OPT_PREFIX, 'sunyata:');

            self::$redis = $redis;
            return $redis;

        } catch (Exception $e) {
            self::$connectionFailed = true;
            error_log('Cache: Redis connection failed - ' . $e->getMessage());
            error_log('Cache: Running in degraded mode (no cache)');
            return null;
        }
    }

    /**
     * Get value from cache
     *
     * @param string $key Cache key
     * @return mixed|null Cached value or null if not found/unavailable
     */
    public static function get(string $key)
    {
        if (!self::$enabled) {
            return null;
        }

        $redis = self::connect();
        if ($redis === null) {
            return null;
        }

        try {
            $value = $redis->get($key);

            // Redis returns false for non-existent keys
            if ($value === false) {
                return null;
            }

            // Deserialize if it's JSON
            $decoded = json_decode($value, true);
            return $decoded !== null ? $decoded : $value;

        } catch (Exception $e) {
            error_log('Cache::get error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Set value in cache with optional TTL
     *
     * @param string $key Cache key
     * @param mixed $value Value to cache (will be JSON encoded)
     * @param int $ttl Time-to-live in seconds (default: 300 = 5min)
     * @return bool Success status
     */
    public static function set(string $key, $value, int $ttl = 300): bool
    {
        if (!self::$enabled) {
            return false;
        }

        $redis = self::connect();
        if ($redis === null) {
            return false;
        }

        try {
            // JSON encode for structured data
            $serialized = is_string($value) ? $value : json_encode($value);

            if ($ttl > 0) {
                return $redis->setex($key, $ttl, $serialized);
            } else {
                return $redis->set($key, $serialized);
            }

        } catch (Exception $e) {
            error_log('Cache::set error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete value from cache
     *
     * @param string $key Cache key
     * @return bool Success status
     */
    public static function delete(string $key): bool
    {
        if (!self::$enabled) {
            return false;
        }

        $redis = self::connect();
        if ($redis === null) {
            return false;
        }

        try {
            return $redis->del($key) > 0;
        } catch (Exception $e) {
            error_log('Cache::delete error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete all keys matching pattern
     *
     * @param string $pattern Pattern (e.g., "metrics:*")
     * @return int Number of keys deleted
     */
    public static function deletePattern(string $pattern): int
    {
        if (!self::$enabled) {
            return 0;
        }

        $redis = self::connect();
        if ($redis === null) {
            return 0;
        }

        try {
            // Find keys matching pattern
            $keys = $redis->keys($pattern);

            if (empty($keys)) {
                return 0;
            }

            // Delete all matching keys
            return $redis->del($keys);

        } catch (Exception $e) {
            error_log('Cache::deletePattern error: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Check if key exists in cache
     *
     * @param string $key Cache key
     * @return bool True if exists
     */
    public static function has(string $key): bool
    {
        if (!self::$enabled) {
            return false;
        }

        $redis = self::connect();
        if ($redis === null) {
            return false;
        }

        try {
            return $redis->exists($key) > 0;
        } catch (Exception $e) {
            error_log('Cache::has error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get or set pattern - fetch from cache or compute and store
     *
     * @param string $key Cache key
     * @param callable $callback Function to compute value if not cached
     * @param int $ttl Time-to-live in seconds
     * @return mixed Cached or computed value
     */
    public static function remember(string $key, callable $callback, int $ttl = 300)
    {
        // Try to get from cache
        $cached = self::get($key);

        if ($cached !== null) {
            return $cached;
        }

        // Not in cache - compute value
        $value = $callback();

        // Store in cache for next time
        if ($value !== null) {
            self::set($key, $value, $ttl);
        }

        return $value;
    }

    /**
     * Flush all cache keys with sunyata: prefix
     *
     * WARNING: Use with caution - clears ALL cached data
     *
     * @return bool Success status
     */
    public static function flush(): bool
    {
        $redis = self::connect();
        if ($redis === null) {
            return false;
        }

        try {
            // Find all keys with our prefix
            $keys = $redis->keys('*');

            if (empty($keys)) {
                return true;
            }

            $redis->del($keys);
            return true;

        } catch (Exception $e) {
            error_log('Cache::flush error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Disable cache (for testing or emergency degraded mode)
     */
    public static function disable(): void
    {
        self::$enabled = false;
    }

    /**
     * Enable cache
     */
    public static function enable(): void
    {
        self::$enabled = true;
    }

    /**
     * Check if cache is available and working
     *
     * @return bool True if Redis is connected and responsive
     */
    public static function isAvailable(): bool
    {
        $redis = self::connect();
        if ($redis === null) {
            return false;
        }

        try {
            // ping() returns true on success (not '+PONG' string)
            return $redis->ping() === true || $redis->ping() === '+PONG';
        } catch (Exception $e) {
            return false;
        }
    }
}
