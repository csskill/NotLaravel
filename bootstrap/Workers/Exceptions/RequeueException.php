<?php

namespace Nraa\Workers\Exceptions;

/**
 * Exception thrown when a job should be returned to the queue without counting as a failure.
 * 
 * This is useful for jobs that encounter temporary resource unavailability (like locks)
 * and should be retried later without incrementing failure counts.
 */
class RequeueException extends \Exception
{
    public function __construct(string $message = "Job returned to queue", int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
