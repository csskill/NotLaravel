<?php

declare(strict_types=1);

namespace Nraa\Router;

use \Nraa\Pillars\Application;
use \Nraa\DOM\TwigLoader;
use \Nraa\Router\Route;

enum HttpCallbackMethod: string
{
    case GET = 'GET';
    case POST = 'POST';
    case PUT = 'PUT';
    case DELETE = 'DELETE';
    case PATCH = 'PATCH';
}

/**
 * RouteOptions
 */
final class RouteOptions
{
    public bool $authRequired = false;
    public array $rolesAllowed = [];
    public array $permissionsRequired = [];
    public array $middlewares = [];
    public string $membershipTier = ''; // 'free', 'basic', 'premium' - minimum tier required

    public function __construct($authRequired = false, $rolesAllowed = [], $permissionsRequired = [], $middlewares = [], $membershipTier = '')
    {
        $this->authRequired = $authRequired;
        $this->rolesAllowed = $rolesAllowed;
        $this->permissionsRequired = $permissionsRequired;
        $this->middlewares = $middlewares;
        $this->membershipTier = $membershipTier;
    }
}

final class Router
{

    protected array $routes = [];

    protected string $basePath = '';

    protected string $defaultViewsPath = '/resources/views/';

    public static $_instance;

    /**
     * __construct
     *
     * @return void
     */
    public function __construct()
    {
        $this->basePath = dirname(__FILE__, 3);
    }

    /**
     * getRoutes
     *
     * @return array[string, RouteOptions]
     */
    public function getRoutes(): array
    {
        return $this->routes;
    }

    /**
     * Method getTemplateRoute
     *
     * @return string
     */
    public function getTemplateRoute(): string
    {
        return $this->basePath . $this->defaultViewsPath;
    }

    /**
     * Returns a JSON response based on the given data.
     * 
     * This function sets the Content-Type header to application/json, 
     * sets the Cache-Control header to max-age=3600, must-revalidate, 
     * and the Expires header to one hour from the current time.
     * 
     * It then encodes the given data into a JSON string and outputs it.
     * 
     * @param array $responseData The data to be encoded into a JSON string.
     * 
     * @return void
     */
    public function returnJsonResponse($responseData)
    {
        // Set the Content-Type header to indicate JSON response
        header('Content-Type: application/json', true, 200);
        header('Cache-Control: max-age=3600, must-revalidate', true, 200);
        $expires = gmdate('D, d M Y H:i:s T', time() + 3600);
        header('Expires: ' . $expires, true, 200);
        // Encode the PHP data into a JSON string
        $json_output = json_encode($responseData, JSON_PRETTY_PRINT);

        // Output the JSON string
        echo $json_output;
        die();
    }

    /**
     * Method getCacheDir
     *
     * @return string
     */
    public static function getCacheDir(): string
    {
        return self::getInstance()->basePath  . '/storage/cache/';
    }

    /**
     * Method getBaseDir
     *
     * @return string
     */
    public static function getBaseDir(): string
    {
        return self::getInstance()->basePath . '/';
    }

    /**
     * getInstance
     *
     * @return self
     */
    public static function getInstance(): self
    {
        if (!(self::$_instance instanceof self)) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    /**
     * Redirects to a given URL.
     *
     * @param string $url The URL to redirect to.
     * @param string $params [optional] Any parameters to append to the URL.
     *
     * @return void
     */
    public function redirect(string $url, string $params = '')
    {
        header('Location: ' . $url . $params);
        exit;
    }

    /**
     * Checks if the given action is a reference to a controller.
     *
     * If the action is not a closure, it will be checked if it is a string or if it has a key 'uses' that is a string.
     *
     * @param mixed $action The action to check.
     *
     * @return bool Whether the action is a reference to a controller.
     */
    protected function actionReferencesController($action)
    {
        if (! $action instanceof \Closure) {
            return is_string($action) || (isset($action['uses']) && is_string($action['uses']));
        }

        return false;
    }

    /**
     * Converts a given action to a controller action array.
     *
     * If the action is a string, it will be converted to an array with the key 'uses'.
     * If the action is already an array, the key 'controller' will be set to the value of 'uses'.
     *
     * @param mixed $action The action to convert.
     *
     * @return array The converted action array.
     */
    protected function convertToControllerAction($action)
    {
        if (is_string($action)) {
            $action = ['uses' => $action];
        }

        $action['controller'] = $action['uses'];

        return $action;
    }

    /**
     * Creates a new Route object and adds it to the list of routes.
     *
     * @param string $uri The path of the route (e.g. "/users/{id}")
     * @param HttpCallbackMethod $method The HTTP method of the route (e.g. GET, POST, PUT, DELETE)
     * @param mixed $action The action to be called when the route is accessed (e.g. a callable function, a string of a controller action, etc.)
     * @return Route The newly created route
     */
    private function createRoute(string $uri, HttpCallbackMethod $method, $action): Route
    {

        // Figure out and sanitize the action
        if ($this->actionReferencesController($action)) {
            $action = $this->convertToControllerAction($action);
        }

        $route = new Route($method, $uri, $action);
        $this->addRoute($route);
        return $route;
    }

    /**
     * Adds a route to the list of routes.
     *
     * @param Route $route The route to be added
     */
    private function addRoute($route)
    {
        if (!isset($this->routes[$route->uri])) {
            $this->routes[$route->uri] = [];
        }
        $this->routes[$route->uri][$route->method->name] = $route;
    }


    /**
     * Registers a route with the given path and HTTP method.
     *
     * @param string $path The path of the route (e.g. "/users/{id}")
     * @param HttpCallbackMethod $method The HTTP method of the route (e.g. GET, POST, PUT, DELETE)
     * @param mixed $action The action to be called when the route is accessed (e.g. a callable function, a string of a controller action, etc.)
     * @return Route The newly registered route
     */
    public function registerRoute(string $path, HttpCallbackMethod $method, $action): Route
    {
        $route = $this->createRoute($path, $method, $action);
        return $route;
    }


    /**
     * Registers a GET route with the given path and action.
     *
     * @param string $path The path of the route (e.g. "/users/{id}")
     * @param mixed $action The action to be called when the route is accessed (e.g. a callable function, a string of a controller action, etc.)
     *
     * @return Route The newly registered route
     */
    public static function get(string $path, $action): Route
    {
        return self::getInstance()->registerRoute($path, HTTPCallbackMethod::GET, $action);
    }

    /**
     * post
     *
     * @param  mixed $path
     * @param  mixed $options
     * @param  mixed $callback
     * @return Route The newly registered route
     */
    public static function post(string $path, $action): Route
    {
        return self::getInstance()->registerRoute($path, HTTPCallbackMethod::POST, $action);
    }

    /**
     * put
     *
     * @param  mixed $path
     * @param  mixed $options
     * @param  mixed $callback
     * @return Route The newly registered route
     */
    public static function put(string $path, $action): Route
    {
        return self::getInstance()->registerRoute($path, HTTPCallbackMethod::PUT, $action);
    }

    /**
     * delete
     *
     * @param  mixed $path
     * @param  mixed $options
     * @param  mixed $callback
     * @return Route The newly registered route
     */
    public static function delete(string $path, $action): Route
    {
        return self::getInstance()->registerRoute($path, HTTPCallbackMethod::DELETE, $action);
    }

    /**
     * patch
     *
     * @param  mixed $path
     * @param  mixed $options
     * @param  mixed $callback
     * @return Route The newly registered route
     */
    public static function patch(string $path, $action): Route
    {
        return self::getInstance()->registerRoute($path, HTTPCallbackMethod::PATCH, $action);
    }


    /**
     * Dispatches the given request to the correct route.
     *
     * This method will iterate through all the registered routes and check if the current URI matches
     * with the regex pattern of the route. If a match is found, it will check if the route has an action
     * associated with it and if the action is not empty, it will call the action with the matched variables.
     *
     * If no match is found, it will check if there is a route with the current URI and the request method. If a match
     * is found, it will call the action associated with the route with no arguments.
     *
     * If no route is found, it will redirect to the 404 page if debug mode is enabled.
     *
     * @return void
     */
    public function dispatch(): void
    {

        $currentUri = "/" . $this->getCurrentPath();
        $requestMethod = $this->getRequestMethod();
        $route = null;

        $matchedRoute = null;
        $variables = [];

        foreach ($this->routes as $key => $val) {
            $regexPattern = preg_replace('/\{(\w+)\}/', '(?P<$1>[^/]+)', $key);
            if (preg_match('#^' . $regexPattern . '$#', $currentUri, $matches)) {
                $variables = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);

                $methodName = is_string($requestMethod) ? $requestMethod : $requestMethod->name;
                $matchedRoute = $this->routes[$key][$methodName] ?? null;
                break;
            }
        }

        if ($matchedRoute) {
            if (!empty($matchedRoute->getAction())) {
                $matchedRoute->checkRouteOption();
                // We got shit to do  
                $result = $matchedRoute->performAction($variables);
                
                // If result is a JsonResponse, send it
                if ($result instanceof \Symfony\Component\HttpFoundation\JsonResponse) {
                    $result->send();
                    return;
                }
            }
        } else {
            // Determine the correct route if any
            $methodName = is_string($requestMethod) ? $requestMethod : $requestMethod->name;
            if (!empty($this->routes[$currentUri]) && !empty($this->routes[$currentUri][$methodName])) {
                $route = $this->routes[$currentUri][$methodName];
            }

            if ($route === null) {
                $application = Application::getInstance();
                if ($application->debug) {
                    $this->redirect('/404');
                }
                return;
            }

            // Check the route options

            // Check for any perform any given action
            if (!empty($route->getAction())) {
                $route->checkRouteOption();
                // We got shit to do
                $result = $route->performAction();
                
                // If result is a JsonResponse, send it
                if ($result instanceof \Symfony\Component\HttpFoundation\JsonResponse) {
                    $result->send();
                    return;
                }
            }
        }
    }

    /**
     * Gets the current HTTP request method.
     *
     * Loops through all the HttpCallbackMethod cases and checks if the current request method matches any of the cases.
     * If a match is found, it returns the corresponding HttpCallbackMethod.
     * If no match is found, it returns the current request method from the $_SERVER array.
     *
     * @return HttpCallbackMethod|string
     */
    private function getRequestMethod()
    {
        foreach (HttpCallbackMethod::cases() as $reqmethod) {
            if ($reqmethod->value == $_SERVER['REQUEST_METHOD']) {
                return $reqmethod;
            }
        }
        return $_SERVER['REQUEST_METHOD'];
    }

    /**
     * sanitizePath
     *
     * @param  mixed $path
     * @return string
     */
    private function sanitizePath(string $path): string
    {
        $newl = ltrim($path, '/');
        $newr = rtrim($newl, '/');
        return $path;
    }

    /**
     * checkIncludePath
     *
     * @param  mixed $path
     * @return void
     */
    private function performInclude(string $path): void
    {
        $includePath = $this->sanitizePath($path) . '.html.twig';
        $twig = TwigLoader::getInstance();
        $twig->display($includePath);
    }

    /**
     * getCurrentUrl
     *
     * @return string
     */
    private function getCurrentUrl(): string
    {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
        $domainName = $_SERVER['HTTP_HOST'];
        $requestUri = $_SERVER['REQUEST_URI'];
        return $protocol . $domainName . $requestUri;
    }

    /**
     * getCurrentUri
     *
     * @return string
     */
    private function getCurrentPath(): string
    {
        $requestUri = $_SERVER['REQUEST_URI'];
        $path = trim(parse_url($requestUri, PHP_URL_PATH), '/');
        $path_parts = explode('/', $path);
        return $path;
    }
}
