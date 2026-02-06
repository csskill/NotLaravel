<?php

namespace Nraa\Helpers;

/**
 * Helper class to manage MongoDB indexes for all models
 * This ensures indexes are created during application bootstrap
 */
class IndexManager
{
    /**
     * List of all model classes that should have indexes ensured
     * Only includes models extending Model (not MongoDBObject)
     */
    private static array $modelClasses = [
        \Nraa\Models\Notification::class,
    ];

    /**
     * Ensure indexes for all registered models
     * This should be called during application bootstrap
     * 
     * @param bool $suppressErrors If true, errors will be logged instead of thrown
     */
    public static function ensureAllIndexes(bool $suppressErrors = true): void
    {
        foreach (self::$modelClasses as $class) {
            try {
                if (method_exists($class, 'ensureIndexes')) {
                    $instance = new $class();
                    $instance->ensureIndexes();
                }
            } catch (\Exception $e) {
                if ($suppressErrors) {
                    error_log("Failed to ensure indexes for $class: " . $e->getMessage());
                } else {
                    throw $e;
                }
            }
        }
    }

    /**
     * Register a new model class for index management
     * Call this when adding new models dynamically
     */
    public static function registerModel(string $className): void
    {
        if (!in_array($className, self::$modelClasses)) {
            self::$modelClasses[] = $className;
        }
    }

    /**
     * Get all registered model classes
     */
    public static function getRegisteredModels(): array
    {
        return self::$modelClasses;
    }
}
