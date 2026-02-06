<?php

namespace Nraa\Pillars;

use Exception;
use Nraa\Database\Log\MongoDbLogProvider;
use Nraa\Filesystem\FileLogProvider;

class Logging
{

    protected $config = [];
    protected $channels = [];

    protected $logLevels = [
        'debug' => 0,
        'info' => 1,
        'notice' => 2,
        'warning' => 3,
        'error' => 4,
        'critical' => 5,
        'alert' => 6,
        'emergency' => 7,
    ];

    public static ?Logging $instance = null;
    private $logProviderInstances = [];

    /**
     * Gets the singleton instance of the Logging class.
     *
     * @return Logging
     *
     * @throws Exception If Logging has not been instantiated with a configuration before it can be used.
     */
    public static function getInstance(): Logging
    {
        if (static::$instance === null) {
            throw new Exception("Logging needs to be instantiated with a configuration before it can be used.");
        }
        return static::$instance;
    }

    /**
     * Ensures that a channel configuration has all the required settings.
     *
     * If a setting is missing from the channel configuration, it is set to its default value.
     *
     * The settings that are checked are:
     *
     * - 'level': The log level that the channel should log at. Defaults to 'debug'.
     * - 'backtrace': Whether the channel should log a backtrace when logging. Defaults to true.
     * - 'backtraceLevels': The number of levels of the backtrace that should be logged. Defaults to 2.
     *
     * @param array $channelConfig The channel configuration to ensure has all the required settings.
     * @return array The channel configuration with all the required settings.
     */
    protected function ensureChannelHasSetorDefaultValues($channelConfig)
    {
        if (!isset($channelConfig['level'])) {
            $channelConfig['level'] = 'debug';
        }
        if (!isset($channelConfig['backtrace'])) {
            $channelConfig['backtrace'] = true;
        }
        if (!isset($channelConfig['backtraceLevels'])) {
            $channelConfig['backtraceLevels'] = 2;
        }
        return $channelConfig;
    }

    /**
     * Instantiates the Logging class.
     *
     * The Logging class is responsible for logging messages to configured channels.
     * A channel can be configured to log messages to a file or a MongoDB database.
     * The Logging class also provides functionality to check if a log level is applicable to a channel.
     *
     * @param string $basePath The base path of the application.
     * @param array $configuration The configuration for the Logging class.
     */
    function __construct($basePath = '', $configuration = [])
    {
        static::$instance = $this;
        $this->config = $configuration;
        foreach ($this->config as $channel => $channelConfig) {
            $this->channels[$channel] = $this->ensureChannelHasSetorDefaultValues($channelConfig);
            if ($channelConfig['driver'] == 'file') {
                $this->logProviderInstances[$channel] = new FileLogProvider($channelConfig['driver'], $basePath . $channelConfig['path'], $channel);
            }
            if ($channelConfig['driver'] == 'mongodb') {
                $this->logProviderInstances[$channel] = new MongoDbLogProvider($channelConfig['driver'], $channelConfig['table']);
            }
        }
    }

    /**
     * Checks if a log level is applicable to a channel.
     *
     * The method compares the log level with the channel's log level and returns true if the log level is applicable to the channel.
     *
     * @param string $level The log level to check.
     * @param string $channelLevel The log level of the channel to check against.
     * @return bool True if the log level is applicable to the channel, false otherwise.
     */
    public function isLogLevelApplicable(string $level, string $channelLevel): bool
    {
        $levelInt = $this->getLogLevelInt($level);
        $channelLevelInt = $this->getLogLevelInt($channelLevel);

        return $levelInt >= $channelLevelInt;
    }

    /**
     * Returns the configuration for the given channel.
     *
     * @param string $channel The name of the channel to retrieve the configuration for.
     * @return array The configuration for the given channel.
     */
    public function getChannel($channel)
    {
        return $this->channels[$channel];
    }

    /**
     * Returns the integer value of the given log level.
     *
     * The method takes a string log level as an argument and returns the corresponding integer value.
     * If the given log level is not recognized, the method returns 0.
     *
     * The recognized log levels are:
     * - debug
     * - info
     * - notice
     * - warning
     * - error
     * - critical
     * - alert
     * - emergency
     *
     * @param string $level The log level to get the integer value for.
     * @return int The integer value of the given log level.
     */
    private function getLogLevelInt(string $level): int
    {
        $logLevels = [
            'debug' => 0,
            'info' => 1,
            'notice' => 2,
            'warning' => 3,
            'error' => 4,
            'critical' => 5,
            'alert' => 6,
            'emergency' => 7,
        ];

        return $logLevels[$level] ?? 0;
    }

    /**
     * Writes a log message to all the configured providers that are applicable to the given log level.
     *
     * The method takes a log level and a message as arguments and writes the log message to all the configured providers that are applicable to the given log level.
     * If a channel is configured to log a backtrace, the method logs the backtrace along with the message.
     * If a channel is not configured to log a backtrace, the method logs only the message.
     *
     * @param string $logLevel The log level to write the log message at.
     * @param mixed $message The message to log.
     */
    public function writeLogForConfiguredProviders(string $logLevel, $message)
    {
        foreach ($this->channels as $channel_name => $conf) {
            if ($this->isLogLevelApplicable($logLevel, $this->channels[$channel_name]['level'])) {
                if ($this->channels[$channel_name]['backtrace']) {
                    $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, $this->channels[$channel_name]['backtraceLevels']);
                    $logMessage = new \stdClass();
                    $logMessage->message = $message;
                    $logMessage->trace = $trace;
                    $json_message = json_encode($logMessage);
                    $this->logProviderInstances[$channel_name]->writeLog($json_message, $logLevel, $message);
                } else {
                    $this->logProviderInstances[$channel_name]->writeLog("", $logLevel, $message);
                }
            }
        }
    }

    /**
     * Writes a log message to a specific channel.
     *
     * The method takes the name of the channel, a message and a log level as arguments and writes the log message to the specified channel.
     * The method uses the configured log provider for the given channel to write the log message.
     *
     * @param string $channel The name of the channel to write the log message to.
     * @param mixed $message The message to log.
     * @param string $logLevel The log level to write the log message at.
     */
    public function writeLogForSpecificChannel($channel, $message, $logLevel)
    {
        $instance = $this->logProviderInstances[$channel];
        $instance->writeLog($message, $logLevel);
    }
}
