<?php

namespace Nraa\Controllers;

use Nraa\Router\Controller;
use Nraa\Services\Auth\AuthenticationService;
use Nraa\Services\HomeDashboardService;
use Twig\Environment;
use \Nraa\Services\FollowService;

class HomeController extends Controller
{


    private HomeDashboardService $homeDashboardService;
    private AuthenticationService $authenticationService;
    private FollowService $followService;
    public function __construct()
    {
        $this->homeDashboardService = new HomeDashboardService();
        $this->authenticationService = new AuthenticationService();
        $this->followService = new FollowService();
    }

    /**
     * Landing page for non-authenticated users
     *
     * @param Environment $twig The twig environment to render the page with.
     */
    public function landing(Environment $twig): void
    {
        $twig->display('landing.html.twig', [
            'error' => $_GET['error'] ?? null,
            'success' => $_GET['success'] ?? null
        ]);
    }

    /**
     * Home page for authenticated users - displays dashboard with recent matches
     *
     * @param Environment $twig The twig environment to render the page with.
     */
    public function index(Environment $twig): void
    {
        $user = $this->authenticationService->getCurrentUser();
        $dashboardData = $this->homeDashboardService->getDashboardData(user: $user);

        $twig->display('home.html.twig', context: [
            'error' => $_GET['error'] ?? null,
            'success' => $_GET['success'] ?? null,
            'recent_matches' => $dashboardData['recent_matches'],
            'followed_matches' => $dashboardData['followed_matches'],
            'user' => $user,
            'player_stats' => $dashboardData['player_stats']
        ]);
    }

    /**
     * Maintenance page
     *
     * @param Environment $twig The twig environment to render the page with.
     */
    public function maintenance(Environment $twig): void
    {
        http_response_code(503);
        $twig->display('maintenance.html.twig');
    }
}
