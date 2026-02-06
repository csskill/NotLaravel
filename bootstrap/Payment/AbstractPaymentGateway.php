<?php

namespace Nraa\Payment;

/**
 * Abstract base class for payment gateway implementations
 */
abstract class AbstractPaymentGateway implements PaymentGatewayInterface
{
    protected array $config;

    /**
     * @param array $config Gateway-specific configuration
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
