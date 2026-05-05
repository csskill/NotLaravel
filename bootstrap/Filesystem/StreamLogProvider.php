<?php

namespace Nraa\Filesystem;

class StreamLogProvider
{
    protected string $stream;
    protected string $channel;

    public function __construct(string $logLevel, string $stream, string $channel)
    {
        $this->stream = trim($stream) !== '' ? trim($stream) : 'auto';
        $this->channel = $channel;
    }

    public function writeLog($json_message, $logLevel, $message): void
    {
        $logLevel = strtolower((string)$logLevel);
        $dateTime = date('Y-m-d H:i:s');
        $line = sprintf(
            "%s [%s] [%s] %s",
            $dateTime,
            strtoupper($logLevel),
            $this->channel,
            (string)$message
        );

        $jsonMessage = trim((string)$json_message);
        if ($jsonMessage !== '') {
            $line .= ' Stacktrace for log: ' . $jsonMessage;
        }

        $stream = $this->resolveStream($logLevel);
        @file_put_contents($stream, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    private function resolveStream(string $logLevel): string
    {
        $configured = strtolower($this->stream);
        if (in_array($configured, ['stdout', 'stderr'], true)) {
            return $configured === 'stderr' ? 'php://stderr' : 'php://stdout';
        }

        if (in_array($logLevel, ['warning', 'error', 'critical', 'alert', 'emergency'], true)) {
            return 'php://stderr';
        }

        return 'php://stdout';
    }
}
