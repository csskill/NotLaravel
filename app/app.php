<?php

use Nraa\Router\Router;
use Nraa\Pillars\Application;
use Nraa\Http\HttpRequest;
use Twig\Environment;
use Nraa\Middleware\MaintenanceMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRoutes(function (Router $router) {})
    ->addConfiguration(function ($app) {})
    ->addTwigConfiguration(function (Environment $twig) {
        // Adding global variables to Twig. These are global variables that Twig can use in templates without defining them
        // every time you are returning a twig view
        $twig->addGlobal('production', true);
        $twig->addFunction(new \Twig\TwigFunction('isLoggedIn', [$authenticationService, 'isLoggedIn']));
    })
    ->withMiddleware(function (HttpRequest $middleware) {
        $middleware->addMiddleware(MaintenanceMiddleware::class);
    })
    ->create();
