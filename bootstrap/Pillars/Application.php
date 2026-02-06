<?php

namespace Nraa\Pillars;

use Nraa\Router\Router;
use Nraa\DOM\TwigLoader;
use Twig\Environment;
use Nraa\Pillars\Logging;
use Symfony\Component\Dotenv\Dotenv;
use SplQueue;
use Nraa\Filesystem\Filesystem;
use Nraa\Pillars\Events\Event;
use Nraa\Pillars\Events\PendingDispatch;
use Nraa\Http\HttpRequest;

final class Application
{
    /**
     * Base path of the application
     */
    private ?string $basePath = null;

    /**
     * Singleton application instance
     */
    private static ?Application $_instance = null;

    /**
     * Core services
     */
    private ?Router $_router = null;
    private ?Environment $_twig = null;

    /**
     * Environment & configuration
     */
    private $_config = null;
    public bool $debug = false;
    protected array $configuration = [];

    /**
     * Service container
     */
    protected array $_serviceProviders = [];
    protected array $_singletons = [];

    /**
     * Logging
     */
    protected array $logConfiguration = [];
    protected $logger = null;

    /**
     * Pending event dispatch queue.
     *
     * Uses a real queue implementation to ensure
     * safe processing in long-running CLI workers.
     */
    protected SplQueue $_pendingDispatch;

    /**
     * Cached listener registry.
     *
     * [
     *   'EventName' => [
     *       \Nraa\Listeners\EventNameListener::class
     *   ]
     * ]
     */
    protected array $_listeners = [];

    /**
     * Prevent duplicate event handling within the same process.
     * Important for long-running workers.
     */
    protected array $_dispatchedEvents = [];

    public function __construct()
    {
        $this->_pendingDispatch = new SplQueue();
    }

    /**
     * Configures the application instance.
     *
     * This method configures the application instance by setting the base path.
     * The base path is used to determine the location of the application's files and directories.
     *
     * @param string $basePath The base path of the application.
     * @return self The configured application instance.
     */
    public static function configure(string $basePath): self
    {
        $instance = self::getInstance();
        $instance->basePath = $basePath;
        $instance->configurePreBootstrap();
        $instance->registerListeners();

        return $instance;
    }

    /**
     * Configures the application instance's pre-bootstrap settings.
     *
     * This method sets up application dependencies that are critical
     * to the operation of the application.
     *
     * @return void
     */
    private function configurePreBootstrap(): void
    {
        // Load environment configuration
        $dotenv = new Dotenv();
        $dotenv->load(path: $this->basePath . '/.env');
        $this->addConfigurationProvider(config: $dotenv);
        $this->debug = (bool)($_ENV['DEBUG'] ?? false);
        $this->configuration = $_ENV;

        // Configure logging
        $this->logConfiguration = require $this->basePath . '/app/config/log.php';
        $logging = new Logging(basePath: $this->getStoragePath(), configuration: $this->logConfiguration);
        $this->registerSingleton(singleton: $logging);

        // Configure filesystem
        $filesystemConfig = require $this->basePath . '/app/config/filesystems.php';
        $filesystem = new Filesystem(storagePath: $this->getStoragePath(), config: $filesystemConfig);
        $this->registerSingleton(singleton: $filesystem);

        // Global exception & error handlers
        set_exception_handler(callback: [\Nraa\Exceptions\ExceptionHandler::class, 'phpExceptionHandler']);
        set_error_handler(callback: [\Nraa\Exceptions\ExceptionHandler::class, 'phpErrorHandler']);

        require_once $this->basePath . '/bootstrap/Pillars/Helpers/functions.php';
    }

    /**
     * Returns the singleton instance of the Application class.
     *
     * @return Application
     */
    public static function getInstance(): Application
    {
        if (self::$_instance === null) {
            self::$_instance = new Application();
        }

        return self::$_instance;
    }

    /**
     * Creates a new instance of the Application class.
     *
     * Loads registered service providers.
     *
     * @return Application
     */
    public function create(): Application
    {
        $providers = require $this->basePath . '/app/providers.php';
        $this->_serviceProviders = array_merge($this->_serviceProviders, $providers);

        return $this;
    }

    /**
     * Returns the path to the storage directory.
     *
     * @return string
     */
    public function getStoragePath(): string
    {
        return $this->basePath . '/storage';
    }

    /**
     * Returns the path to the base directory.
     *
     * @return string
     */
    public function getBasePath(): string
    {
        return $this->basePath;
    }
    /**
     * Returns the path to the app directory.
     *
     * @return string
     */
    public function getAppPath(): string
    {
        return $this->basePath . '/app';
    }
    /**
     * Returns the filesystem instance.
     *
     * @param string $key The filesystem key as configured in filesystems.php
     * @return \League\Flysystem\Filesystem
     */
    public function getFilesystem(string $key): \League\Flysystem\Filesystem
    {
        return $this->getAppService(Filesystem::class)->filesystem[$key];
    }

    /**
     * Allows the user to add custom configuration logic.
     *
     * @param callable $callable
     * @return Application
     */
    public function addConfiguration(callable $callable): Application
    {
        $callable($this);
        return $this;
    }

    /**
     * Adds a configuration provider to the application.
     *
     * @param object $config
     * @return Application
     */
    public function addConfigurationProvider($config): Application
    {
        $this->_config = $config;
        return $this;
    }

    /**
     * Returns all resolved configuration values.
     *
     * @return array
     */
    public function getConfig(): array
    {
        return $this->configuration;
    }

    /**
     * Allows the user to define application routes.
     *
     * @param callable $callback
     * @return Application
     */
    public function withRoutes(callable $callback): Application
    {
        $this->_router = Router::getInstance();
        $this->registerSingleton($this->_router);

        require $this->basePath . '/routes.php';
        $callback($this->_router);

        return $this;
    }

    /**
     * Registers HTTP middleware.
     *
     * @param callable $callback
     * @return Application
     */
    public function withMiddleware(callable $callback): Application
    {
        $httpRequest = \Nraa\Http\HttpRequest::getInstance();
        $callback($httpRequest);
        $this->registerSingleton($httpRequest);

        return $this;
    }

    /**
     * Adds configuration to the Twig environment.
     *
     * @param callable $callback
     * @return Application
     */
    public function addTwigConfiguration(callable $callback): Application
    {
        $this->_twig = TwigLoader::getInstance();
        $this->registerSingleton($this->_twig);
        $callback($this->_twig);

        return $this;
    }

    /**
     * Registers a singleton instance with the application container.
     *
     * @param object $singleton
     * @return void
     */
    public function registerSingleton(object $singleton): void
    {
        $this->_singletons[$singleton::class] = $singleton;
    }

    /**
     * Retrieves a service instance from the container.
     *
     * @param string $serviceName
     * @return object|null
     */
    public function getAppService(string $serviceName)
    {
        if ($serviceName === self::class) {
            return $this;
        }

        return $this->_singletons[$serviceName]
            ?? $this->_serviceProviders[$serviceName]
            ?? null;
    }

    /**
     * Dispatches an event (deferred in HTTP context).
     *
     * @param mixed ...$args
     * @return Application
     */
    public function dispatch(...$args): Application
    {
        dispatch(...$args);
        return $this;
    }

    /**
     * Dispatches and immediately processes an event.
     *
     * Intended for CLI jobs and background workers where
     * no HTTP lifecycle exists.
     *
     * @param object $event
     * @return void
     */
    public function dispatchNow(object $event): void
    {
        $this->_pendingDispatch->enqueue(
            new PendingDispatch($event)
        );

        $this->flushPendingDispatches();
    }

    /**
     * Processes all queued events immediately.
     *
     * Uses queue semantics to ensure each event
     * is handled exactly once.
     *
     * @return void
     */
    public function flushPendingDispatches(): void
    {
        while (!$this->_pendingDispatch->isEmpty()) {
            $pending = $this->_pendingDispatch->dequeue();
            $this->dispatchSingle($pending->job);
        }
    }

    /**
     * Dispatches a single event instance to its listeners.
     *
     * @param object $event
     * @return void
     */
    private function dispatchSingle(object $event): void
    {
        if ($event instanceof Event) {
            $hash = $event->eventId();
        } else {
            // Non-event objects fallback (should be rare)
            $hash = spl_object_id($event);
        }

        if (isset($this->_dispatchedEvents[$hash])) {
            return;
        }

        $this->_dispatchedEvents[$hash] = true;

        $eventName = remove_namespace(get_class($event));

        if (!isset($this->_listeners[$eventName])) {
            return;
        }

        foreach ($this->_listeners[$eventName] as $listenerClass) {
            (new $listenerClass())->handle($event);
        }
    }

    /**
     * Discovers and registers event listeners once during bootstrap.
     *
     * Avoids filesystem scans during event dispatch.
     *
     * @return void
     */
    private function registerListeners(): void
    {
        $listenersPath = $this->basePath . '/app/Listeners';

        if (!is_dir($listenersPath)) {
            return;
        }

        $directory = new \RecursiveDirectoryIterator($listenersPath, \RecursiveDirectoryIterator::SKIP_DOTS);
        $iterator  = new \RecursiveIteratorIterator($directory);

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $classes = get_classes_in_file($file->getPathname());

            foreach ($classes as $class) {
                if (!str_ends_with($class, 'Listener')) {
                    continue;
                }

                $event = str_replace('Listener', '', $class);
                $this->_listeners[$event][] = '\\Nraa\\Listeners\\' . $class;
            }
        }
    }

    /**
     * Handles an HTTP request lifecycle.
     *
     * @param HttpRequest $request
     * @return Application
     */
    public function handleRequest(HttpRequest $request): Application
    {
        $this->getAppService(HttpRequest::class)->runMiddleware();
        $this->_router->dispatch();
        $this->flushPendingDispatches();
        $this->getAppService(HttpRequest::class)->runTerminableMiddleware();

        return $this;
    }

    /**
     * Shuts down the application.
     *
     * @return void
     */
    public function terminate(): void
    {
        Log::debug("Shutting down application");
        /*dd("Total execution time: " .
            (microtime(true) - constant('NRAA_START')) .
            " seconds");*/

        Log::debug(
            "Total execution time: " .
                (microtime(true) - constant('NRAA_START')) .
                " seconds"
        );
    }
}
