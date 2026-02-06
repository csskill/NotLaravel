<?php

namespace Nraa\Interfaces;

use Nraa\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Closure;

interface IMiddlewareInterface
{
    public function callAction(Request $request, Closure $closure): Response;
}
