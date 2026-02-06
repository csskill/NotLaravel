<?php

namespace Nraa\Controllers;

use Nraa\Router\Controller;
use Twig\Environment;

class PageController extends Controller
{

    /**
     * Summary of termsOfService
     * @param Environment $twig
     * @return void
     */
    public function termsOfService(Environment $twig): void
    {
        $twig->display('pages/tos.twig');
    }

    /**
     * Summary of privacyPolicy
     * @param Environment $twig
     * @return void
     */
    public function privacyPolicy(Environment $twig): void
    {
        $twig->display('pages/pp.twig');
    }

    /**
     * Summary of about
     * @param Environment $twig
     * @return void
     */
    public function about(Environment $twig): void
    {
        $twig->display('pages/about.twig');
    }

    /**
     * Summary of contact
     * @param Environment $twig
     * @return void
     */
    public function contact(Environment $twig): void
    {
        $twig->display('pages/contact.twig');
    }

    /**
     * Summary of faq
     * @param Environment $twig
     * @return void
     */
    public function faq(Environment $twig): void
    {
        $twig->display('pages/faq.twig');
    }

    /**
     * Summary of support
     * @param Environment $twig
     * @return void
     */
    public function support(Environment $twig): void
    {
        $twig->display('pages/support.twig');
    }

    /**
     * Summary of features
     * @param Environment $twig
     * @return void
     */
    public function features(Environment $twig): void
    {
        $twig->display('pages/features.twig');
    }
}
