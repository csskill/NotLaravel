<?php

namespace Nraa\Pillars\Events;

class PendingDispatch
{

    public $job;

    /**
     * Construct a new PendingDispatch instance.
     *
     * @param mixed $job The job to dispatch later.
     */
    function __construct($job)
    {
        $this->job = $job;
    }


    /**
     * Dynamically proxy methods to the underlying job.
     *
     * @param  string  $method
     * @param  array  $parameters
     * @return $this
     */
    public function __call($method, $parameters)
    {

        $this->job->handle(...$parameters);

        return $this;
    }
}
