<?php

namespace Nraa\Exceptions;

use Nraa\Pillars\Log;
use Throwable;

class ExceptionHandler
{
    /**
     * Log an error message to a file (or other destination) when an uncaught exception occurs.
     * 
     * @param Throwable $exception The exception that was not caught.
     */
    public static function phpExceptionHandler(Throwable $exception)
    {
        // Log the error message
        $logMessage = "Error: " . $exception->getMessage() . "\n";
        $logMessage .= "File: " . $exception->getFile() . " (Line: " . $exception->getLine() . ")\n";

        // Get the stack trace
        $stackTrace = $exception->getTraceAsString();
        $logMessage .= "Stack Trace:\n" . $stackTrace . "\n";

        // Write the log message to a file (or other destination)
        Log::error($logMessage);

        // Optionally, display a user-friendly error page for production environments
        // For development, you might want to re-throw the exception or display more details
        if (ini_get('display_errors')) {
            dd($exception);
        }
    }

    /**
     * Catch PHP errors and convert them into an ErrorException.
     * 
     * This error handler is intended to be used with set_error_handler() and will catch
     * PHP errors of the specified severity. If an error occurs, it will be converted
     * into an ErrorException and re-thrown.
     * 
     * Note that this error handler will only catch errors that occur after it has been
     * registered with set_error_handler(). Any errors that occur before registration
     * will be handled by the default PHP error handler.
     * 
     * @param int $severity The severity of the error.
     * @param string $message The error message.
     * @param string $file The file where the error occurred.
     * @param int $line The line number where the error occurred.
     * 
     * @return bool True if the error was handled, false otherwise.
     */
    public static function phpErrorHandler($severity, $message, $file, $line)
    {
        if (error_reporting() & $severity) {
            // Convert the error into an ErrorException
            throw new \ErrorException($message, 0, $severity, $file, $line);
        }
        return false; // Let the default PHP error handler run for unhandled errors        
    }
}
