<?php

namespace Nraa\Pillars;

class Log
{

    protected static $channel = "";

    /**
     * Override function for creating logs to a specific channel
     *
     * This method sets the channel that will be used for logging messages.
     * The channel should be a string that matches one of the channels configured in the logging configuration.
     *
     * @param string $channel The name of the channel to set.
     * @return self The instance of this class with the channel set.
     */
    public static function channel(string $channel): self
    {
        $instance = new self();
        $instance->channel = $channel;
        return $instance;
    }

    /**
     * Writes a log message to a specific channel or all the configured providers.
     *
     * If no channel is specified, the method writes the log message to all the configured providers that are applicable to the given log level.
     * If a channel is specified, the method writes the log message to the specified channel.
     *
     * @param string $logLevel The log level to write the log message at.
     * @param string $message The message to log.
     */
    protected static function writeLog(string $logLevel, string $message): void
    {
        $logging = Logging::getInstance();
        if (static::$channel == "") {
            $logging->writeLogForConfiguredProviders($logLevel, $message);
        } else {
            $logging->writeLogForSpecificChannel(static::$channel, $message, $logLevel);
        }
    }

    /**
     * Writes a debug log message to the configure channels
     * 
     * @param string $message The message to log.
     */
    public static function debug(string $message, array $context = []): void
    {
        if (!empty($context)) {
            $message .= ' Context: ' . json_encode($context);
        }
        self::writeLog('debug', $message);
    }

    /**
     * Writes an info log message to the configured channels.
     *
     * @param string $message The message to log.
     */
    public static function info(string $message, array $context = []): void
    {
        if (!empty($context)) {
            $message .= ' Context: ' . json_encode($context);
        }
        self::writeLog('info', $message);
    }

    /**
     * Writes a notice log message to the configured channels.
     *
     * @param string $message The message to log.
     */
    public static function notice(string $message, array $context = []): void
    {
        if (!empty($context)) {
            $message .= ' Context: ' . json_encode($context);
        }
        self::writeLog('notice', $message);
    }

    /**
     * Writes a warning log message to the configured channels.
     *
     * @param string $message The message to log.
     */
    public static function warning(string $message, array $context = []): void
    {
        if (!empty($context)) {
            $message .= ' Context: ' . json_encode($context);
        }
        self::writeLog('warning', $message);
    }
    /**
     * Writes an error log message to the configured channels.
     *
     * @param string $message The message to log.
     */
    public static function error(string $message, array $context = []): void
    {
        if (!empty($context)) {
            $message .= ' Context: ' . json_encode($context);
        }
        self::writeLog('error', $message);
    }

    /**
     * Writes a critical log message to the configured channels.
     *
     * @param string $message The message to log.
     */
    public static function critical(string $message, array $context = []): void
    {
        if (!empty($context)) {
            $message .= ' Context: ' . json_encode($context);
        }
        self::writeLog('critical', $message);
    }

    /**
     * Writes an alert log message to the configured channels.
     *
     * @param string $message The message to log.
     */
    public static function alert(string $message, array $context = []): void
    {
        if (!empty($context)) {
            $message .= ' Context: ' . json_encode($context);
        }
        self::writeLog('alert', $message);
    }

    /**
     * Writes an emergency log message to the configured channels.
     * 
     * An emergency log level is the highest log level and should only be used for critical errors that require immediate attention.
     * 
     * @param string $message The message to log.
     */
    public static function emergency(string $message, array $context = []): void
    {
        if (!empty($context)) {
            $message .= ' Context: ' . json_encode($context);
        }
        self::writeLog('emergency', $message);
    }
}
