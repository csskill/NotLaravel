<?php

namespace Nraa\Database\Traits;

trait HasEvents
{
    /**
     * Notify any event listeners about an event that is about to take place and pass along an instance of the object that triggered the event.
     *
     * @param string $event The name of the event to notify listeners about.
     * @param mixed $object An instance of the object that triggered the event.
     */
    protected function notifyEvent($event, $object = null): void
    {
        // Will notify any listeners about an event that is about to take place and pass along an instance of the object that triggered the event
    }

    /**
     * Check if a Model object has any event listeners and perform the check.
     * These have the posibility to override the outcome of an action by returning true or false
     *
     * @param string $event The name of the event to check for
     * @param bool $halt Whether or not to halt execution if an event listener returns false
     * @return bool Whether or not the event listeners allowed the action to proceed
     */
    protected function fireEvent($event, $halt = true): bool
    {
        // This will check if a Model object has any event listeners and perform the check. 
        // These have the posibility to override the outcome of an action by returning true or false
        return true;
    }
}
