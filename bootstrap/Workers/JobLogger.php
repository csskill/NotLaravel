<?php

namespace Nraa\Workers;

use Nraa\Workers\Documents\JobLogDocument;
use Nraa\Pillars\Log;

/**
 * Job Logger
 * 
 * Centralized logging for job execution that writes to both:
 * - Local files (for container debugging)
 * - MongoDB (for centralized monitoring across containers)
 * 
 * Supports structured logging with job ID, worker ID, pool, and metadata.
 */
class JobLogger
{
    private static ?string $logDir = null;
    private static string $logLevel = 'INFO';

    /**
     * Initialize logger configuration
     * 
     * @return void
     */
    private static function initialize(): void
    {
        if (self::$logDir === null) {
            // Determine log directory
            $basePath = dirname(__DIR__, 3);
            $logPath = $_ENV['JOB_LOG_DIR'] ?? $basePath . '/storage/logs/jobs';
            
            // Create directory if it doesn't exist
            if (!is_dir($logPath)) {
                @mkdir($logPath, 0755, true);
            }
            
            self::$logDir = $logPath;
        }
        
        // Set log level from environment
        self::$logLevel = strtoupper($_ENV['LOG_LEVEL'] ?? 'INFO');
    }

    /**
     * Log a message
     * 
     * @param array $data Log data with keys:
     *   - job_id: Job ID (optional)
     *   - worker_id: Worker ID (optional)
     *   - pool: Pool name (optional)
     *   - level: Log level (debug, info, warning, error) - defaults to 'info'
     *   - message: Log message (required)
     *   - metadata: Additional metadata array (optional)
     * @return void
     */
    public static function log(array $data): void
    {
        self::initialize();

        // Extract log fields
        $jobId = $data['job_id'] ?? $data['jobId'] ?? null;
        $workerId = $data['worker_id'] ?? $data['workerId'] ?? null;
        $pool = $data['pool'] ?? null;
        $level = strtolower($data['level'] ?? 'info');
        $message = $data['message'] ?? '';
        $metadata = $data['metadata'] ?? [];

        // Validate log level
        $validLevels = ['debug', 'info', 'warning', 'error'];
        if (!in_array($level, $validLevels)) {
            $level = 'info';
        }

        // Check if we should log this level
        if (!self::shouldLog($level)) {
            return;
        }

        // Format timestamp
        $timestamp = new \DateTimeImmutable();
        $timestampStr = $timestamp->format('Y-m-d H:i:s');

        // Build log context
        $context = [];
        if ($jobId) {
            $context['job_id'] = $jobId;
        }
        if ($workerId) {
            $context['worker_id'] = $workerId;
        }
        if ($pool) {
            $context['pool'] = $pool;
        }
        if (!empty($metadata)) {
            $context = array_merge($context, $metadata);
        }

        // Format log line for file output
        $logLine = self::formatLogLine($timestampStr, $level, $message, $context);

        // Write to local file
        self::writeToFile($logLine, $level);

        // Write to MongoDB (async - don't block on failures)
        try {
            JobLogDocument::log([
                'jobId' => $jobId,
                'workerId' => $workerId,
                'pool' => $pool,
                'level' => $level,
                'message' => $message,
                'timestamp' => new \MongoDB\BSON\UTCDateTime($timestamp),
                'metadata' => $metadata,
            ]);
        } catch (\Throwable $e) {
            // Don't let MongoDB logging failures break the application
            // Just log to file and continue
            error_log("Failed to write job log to MongoDB: " . $e->getMessage());
        }

        // Also write to application log if error/warning
        if (in_array($level, ['warning', 'error'])) {
            Log::{$level}($message, $context);
        }
    }

    /**
     * Check if we should log at this level
     * 
     * @param string $level Log level
     * @return bool True if should log
     */
    private static function shouldLog(string $level): bool
    {
        $levelPriority = [
            'debug' => 0,
            'info' => 1,
            'warning' => 2,
            'error' => 3,
        ];

        $configPriority = [
            'DEBUG' => 0,
            'INFO' => 1,
            'WARNING' => 2,
            'ERROR' => 3,
        ];

        $levelPrio = $levelPriority[$level] ?? 1;
        $configPrio = $configPriority[self::$logLevel] ?? 1;

        return $levelPrio >= $configPrio;
    }

    /**
     * Format log line for file output
     * 
     * @param string $timestamp Timestamp string
     * @param string $level Log level
     * @param string $message Log message
     * @param array $context Additional context
     * @return string Formatted log line
     */
    private static function formatLogLine(string $timestamp, string $level, string $message, array $context): string
    {
        $levelUpper = strtoupper($level);
        $contextStr = '';
        
        if (!empty($context)) {
            $contextParts = [];
            foreach ($context as $key => $value) {
                if (is_scalar($value)) {
                    $contextParts[] = "{$key}={$value}";
                } else {
                    $contextParts[] = "{$key}=" . json_encode($value);
                }
            }
            $contextStr = ' [' . implode(' ', $contextParts) . ']';
        }

        return "[{$timestamp}] [{$levelUpper}] {$message}{$contextStr}\n";
    }

    /**
     * Write log line to file
     * 
     * @param string $logLine Formatted log line
     * @param string $level Log level
     * @return void
     */
    private static function writeToFile(string $logLine, string $level): void
    {
        $date = date('Y-m-d');
        $logFile = self::$logDir . '/job-' . $date . '.log';

        // Also write errors to error log
        if ($level === 'error') {
            $errorLogFile = self::$logDir . '/job-error-' . $date . '.log';
            @file_put_contents($errorLogFile, $logLine, FILE_APPEND | LOCK_EX);
        }

        @file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX);
    }

    /**
     * Convenience methods for different log levels
     */
    public static function debug(array $data): void
    {
        $data['level'] = 'debug';
        self::log($data);
    }

    public static function info(array $data): void
    {
        $data['level'] = 'info';
        self::log($data);
    }

    public static function warning(array $data): void
    {
        $data['level'] = 'warning';
        self::log($data);
    }

    public static function error(array $data): void
    {
        $data['level'] = 'error';
        self::log($data);
    }
}
