<?php

namespace Nraa\Router;

use \Nraa\Pillars\Application;
use \Nraa\Pillars\ApplicationInstance;

final class Route
{

    use ApplicationInstance;

    /**
     * The URI pattern the route responds to.
     *
     * @var string
     */
    public $uri;

    /**
     * The HTTP methods the route responds to.
     *
     * @var array
     */
    public $method;

    /**
     * The route action array.
     *
     * @var array
     */
    public $action;

    /**
     * The route options.
     *
     * @var RouteOptions|null
     */
    protected ?RouteOptions $routeOptions = null;

    /**
     * Current route being processed (for middleware access)
     *
     * @var Route|null
     */
    protected static ?Route $currentRoute = null;

    /**
     * Method __construct
     *
     * @param $methods $methods [explicite description]
     * @param $uri $uri [explicite description]
     * @param $action $action [explicite description]
     *
     * @return self
     */
    public function __construct($method, $uri, $action)
    {
        $this->uri = $uri;
        $this->method = $method;
        $this->routeOptions = new RouteOptions();
        $this->action = $this->parseAction($action); //( NEED TO FIGURE THIS SHIT OUT) 
    }


    /**
     * Parse the given action to determine if it's a callable or a string referencing a controller action.
     * If the action is a callable, it will be wrapped in an array with the key 'uses'.
     * If the action is a string referencing a controller action, it will be split into an array with the keys 'uses' and 'controller'.
     * 
     * @param mixed $action The action to parse.
     * 
     * @return array The parsed action array.
     */
    private function parseAction($action)
    {
        if ($this->isCallable($action, true)) {
            return ! is_array($action) ? ['uses' => $action] : [
                'uses' => $action[0] . '@' . $action[1],
                'controller' => $action[0] . '@' . $action[1],
            ];
        }
    }

    /**
     * Sets the route options.
     *
     * @param RouteOptions $routeOptions
     * 
     * @return void
     */
    public function setRouteOptions(RouteOptions $routeOptions)
    {
        $this->routeOptions->middlewares = array_merge($this->routeOptions->middlewares, $routeOptions->middlewares);
        $this->routeOptions->rolesAllowed = $routeOptions->rolesAllowed;
        $this->routeOptions->permissionsRequired = $routeOptions->permissionsRequired;
        $this->routeOptions->authRequired = $routeOptions->authRequired;
        $this->routeOptions->membershipTier = $routeOptions->membershipTier;
    }

    /**
     * Adds a middleware to the route.
     *
     * @param string $middleware The fully qualified class name of the middleware
     *
     * @return self
     */
    public function withMiddleware(string $middleware): self
    {
        $this->routeOptions->middlewares[] = $middleware;
        return $this; // Return self to allow method chaining
    }

    /**
     * Checks if the given variable is callable.
     *
     * If the variable is not an array, it will use the built-in is_callable function.
     * If the variable is an array and the first element is a string or an object, the second element should be a string.
     * If the syntaxOnly parameter is set to true, it will only check if the syntax of the variable is correct, it will not check if the class or method exists.
     *
     * @param mixed $var The variable to check
     * @param boolean $syntaxOnly Whether to only check the syntax
     * @return boolean Whether the variable is callable
     */
    private function isCallable($var, $syntaxOnly = false)
    {
        if (! is_array($var)) {
            return is_callable($var, $syntaxOnly);
        }

        if (! isset($var[0], $var[1]) || ! is_string($var[1] ?? null)) {
            return false;
        }

        if (
            $syntaxOnly &&
            (is_string($var[0]) || is_object($var[0])) &&
            is_string($var[1])
        ) {
            return true;
        }

        $class = is_object($var[0]) ? get_class($var[0]) : $var[0];

        $method = $var[1];

        if (! class_exists($class)) {
            return false;
        }

        if (method_exists($class, $method)) {
            return (new \ReflectionMethod($class, $method))->isPublic();
        }

        if (is_object($var[0]) && method_exists($class, '__call')) {
            return (new \ReflectionMethod($class, '__call'))->isPublic();
        }

        if (! is_object($var[0]) && method_exists($class, '__callStatic')) {
            return (new \ReflectionMethod($class, '__callStatic'))->isPublic();
        }

        return false;
    }

    /**
     * Get the action array for the route.
     *
     * @return array
     */
    public function getAction()
    {
        return $this->action;
    }

    /**
     * Set the action array for the route.
     *
     * @param  array  $action
     * @return $this
     */
    public function setAction(array $action)
    {
        $this->action = $action;

        return $this;
    }

    /**
     * Checks if the route options are set and if the route requires authentication.
     * If the route requires authentication, it will loop through the middlewares and call the callAction method on each one.
     * 
     * @return mixed|null
     */
    public function checkRouteOption()
    {
        // Store current route for middleware access
        self::$currentRoute = $this;
        
        if (!empty($this->routeOptions->middlewares)) {
            $middlewares = $this->routeOptions->middlewares;
            foreach ($middlewares as $middleware) {
                $httpRequest = $this->getApp()->getAppService('Nraa\Http\HttpRequest');
                $httpRequest->runWithMiddleware($middleware);
            }
        }
        
        // Clear current route after middleware processing
        self::$currentRoute = null;
    }

    /**
     * Get the current route being processed
     * 
     * @return Route|null
     */
    public static function getCurrentRoute(): ?Route
    {
        return self::$currentRoute;
    }

    /**
     * Get route options
     * 
     * @return RouteOptions|null
     */
    public function getRouteOptions(): ?RouteOptions
    {
        return $this->routeOptions;
    }

    /**
     * Checks if the action is a closure.
     *
     * @return bool
     */
    private function isClosure()
    {
        return (!empty($this->action['uses']) && $this->action['uses'] instanceof \Closure);
    }


    /**
     * Calls the action of the route with the given variables.
     * If the action is a closure, it will be called with the route instance as a parameter.
     * If the action is a string referencing a controller action, it will be called with the parameters passed to this method.
     * 
     * @param array $variables An array of variables to pass to the action.
     * @return mixed|null The result of the action call.
     */
    public function performAction($variables = [])
    {
        $callbackResult = null;
        $callable = $this->action['uses'];

        // Check to see if the action is a callable and a controller is not set
        if (isset($callable) && !array_key_exists('controller', $this->action)) {
            $callbackResult = is_callable($callable) ? call_user_func($callable, $this) : null;
            return $callbackResult;
        }

        // Checks if the action is a string referencing a controller action
        if (!$this->isCallable($this->action) && array_key_exists('controller', $this->action)) {
            $parts = explode('@', $this->action['uses']);

            $controller = '\\' . $parts[0];
            $method = $parts[1];

            $controllerInstance = new $controller();
            $reflectionMethod = new \ReflectionMethod($controllerInstance, $method);
            $parameters = $reflectionMethod->getParameters();

            $requiredParameters = [];

            foreach ($parameters as $parameter) {
                $paramType = $parameter->getType();
                if (!$paramType) continue;

                $typeName = $paramType->getName();

                // Check if this is a built-in/scalar type or a class
                if ($paramType->isBuiltin()) {
                    // For scalar types (string, int, etc), get from route variables
                    $paramName = $parameter->getName();
                    if (isset($variables[$paramName])) {
                        $requiredParameters[] = $variables[$paramName];
                    } elseif (!$parameter->isOptional()) {
                        $requiredParameters[] = null; // Will cause type error if not optional
                    }
                } else {
                    // For class types, resolve from DI container
                    if (!$parameter->isOptional()) {
                        $application = Application::getInstance();
                        $service = $application->getAppService($typeName);
                        
                        // Handle HttpRequest (both namespaces) - use singleton pattern
                        if ($service === null && ($typeName === 'Nraa\Http\HttpRequest' || $typeName === 'Nraa\Http\HttpRequest')) {
                            if ($typeName === 'Nraa\Http\HttpRequest') {
                                // Create wrapper instance and inject route variables
                                $service = new \Nraa\Http\HttpRequest();
                                $service->setRouteParams($variables);
                            } else {
                                $service = \Nraa\Http\HttpRequest::getInstance();
                            }
                        }
                        
                        // HttpResponse is a static helper class - should not be a parameter
                        // If it appears as a parameter, something is wrong with the controller signature
                        if ($service === null && $typeName === 'Nraa\Http\HttpResponse') {
                            throw new \RuntimeException("HttpResponse should not be a parameter. Use HttpResponse::json() statically in your controller.");
                        }
                        
                        if ($service === null) {
                            throw new \RuntimeException("Could not resolve dependency: {$typeName}");
                        }
                        
                        $requiredParameters[] = $service;
                    }
                }
            }

            return $controllerInstance->{$method}(...array_values($requiredParameters));
        }
    }
}
