<?php

namespace Nraa\DOM;

use Nraa\Router\Router;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

class TwigLoader
{
    private static ?Environment $_instance = null;

    /**
     * Creates a new Twig environment.
     *
     * The Twig environment is created with the `templateDir` as the base directory for templates
     * and the `cacheDir` as the cache directory.
     *
     * @param Environment $twig A pre-configured Twig environment.
     */
    private function __construct(Environment $twig) {}

    /**
     * @param string $path
     * @param mixed ...$args
     * @return \Twig\TemplateWrapper|null
     * 
     * Renders a twig template with the given path and arguments.
     * The path is sanitized by replacing both forward and backward slashes with the correct directory separator for the current OS.
     * The extension `.html.twig` is appended to the path.
     * 
     * @see \Nraa\DOM\TwigLoader::sanitizePath()
     */
    public function view(string $path, ...$args): ?\Twig\TemplateWrapper
    {
        $includePath = $this->sanitizePath($path) . '.html.twig';
        return self::$_instance->display($includePath, ...$args);
    }

    /**
     * Sanitizes a given path by replacing forward and backward slashes with the correct directory separator
     * for the current OS.
     *
     * @param string $path The path to sanitize
     * @return string The sanitized path
     */
    private function sanitizePath(string $path): string
    {
        return str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    }

    /**
     * Creates a new Twig environment.
     *
     * The Twig environment is created with the `templateDir` as the base directory for templates
     * and the `cacheDir` as the cache directory.
     *
     * @return Environment A new Twig environment with the specified settings.
     */
    private static function createTwig(): Environment
    {
        $router = Router::getInstance();
        $templateDir = $router->getTemplateRoute();
        $cacheDir = $router->getCacheDir();
        $loader = new FilesystemLoader($templateDir);
        $twigEnvironment = new Environment($loader, [
            //'cache' => $cacheDir, Disable during testing
            'cache' => false,
            'debug' => true,
            'auto_reload' => true,
            'strict_variables' => false,
            'charset' => 'UTF-8',
            'autoescape' => false,

        ]);
        return $twigEnvironment;
    }

    /**
     * Gets the singleton instance of the Twig Environment.
     *
     * @return Environment The twig environment.
     */
    public static function getInstance(): Environment
    {
        return self::$_instance ?? (self::$_instance = self::createTwig());
    }
}
