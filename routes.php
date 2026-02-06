<?php

use \Nraa\Router\Router;
use \Nraa\Router\RouteOptions;
use \Nraa\Controllers\HomeController;
use \Nraa\Controllers\ErrorController;
use \Nraa\Controllers\PageController;

// Landing page (redirects to /home if logged in)
Router::get('/', [HomeController::class, 'landing'])->setRouteOptions(new RouteOptions(false, [], [], ['\Nraa\Middleware\LandingPageMiddleware']));
// System maintenance mode endpoint
Router::get('/maintenance', [HomeController::class, 'maintenance']);

// Page Routes
Router::get('/terms-of-service', [PageController::class, 'termsOfService']);
Router::get('/privacy-policy', [PageController::class, 'privacyPolicy']);
Router::get('/about', [PageController::class, 'about']);
Router::get('/contact', [PageController::class, 'contact']);
Router::get('/faq', [PageController::class, 'faq']);
Router::get('/support', [PageController::class, 'support']);
Router::get('/features', [PageController::class, 'features']);

// Explicitly for the CLI
Router::get('/cli', function () {});

// Error pages
Router::get('/404', [ErrorController::class, 'notFound']);
