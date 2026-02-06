<?php

namespace Nraa\Http;

use Symfony\Component\HttpFoundation\Request as SymfonyRequest;


class Request extends SymfonyRequest
{
    /**
     * Construct a new request from the superglobals.
     *
     * @link https://symfony.com/doc/current/components/http_fundamentals.html#accessing-request-data
     * @return void
     */
    function __construct()
    {
        parent::__construct(
            $_GET,
            $_POST,
            [],
            $_COOKIE,
            $_FILES,
            $_SERVER
        );
    }
}
