<?php

namespace Nraa\Events;

use \Nraa\Pillars\Events\Dispatchable;
use Nraa\Pillars\Events\Event;

class UserCreated extends Event
{
    use Dispatchable;

    public $user;

    function __construct(User $user)
    {
        $this->user = $user;
    }
}
