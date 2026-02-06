<?php

namespace Nraa\Filesystem;

class FileLogProvider
{

    protected string $path;
    protected string $level;
    protected string $channel;

    /**
     * Constructor for the FileLogProvider class.
     *
     * @param string $logLevel The log level for this log provider.
     * @param string $path The path to the log file.
     */
    function __construct(string $logLevel, string $path, string $channel)
    {
        $this->path = $path;
        $this->level = $logLevel;
        $this->channel = $channel;
    }

    /**
     * Writes a log message to the specified log file.
     *
     * The method takes a JSON encoded backtrace, a log level and a message as arguments and writes the log message to the specified log file.
     * If the log file does not exist, the method creates the file and writes the log message to it.
     * If the directory for the log file does not exist, the method creates the directory and writes the log message to the file.
     *
     * @param string $json_message The JSON encoded backtrace to write to the log file.
     * @param string $logLevel The log level to write the log message at.
     * @param string $message The message to log.
     */
    public function writeLog($json_message, $logLevel, $message): void
    {
        $filepath = $this->path . '.' . $this->channel;
        $directory = dirname($filepath);
        if (!file_exists($directory)) {
            mkdir($directory, 0775, true);
        }
        $dateTime = date('Y-m-d H:i:s', time());
        $logMessage = $dateTime . ' [' . $logLevel . '] ' . $message . ". \n Stacktrace for log: " . $json_message . "\r\n";
        file_put_contents($filepath, $logMessage, FILE_APPEND | LOCK_EX);
    }
}
