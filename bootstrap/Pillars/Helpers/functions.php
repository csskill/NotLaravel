<?php

if (!function_exists('redirect')) {
    function redirect($url, $params = '')
    {
        $router = \Nraa\Router\Router::getInstance();
        $router->redirect($url, $params);
    }
}

if (! function_exists('event')) {
    /**
     * Dispatch an event and call the listeners.
     * 
     * In CLI context, events are processed immediately.
     * In HTTP context, events are queued and processed at end of request.
     *
     * @param  string|object  $event
     * @param  mixed  $payload
     * @param  bool  $halt
     * @return array|null
     */
    function event(...$args)
    {
        // In CLI context (jobs, scripts), dispatch and process immediately
        if (php_sapi_name() === 'cli') {
            if (count($args) === 1 && is_object($args[0])) {
                app()->dispatchNow($args[0]);
                return null;
            }
        }
        
        // In HTTP context, queue for later processing
        return app()->dispatch(...$args);
    }
}

if (! function_exists('event_now')) {
    /**
     * Dispatch an event and process it immediately (synchronous).
     * Use this explicitly when you need immediate processing regardless of context.
     *
     * @param  object  $event The event instance
     * @return void
     */
    function event_now(object $event): void
    {
        app()->dispatchNow($event);
    }
}

if (! function_exists('get_class_name_without_namespace')) {
    function get_class_name_without_namespace($className)
    {
        return strtolower(explode('\\', $className)[count(explode('\\', $className)) - 1]);
    }
}

if (! function_exists('app')) {
    function app()
    {
        return \Nraa\Pillars\Application::getInstance();
    }
}

if (! function_exists('dispatch')) {
    /**
     * Dispatch a job to its appropriate handler.
     *
     * @param  mixed  $job
     * @return ($job is \Closure ? \Illuminate\Foundation\Bus\PendingClosureDispatch : \Illuminate\Foundation\Bus\PendingDispatch)
     */
    function dispatch($job): \Nraa\Pillars\Events\PendingDispatch
    {
        $pending = new \Nraa\Pillars\Events\PendingDispatch($job);

        app()->addPendingDispatch($pending);
        return $pending;
    }
}

if (! function_exists('get_classes_in_file')) {
    function get_classes_in_file($filePath)
    {
        if (!file_exists($filePath)) {
            return [];
        }

        $classes = [];
        $contents = file_get_contents($filePath);
        $tokens = token_get_all($contents);

        $namespace = '';
        for ($i = 0; $i < count($tokens); $i++) {
            if ($tokens[$i][0] === T_NAMESPACE) {
                $namespace = '';
                for ($j = $i + 1; $j < count($tokens); $j++) {
                    if ($tokens[$j][0] === T_STRING || $tokens[$j][0] === T_NS_SEPARATOR) {
                        $namespace .= $tokens[$j][1];
                    } else if ($tokens[$j] === '{' || $tokens[$j] === ';') {
                        break;
                    }
                }
            }

            if ($tokens[$i][0] === T_CLASS && $tokens[$i - 1][0] !== T_DOUBLE_COLON) { // Exclude ::class
                for ($j = $i + 1; $j < count($tokens); $j++) {
                    if ($tokens[$j][0] === T_STRING) {
                        $className = $tokens[$j][1];
                        $fullyQualifiedClassName = $namespace ? $namespace . '\\' . $className : $className;
                        $classes[] = $fullyQualifiedClassName;
                        break;
                    }
                }
            }
        }

        return $classes;
    }
}

if (! function_exists('remove_namespace')) {
    function remove_namespace($class)
    {

        // Find the position of the last backslash
        $lastBackslashPos = strrpos($class, '\\');

        // If a backslash is found, extract the substring after it
        if ($lastBackslashPos !== false) {
            $classNameWithoutNamespace = substr($class, $lastBackslashPos + 1);
        } else {
            // If no backslash is found, the string itself is the class name
            $classNameWithoutNamespace = $class;
        }

        return $classNameWithoutNamespace;
    }
}
