<?php

namespace Nraa\Http;

use \Nraa\Interfaces\ITerminableMiddlewareInterface;
use \Nraa\Interfaces\IMiddlewareInterface;
use \Nraa\Pillars\Log;

final class HttpRequest
{

    /** @var HttpRequest */
    protected static $_instance;

    protected $middleware = [];

    protected Request $request;
    protected Response $response;

    /** @var array Route parameters from the router */
    private array $routeParams = [];

    /**
     * Initialize the request and response objects
     */
    public function __construct()
    {
        $this->request = new Request();
        $this->response = new Response();
    }

    /**
     * Set route parameters (called by router)
     * 
     * @param array $params Route parameters
     * @return void
     */
    public function setRouteParams(array $params): void
    {
        $this->routeParams = $params;
    }

    /**
     * Get route parameter by name
     * 
     * @param string $name Parameter name
     * @return string|null
     */
    public function getRouteParam(string $name): ?string
    {
        return $this->routeParams[$name] ?? null;
    }

    /**
     * Get query parameter by name (from GET or POST)
     * 
     * @param string $name Parameter name
     * @param mixed $default Default value if not found
     * @return mixed
     */
    public function getQueryParam(string $name, $default = null)
    {
        return $_GET[$name] ?? $_POST[$name] ?? $default;
    }

    /**
     * Get the underlying Symfony Request object
     * 
     * @return Request
     */
    public function getRequest(): Request
    {
        return $this->request;
    }

    /**
     * Get the underlying Response object
     * 
     * @return Response
     */
    public function getResponse(): Response
    {
        return $this->response;
    }

    /**
     * Get the singleton instance of the HttpRequest
     *
     * @return self
     * 
     */
    public static function getInstance(): self
    {
        if (!(static::$_instance instanceof self)) {
            static::$_instance = new self();
        }
        return static::$_instance;
    }

    /**
     * Adds global Middleware to the application. These will be run at every request
     * 
     * @param string $middleware The fully qualified class name of the middleware
     * 23
     * @return void
     */
    public function addMiddleware(string $middleware): void
    {
        $this->middleware[] = $middleware;
    }

    /**
     * Run the given middleware. This will call the callAction method on the middleware with the current request.
     * 
     * @param string $middleware The fully qualified class name of the middleware
     * 
     * @return void
     */
    public function runWithMiddleware($middleware): void
    {
        $middlewareInstance = new $middleware();
        if ($middlewareInstance instanceof IMiddlewareInterface) {
            $res = $middlewareInstance->callAction($this->request, fn($res) => $this->handleResponse($res));
            // Send response if it's a redirect or an error response (4xx, 5xx)
            if ($res->isRedirect() || $res->getStatusCode() >= 400) {
                $res->send();
                exit; // Stop execution after sending error response
            }
        }
    }

    /**
     * Runs all the global middleware added to the request.
     * This will loop through all the middleware added with the addMiddleware method and call the callAction method on each one.
     * This will allow the middleware to modify the request and response objects.
     * @return void
     */
    public function runMiddleware(): void
    {
        foreach ($this->middleware as $middleware) {
            $middlewareInstance = new $middleware();
            if ($middlewareInstance instanceof IMiddlewareInterface) {
                $res = $middlewareInstance->callAction($this->request, fn($res) => $this->handleResponse($res));
                // Send response if it's a redirect or an error response (4xx, 5xx)
                if ($res->isRedirect() || $res->getStatusCode() >= 400) {
                    $res->send();
                    exit; // Stop execution after sending error response
                }
            }
        }
    }

    /**
     * Runs all the global middleware added to the request that implements the ITerminableMiddlewareInterface.
     * This will loop through all the middleware added with the addMiddleware method and call the terminate method on each one.
     * This will allow the middleware to modify the request and response objects after the request has been processed.
     * @return void
     */
    public function runTerminableMiddleware(): void
    {
        foreach ($this->middleware as $middleware) {
            $middlewareInstance = new $middleware();
            if ($middlewareInstance instanceof ITerminableMiddlewareInterface) {
                Log::debug("Calling terminate on middleware: " . $middleware);
                $res = $middlewareInstance->terminate($this->request, fn($res) => $this->handleTerminableResponse($res));
                if ($res->isRedirect()) {
                    $res->send();
                }
            }
        }
    }

    /**
     * Handle the response from the middleware after the request has been processed.
     * This will return a Response object
     *
     * @param Request $res The response from the middleware
     * @return Response The response object
     */
    public function handleResponse(Request $res): Response
    {
        return $this->response;
    }

    /**
     * Handle the response from the middleware after the request has been processed.
     * This will return a Response object
     *
     * @param Request $res The response from the middleware
     * @return Response The response object
     */
    public function handleTerminableResponse(Request $res): Response
    {
        // Idk yet
        return $this->response;
        fastcgi_finish_request();
    }
}
