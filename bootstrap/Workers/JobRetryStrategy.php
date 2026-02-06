<?php

namespace Nraa\Workers;

/**
 * Job Retry Strategy
 * 
 * Unified retry strategy with fixed delays:
 * - Attempt 1: 30 seconds
 * - Attempt 2: 60 seconds (1 minute)
 * - Attempt 3: 120 seconds (2 minutes)
 * 
 * Replaces the previous exponential backoff and ambiguous retry counting.
 */
class JobRetryStrategy
{
    /**
     * Fixed delay schedule in seconds
     * 
     * @var array
     */
    private static array $delays = [30, 60, 120];

    /**
     * Get delay for a specific attempt number
     * 
     * @param int $attempt Current attempt number (1-based)
     * @return int Delay in seconds
     */
    public static function getDelay(int $attempt): int
    {
        // Attempt 1 -> index 0 (30s)
        // Attempt 2 -> index 1 (60s)
        // Attempt 3 -> index 2 (120s)
        // Attempt 4+ -> use last delay (120s)
        $index = min($attempt - 1, count(self::$delays) - 1);
        return self::$delays[$index];
    }

    /**
     * Check if job should be retried
     * 
     * @param int $attempt Current attempt number
     * @param int $maxAttempts Maximum number of attempts
     * @return bool True if should retry
     */
    public static function shouldRetry(int $attempt, int $maxAttempts): bool
    {
        return $attempt < $maxAttempts;
    }

    /**
     * Get all configured delays
     * 
     * @return array Array of delays in seconds
     */
    public static function getDelays(): array
    {
        return self::$delays;
    }
}
