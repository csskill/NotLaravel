<?php

namespace Nraa\Workers\Exceptions;

/**
 * Exception thrown when a job reaches a terminal, non-actionable state and
 * should be auto-resolved rather than marked failed.
 */
class AutoResolveException extends \Exception
{
    public function __construct(string $message = 'Job auto-resolved', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
