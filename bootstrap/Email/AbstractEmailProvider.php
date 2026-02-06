<?php

namespace Nraa\Email;

/**
 * Abstract base class for email provider implementations
 */
abstract class AbstractEmailProvider implements EmailProviderInterface
{
    protected array $config;

    /**
     * @param array $config Provider-specific configuration
     */
    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Get configuration value
     * 
     * @param string $key Configuration key
     * @param mixed $default Default value if key not found
     * @return mixed
     */
    protected function getConfig(string $key, $default = null)
    {
        return $this->config[$key] ?? $default;
    }
}
