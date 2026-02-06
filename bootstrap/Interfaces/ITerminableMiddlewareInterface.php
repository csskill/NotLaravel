<?php

namespace Nraa\Interfaces;

use Nraa\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Closure;

interface ITerminableMiddlewareInterface
{
    public function terminate(Request $request, Closure $closure): Response;
}
