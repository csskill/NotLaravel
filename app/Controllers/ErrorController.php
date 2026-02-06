<?php

namespace Nraa\Controllers;

use Nraa\Router\Controller;
use Twig\Environment;

class ErrorController extends Controller
{

    /**
     * Show 404 not found page
     * 
     * @return void
     */
    public function notFound(Environment $twig): void
    {
        $twig->display('error/404.twig');
    }
}
