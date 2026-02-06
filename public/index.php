<?php

session_start();

define('NRAA_START', microtime(true));

require_once __DIR__ . '/../vendor/autoload.php';

// Start application
$app = require_once __DIR__ . '/../app/app.php';
$app->handleRequest(new \Nraa\Http\HttpRequest())
    ->terminate();
