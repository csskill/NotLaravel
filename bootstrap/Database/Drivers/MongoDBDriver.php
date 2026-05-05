<?php

namespace Nraa\Database\Drivers;


use \MongoDB\Client;
use \MongoDB\Driver\ReadPreference;
use \MongoDB\Driver;
use \MongoDB\BulkWrite;
use \MongoDB\Operation\ClientBulkWriteCommand;
use \MongoDB\Operation\Delete;
use \MongoDB\Operation\Insert;
use \MongoDB\Operation\Update;
use \MongoDB\Operation\Find;
use MongoDB\Driver\Manager;
use \Nraa\Pillars\Log;

class MongoDBDriver
{
    public const CONNECTION_PRIMARY = 'primary';
    public const CONNECTION_WEB_READ = 'web_read';

    /** @var array<string,self> */
    private static array $instances = [];
    private Client $client;
    private \MongoDB\Database $db;
    private string $connectionName;

    /**
     * Initializes a new instance of the MongoDBDriver class.
     *
     * @param string $uri The connection URI for the MongoDB instance.
     * @param string $dbName The name of the database to use.
     */
    function __construct(?string $uri = null, ?string $dbName = null, string $connectionName = self::CONNECTION_PRIMARY)
    {
        $resolved = self::resolveConnectionConfig($uri, $dbName, $connectionName);
        $this->client = new Client($resolved['uri']);
        $this->db = $this->client->selectDatabase($resolved['database']);
        $this->connectionName = $resolved['connection_name'];
    }

    /**
     * Returns a singleton instance of the MongoDBDriver class.
     *
     * If the instance does not already exist, it will be created with the given
     * connection URI and database name.
     *
     * @param string $uri The connection URI for the MongoDB instance.
     * @param string $dbName The name of the database to use.
     *
     * @return self The singleton instance of the MongoDBDriver class.
     */
    public static function getInstance(?string $uri = null, ?string $dbName = null, string $connectionName = self::CONNECTION_PRIMARY): self
    {
        $resolvedName = self::normalizeConnectionName($connectionName);
        if (!isset(self::$instances[$resolvedName])) {
            self::$instances[$resolvedName] = new MongoDBDriver($uri, $dbName, $resolvedName);
        }
        return self::$instances[$resolvedName];
    }

    /**
     * Returns the name of the database that the driver is connected to.
     *
     * @return string The name of the database.
     */
    public function getDatabaseName()
    {
        return $this->db->getDatabaseName();
    }

    /**
     * Returns the MongoDB\Database instance associated with this driver.
     */
    public function getDatabase(): \MongoDB\Database
    {
        return $this->db;
    }

    /**
     * Returns the MongoDB\Driver\Manager instance associated with this MongoDBDriver.
     *
     * The manager is used to perform operations on the MongoDB instance.
     *
     * @return MongoDB\Driver\Manager The manager instance.
     */
    public static function getManager(string $connectionName = self::CONNECTION_PRIMARY): Manager
    {
        $instance = static::getInstance(connectionName: $connectionName);
        return $instance->client->getManager();
    }

    /**
     * Resolve MongoDB connection details from explicit args, config, and env.
     *
     * @return array{uri:string,database:string,connection_name:string}
     */
    private static function resolveConnectionConfig(?string $uri, ?string $dbName, string $connectionName): array
    {
        $config = [];
        $configPath = null;

        if (function_exists('app')) {
            try {
                $configPath = app()->getAppPath() . '/config/database.php';
            } catch (\Throwable $e) {
                $configPath = null;
            }
        }

        if (!is_string($configPath) || $configPath === '' || !is_file($configPath)) {
            $configPath = dirname(__DIR__, 4) . '/app/config/database.php';
        }

        if (is_file($configPath)) {
            $loaded = require $configPath;
            if (is_array($loaded)) {
                $config = $loaded;
            }
        }

        $normalizedName = self::normalizeConnectionName($connectionName);
        $connectionKey = match ($normalizedName) {
            self::CONNECTION_WEB_READ => 'mongodb_web_read',
            default => 'mongodb_primary',
        };

        $mongodb = is_array($config['connections'][$connectionKey] ?? null)
            ? $config['connections'][$connectionKey]
            : (is_array($config['connections']['mongodb'] ?? null) ? $config['connections']['mongodb'] : []);

        $resolvedUri = trim((string)($uri
            ?? $mongodb['uri']
            ?? $mongodb['connection_string']
            ?? $_ENV['MONGODB_CONNECTION_STRING']
            ?? $_ENV['MONGODB_URI']
            ?? 'mongodb://localhost:27017'));
        if ($resolvedUri === '') {
            $resolvedUri = 'mongodb://localhost:27017';
        }

        $resolvedDb = trim((string)($dbName
            ?? $mongodb['database']
            ?? $_ENV['MONGODB_DATABASE']
            ?? 'nraa'));
        if ($resolvedDb === '') {
            $resolvedDb = 'nraa';
        }

        return [
            'uri' => $resolvedUri,
            'database' => $resolvedDb,
            'connection_name' => $normalizedName,
        ];
    }

    public static function normalizeConnectionName(?string $connectionName): string
    {
        $normalized = strtolower(trim((string)$connectionName));
        return $normalized === self::CONNECTION_WEB_READ ? self::CONNECTION_WEB_READ : self::CONNECTION_PRIMARY;
    }

    public static function extractConnectionName(array &$options): string
    {
        $connectionName = $options['connection'] ?? self::CONNECTION_PRIMARY;
        unset($options['connection']);

        return self::normalizeConnectionName(is_string($connectionName) ? $connectionName : self::CONNECTION_PRIMARY);
    }

    public function getConnectionName(): string
    {
        return $this->connectionName;
    }

    /**
     * Performs a bulk insert of documents into the specified collection.
     *
     * @param string $collection_name The name of the collection to insert into.
     * @param array $data An array of associative arrays with keys matching the
     *                    properties of the documents to insert.
     *
     * @return array An array of inserted document IDs.
     */
    public function bulkInsert($collection_name, $data): mixed
    {
        Log::debug('Performing bulkInsert on collection: ' . $collection_name);
        if ($this->requiresBulkNormalization($data)) {
            $data = json_decode(json_encode($data), true);
        }
        $insertResults = $this->getCollection($collection_name)->insertMany($data, ['ordered' => false, 'rawResult' => true]);
        Log::debug('Result of bulkInsert: ' . (count($insertResults->getInsertedIds()) > 0 ? 'success' : 'failure'));
        return $insertResults->getInsertedIds();
    }


    /**
     * Returns the MongoDB\Collection instance associated with the given collection name.
     *
     * @param string $collectionName The name of the collection to retrieve.
     *
     * @return \MongoDB\Collection The collection instance associated with the given collection name.
     */
    public function getCollection($collectionName): \MongoDB\Collection
    {
        return $this->db->$collectionName;
    }


    /**
     * Inserts a single document into the specified collection.
     *
     * @param string $collectionName The name of the collection to insert into.
     * @param array $document The document to insert into the collection.
     *
     * @return \MongoDB\InsertOneResult The result of the insert operation.
     */
    public function insert($collectionName, $document)
    {
        Log::debug('Performing insert on collection: ' . $collectionName);
        Log::debug('Document: ' . json_encode($document));
        $result = $this->getCollection($collectionName)->insertOne($document);
        Log::debug('Result of insert: ' . ($result->getInsertedCount() > 0 ? 'success' : 'failure'));
        return $result;
    }


    /**
     * Finds documents in the collection that match the filter.
     *
     * @param string $collectionName The name of the collection to find in.
     * @param array $filter A filter to match the documents.
     * @param array $options An array of options for the find operation.
     *
     * @return \MongoDB\Driver\Cursor The result of the find operation.
     */
    public function find($collectionName, $filter = [], $options = [])
    {
        return $this->getCollection($collectionName)->find($filter, $options);
    }


    /**
     * Finds a single document in the collection that matches the filter.
     *
     * @param string $collectionName The name of the collection to find in.
     * @param array $filter A filter to match the document.
     * @param array $options An array of options for the findOne operation.
     *
     * @return array|object|null The document that was found, or null if no document was found.
     */
    public function findOne($collectionName, $filter = [], $options = [])
    {
        $result = $this->getCollection($collectionName)->findOne($filter, $options);
        if ($result instanceof \MongoDB\Model\BSONDocument) {
            return (object)$result->getArrayCopy();
        }
        return $result;
    }


    /**
     * Updates documents in the collection that match the filter.
     *
     * @param string $collectionName The name of the collection to update in.
     * @param array $filter A filter to match the documents to update.
     * @param array $update The update rules.
     * @param array $options An array of options for the update operation.
     *
     * @return \MongoDB\Driver\WriteResult The result of the update operation.
     */
    public function update($collectionName, $filter, $update, $options = [])
    {
        Log::debug('Performing update on collection: ' . $collectionName);
        Log::debug('Filter: ' . json_encode($filter));
        Log::debug('Update: ' . json_encode($update));
        $result = $this->getCollection($collectionName)->updateMany($filter, $update, $options);
        Log::debug('Result of update: ' . ($result->getModifiedCount() > 0 ? 'success' : 'failure'));
        return $result;
    }


    /**
     * Updates a single document in the collection that matches the filter.
     *
     * @param string $collectionName The name of the collection to update in.
     * @param array $filter A filter to match the document to update.
     * @param array $update The update rules.
     * @param array $options An array of options for the update operation.
     *
     * @return \MongoDB\Driver\WriteResult The result of the update operation.
     */
    public function updateOne($collectionName, $filter, $update, $options = [])
    {
        Log::debug('Performing updateOne on collection: ' . $collectionName);
        Log::debug('Filter: ' . json_encode($filter));
        Log::debug('Update: ' . json_encode($update));
        $result = $this->getCollection($collectionName)->updateOne($filter, $update, $options);
        Log::debug('Result of updateOne: ' . ($result->getModifiedCount() > 0 ? 'success' : 'failure'));
        return $result;
    }


    /**
     * Deletes documents from the collection that match the filter.
     *
     * @param string $collectionName The name of the collection to delete from.
     * @param array $filter A filter to match the documents to delete.
     * @param array $options An array of options for the delete operation.
     *
     * @return \MongoDB\Driver\WriteResult The result of the delete operation.
     */
    public function delete($collectionName, $filter, $options = [])
    {
        Log::debug('Performing delete on collection: ' . $collectionName);
        Log::debug('Filter: ' . json_encode($filter));
        if (empty($filter)) {
            Log::alert('Filter is empty');
        }
        $result = $this->getCollection($collectionName)->deleteMany($filter, $options);
        Log::debug('Result of delete: ' . ($result->getDeletedCount() > 0 ? 'success' : 'failure'));
        return $result;
    }

    private function requiresBulkNormalization(mixed $data): bool
    {
        if (!is_array($data)) {
            return false;
        }

        foreach ($data as $document) {
            if (is_object($document)) {
                return true;
            }

            if (!is_array($document)) {
                continue;
            }

            foreach ($document as $value) {
                if (is_object($value)) {
                    return true;
                }
            }
        }

        return false;
    }
}
