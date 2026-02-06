<?php

namespace Nraa\Database\Log;

use Nraa\Database\Drivers\MongoDBDriver;
use \MongoDB\BSON\UTCDateTime;

class MongoDbLogProvider
{

    protected $dbInstance;
    protected string $collection;

    /**
     * Converts a log message to a document that can be written to the MongoDB collection.
     *
     * The method takes a JSON encoded backtrace, a log level and a message as arguments and returns a document that can be written to the MongoDB collection.
     * The document contains the given log level, message and JSON encoded backtrace and the current datetime.
     *
     * @param string $json_message The JSON encoded backtrace to write to the log message.
     * @param string $logLevel The log level to write the log message at.
     * @param string $message The message to log.
     *
     * @return array
     */
    protected function converMessageToDbDocument($json_message, $logLevel, $message)
    {
        return [
            'logLevel'       => $logLevel,
            'message'        => $message,
            'jsonMessage'    => $json_message,
            'createdAt'      => new UTCDateTime()
        ];
    }

    /**
     * Constructs a new instance of the MongoDbLogProvider class.
     *
     * The method takes a log level and the name of the MongoDB collection to write the log messages to as arguments.
     * It sets the collection and database instance and is used internally by the class.
     *
     * @param string $logLevel The log level to write the log messages at.
     * @param string $collection The name of the MongoDB collection to write the log messages to.
     */
    function __construct(string $logLevel, string $collection)
    {
        $this->collection = $collection;
        $this->dbInstance = new MongoDBDriver();
    }

    /**
     * Writes a log message to the MongoDB collection.
     *
     * The method takes a JSON encoded backtrace, a log level and a message as arguments and writes the log message to the specified MongoDB collection.
     * If the log message does not exist, the method creates the message and writes it to the collection.
     * If the log message exists, the method updates the message with the new log level and message.
     *
     * @param string $json_message The JSON encoded backtrace to write to the log message.
     * @param string $logLevel The log level to write the log message at.
     * @param string $message The message to log.
     */
    public function writeLog($json_message, $message, $logLevel): void
    {
        $this->dbInstance->getCollection($this->collection)->insertOne($this->converMessageToDbDocument($json_message, $message, $logLevel), ['writeConcern' => new \MongoDB\Driver\WriteConcern(0)]);
    }
}
