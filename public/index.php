<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
set_time_limit(60);
error_reporting(E_ALL);

// Configure session for HTTPS
session_set_cookie_params([
    'lifetime' => 604800, // 7 days
    'path' => '/',
    'domain' => '', // Let browser determine domain
    'secure' => true, // HTTPS only
    'httponly' => true, // No JavaScript access
    'samesite' => 'Lax' // CSRF protection
]);

session_start();

define('NRAA_START', microtime(true));

require_once __DIR__ . '/../vendor/autoload.php';

// Start application
$app = require_once __DIR__ . '/../app/app.php';
$app->handleRequest(new \Nraa\Http\HttpRequest())
    ->terminate();
