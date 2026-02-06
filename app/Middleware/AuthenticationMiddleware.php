<?php

namespace Nraa\Middleware;

use \Nraa\Interfaces\IMiddlewareInterface;
use \Nraa\Services\Auth\AuthenticationService;
use Nraa\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Closure;

final class AuthenticationMiddleware implements IMiddlewareInterface
{
    private AuthenticationService $authenticationService;
    public function __construct()
    {
        $this->authenticationService = new AuthenticationService();
    }

    /**
     * Calls the action
     * 
     * @param Request $request The request object.
     * @param Closure $next The next closure.
     * @return Response The response object.
     */
    public function callAction(Request $request, Closure $next): Response
    {
        $isLoggedIn = $this->authenticationService->isLoggedIn();
        $isJsonRequest = $this->isJsonRequest($request);

        if (!$isLoggedIn) {
            if ($isJsonRequest) {
                return new JsonResponse(['success' => false, 'error' => 'Authentication required'], 401);
            }
            // Redirect to home page with login modal trigger
            return new RedirectResponse('/?login=required');
        }

        return $next($request);
    }
}
