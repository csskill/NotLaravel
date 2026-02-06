<?php

namespace Nraa\Middleware;

use Nraa\Interfaces\IMiddlewareInterface;
use Nraa\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Closure;

/**
 * Middleware for maintenance mode - redirects all requests to /maintenance and prohibits access to any other page or api
 */
final class MaintenanceMiddleware implements IMiddlewareInterface
{

    /**
     * Calls the action
     * 
     * @param Request $request The request object.
     * @param Closure $next The next closure.
     * @return Response The response object.
     */
    public function callAction(Request $request, Closure $next): Response
    {
        // Check env for maintenance mode
        if ($_ENV['MAINTENANCE_MODE'] === 'true') {
            // If already on maintenance page, proceed  
            if ($request->getPathInfo() === '/maintenance') {
                return $next($request);
            }
            // Redirect all other requests to /maintenance
            return new RedirectResponse('/maintenance');
        }

        // Not in maintenance mode - proceed with request   
        return $next($request);
    }
}
