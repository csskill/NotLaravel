<?php

namespace Nraa\Workers;

/**
 * Pool Manager
 * 
 * Manages worker pool configurations and job-to-pool assignments.
 * Loads pool definitions from config file and provides methods to
 * determine which pool should handle a given job class.
 * 
 * @package Nraa\Workers
 */
class PoolManager
{
    private array $pools;
    private array $jobClassToPool = [];

    /**
     * Constructor
     * 
     * Loads pool configurations and builds job class index
     */
    public function __construct()
    {
        $this->loadPools();
        $this->buildJobClassIndex();
    }

    /**
     * Load pool configurations from config file
     * 
     * @throws \RuntimeException If config file is missing or invalid
     */
    private function loadPools(): void
    {
        $configPath = __DIR__ . '/../../app/config/worker_pools.php';

        if (!file_exists($configPath)) {
            throw new \RuntimeException(
                "Worker pools config file not found: $configPath"
            );
        }

        $this->pools = require $configPath;

        if (!is_array($this->pools) || empty($this->pools)) {
            throw new \RuntimeException(
                'Worker pools config must return a non-empty array'
            );
        }

        $this->validatePools();
    }

    /**
     * Validate pool configurations
     * 
     * Ensures all required fields are present and valid
     * 
     * @throws \RuntimeException If validation fails
     */
    private function validatePools(): void
    {
        $requiredFields = ['name', 'description', 'workers', 'capacity', 'timeout', 'jobs'];

        foreach ($this->pools as $poolName => $config) {
            foreach ($requiredFields as $field) {
                if (!isset($config[$field])) {
                    throw new \RuntimeException(
                        "Pool '$poolName' is missing required field: $field"
                    );
                }
            }

            if (!is_int($config['workers']) || $config['workers'] < 1) {
                throw new \RuntimeException(
                    "Pool '$poolName' must have at least 1 worker"
                );
            }

            if (!is_int($config['capacity']) || $config['capacity'] < 1) {
                throw new \RuntimeException(
                    "Pool '$poolName' must have capacity >= 1"
                );
            }

            if (!is_int($config['timeout']) || $config['timeout'] < 1) {
                throw new \RuntimeException(
                    "Pool '$poolName' must have timeout >= 1 second"
                );
            }

            if (!is_array($config['jobs'])) {
                throw new \RuntimeException(
                    "Pool '$poolName' jobs must be an array"
                );
            }
        }
    }

    /**
     * Build job class to pool name index
     * 
     * Creates a mapping of job class names to pool names for fast lookups.
     * Validates that no job class is assigned to multiple pools.
     * 
     * @throws \RuntimeException If a job class is assigned to multiple pools
     */
    private function buildJobClassIndex(): void
    {
        foreach ($this->pools as $poolName => $config) {
            foreach ($config['jobs'] as $jobClass) {
                if (isset($this->jobClassToPool[$jobClass])) {
                    throw new \RuntimeException(
                        "Job class '$jobClass' is assigned to multiple pools: " .
                            "'{$this->jobClassToPool[$jobClass]}' and '$poolName'"
                    );
                }
                $this->jobClassToPool[$jobClass] = $poolName;
            }
        }
    }

    /**
     * Get pool name for a job class
     * 
     * Returns the pool that should handle the given job class.
     * If the job class is not explicitly assigned to any pool,
     * returns 'general' as the default pool.
     * 
     * @param string $jobClass Fully qualified job class name
     * @return string Pool name
     */
    public function getPoolForJobClass(string $jobClass): string
    {
        // Direct mapping
        if (isset($this->jobClassToPool[$jobClass])) {
            return $this->jobClassToPool[$jobClass];
        }

        // Fall back to general pool for unassigned jobs
        return 'general';
    }

    /**
     * Get configuration for a specific pool
     * 
     * @param string $poolName Pool name
     * @return array Pool configuration
     * @throws \InvalidArgumentException If pool doesn't exist or is not enabled
     */
    public function getPoolConfig(string $poolName): array
    {
        if (!isset($this->pools[$poolName])) {
            throw new \InvalidArgumentException(
                "Pool '$poolName' not found. Available pools: " .
                    implode(', ', array_keys($this->pools))
            );
        }
        
        // Check if pool is enabled via WORKER_POOLS environment variable
        $enabledPoolsEnv = $_ENV['WORKER_POOLS'] ?? null;
        if ($enabledPoolsEnv !== null && $enabledPoolsEnv !== '') {
            $enabledPoolNames = array_map('trim', explode(',', $enabledPoolsEnv));
            if (!in_array($poolName, $enabledPoolNames)) {
                throw new \InvalidArgumentException(
                    "Pool '$poolName' is not enabled in this container. " .
                    "Enabled pools: " . implode(', ', $enabledPoolNames)
                );
            }
        }
        
        return $this->pools[$poolName];
    }

    /**
     * Get enabled pools based on WORKER_POOLS environment variable
     * 
     * If WORKER_POOLS is set, returns only those pools.
     * Otherwise, returns all pools.
     * 
     * @return array Enabled pool configurations indexed by pool name
     */
    public function getEnabledPools(): array
    {
        $enabledPoolsEnv = $_ENV['WORKER_POOLS'] ?? null;
        
        if ($enabledPoolsEnv === null || $enabledPoolsEnv === '') {
            // No filter - return all pools
            return $this->pools;
        }
        
        // Parse comma-separated list and filter
        $enabledPoolNames = array_map('trim', explode(',', $enabledPoolsEnv));
        $enabledPoolNames = array_filter($enabledPoolNames); // Remove empty strings
        
        if (empty($enabledPoolNames)) {
            throw new \RuntimeException(
                'WORKER_POOLS environment variable is set but contains no valid pool names'
            );
        }
        
        $filteredPools = [];
        $invalidPools = [];
        
        foreach ($enabledPoolNames as $poolName) {
            if (isset($this->pools[$poolName])) {
                $filteredPools[$poolName] = $this->pools[$poolName];
            } else {
                $invalidPools[] = $poolName;
            }
        }
        
        if (!empty($invalidPools)) {
            throw new \RuntimeException(
                'Invalid pool names in WORKER_POOLS: ' . implode(', ', $invalidPools) . 
                '. Available pools: ' . implode(', ', array_keys($this->pools))
            );
        }
        
        return $filteredPools;
    }

    /**
     * Get all pool configurations (unfiltered)
     * 
     * @return array All pool configurations indexed by pool name
     */
    public function getAllPools(): array
    {
        return $this->pools;
    }

    /**
     * Get list of all pool names
     * 
     * @return array Pool names
     */
    public function getPoolNames(): array
    {
        return array_keys($this->pools);
    }

    /**
     * Check if a pool exists
     * 
     * @param string $poolName Pool name to check
     * @return bool True if pool exists
     */
    public function poolExists(string $poolName): bool
    {
        return isset($this->pools[$poolName]);
    }

    /**
     * Get total worker count across all pools
     * 
     * @return int Total number of workers
     */
    public function getTotalWorkerCount(): int
    {
        $total = 0;
        foreach ($this->pools as $config) {
            $total += $config['workers'];
        }
        return $total;
    }

    /**
     * Get worker count for a specific pool
     * 
     * @param string $poolName Pool name
     * @return int Number of workers in the pool
     * @throws \InvalidArgumentException If pool doesn't exist
     */
    public function getWorkerCount(string $poolName): int
    {
        $config = $this->getPoolConfig($poolName);
        return $config['workers'];
    }

    /**
     * Get job classes assigned to a specific pool
     * 
     * @param string $poolName Pool name
     * @return array Job class names
     * @throws \InvalidArgumentException If pool doesn't exist
     */
    public function getPoolJobClasses(string $poolName): array
    {
        $config = $this->getPoolConfig($poolName);
        return $config['jobs'];
    }

    /**
     * Get worker configuration for a specific worker in a pool
     * 
     * Calls the pool's worker_config_provider (if defined) to get
     * worker-specific configuration based on the worker index.
     * 
     * @param string $poolName Pool name
     * @param int $workerIndex Worker index within the pool (0-based)
     * @return array Worker configuration (empty if no provider defined)
     * @throws \InvalidArgumentException If pool doesn't exist
     * @throws \RuntimeException If config provider fails
     */
    public function getWorkerConfig(string $poolName, int $workerIndex): array
    {
        $config = $this->getPoolConfig($poolName);

        // If no config provider, return empty config
        if (!isset($config['worker_config_provider']) || $config['worker_config_provider'] === null) {
            return [];
        }

        // Call the config provider function
        $provider = $config['worker_config_provider'];
        if (!is_callable($provider)) {
            throw new \RuntimeException(
                "Pool '$poolName' has invalid worker_config_provider (must be callable)"
            );
        }

        try {
            $workerConfig = $provider($workerIndex);

            if (!is_array($workerConfig)) {
                throw new \RuntimeException(
                    "worker_config_provider for pool '$poolName' must return an array"
                );
            }

            return $workerConfig;
        } catch (\Exception $e) {
            throw new \RuntimeException(
                "Failed to get worker config for pool '$poolName' worker $workerIndex: " .
                    $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Get pool statistics summary
     * 
     * @return array Statistics for all pools
     */
    public function getPoolStats(): array
    {
        $stats = [];
        foreach ($this->pools as $poolName => $config) {
            $stats[$poolName] = [
                'name' => $config['name'],
                'workers' => $config['workers'],
                'capacity' => $config['capacity'],
                'total_capacity' => $config['workers'] * $config['capacity'],
                'timeout' => $config['timeout'],
                'job_classes' => count($config['jobs']),
                'has_worker_config' => isset($config['worker_config_provider']) && $config['worker_config_provider'] !== null,
            ];
        }
        return $stats;
    }
}
