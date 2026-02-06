<?php

/**
 * Service Configuration
 * 
 * Centralizes service URLs and connection settings.
 * All service URLs can be overridden via environment variables.
 * 
 * Note: Docker detection and service discovery logic is handled by application services
 * (e.g., GoDemoParserService), not in this config file.
 */

return [
    /**
     * Go Parser Service
     * 
     * HTTP service for parsing CS2 demo files.
     * URL defaults to localhost for development.
     * Docker detection and service discovery is handled by GoDemoParserService.
     */
    'go_parser' => [
        'url' => $_ENV['GO_PARSER_SERVICE_URL'] ?? 'http://127.0.0.1:8080',
        'timeout' => (int)($_ENV['GO_PARSER_TIMEOUT'] ?? 300), // 5 minutes default
        'health_check_timeout' => (int)($_ENV['GO_PARSER_HEALTH_CHECK_TIMEOUT'] ?? 5),
    ],

    /**
     * MongoDB Connection
     * 
     * MongoDB connection string for database access.
     * Uses MONGODB_CONNECTION_STRING environment variable if set.
     */
    'mongodb' => [
        'connection_string' => $_ENV['MONGODB_CONNECTION_STRING'] ?? 'mongodb://localhost:27017',
    ],
];
