<?php

namespace Nraa\Payment;

use Nraa\Payment\Adapters\StripeAdapter;

/**
 * Payment Gateway Manager
 * 
 * Manages multiple payment gateway instances, similar to Filesystem manager
 */
class PaymentGatewayManager
{
    protected array $config;
    protected string $defaultGateway;
    public array $gateways = [];

    /**
     * @param array $config Payment gateway configurations
     */
    public function __construct(array $config = [])
    {
        $this->config = $config;
        $this->defaultGateway = $config['default'] ?? 'stripe';
        $this->initialize();
    }

    /**
     * Initialize all configured payment gateways
     * 
     * @return void
     */
    public function initialize(): void
    {
        if (!isset($this->config['gateways'])) {
            return;
        }

        foreach ($this->config['gateways'] as $key => $gatewayConfig) {
            $driver = $gatewayConfig['driver'] ?? null;
            
            if (empty($driver)) {
                continue;
            }

            switch ($driver) {
                case 'stripe':
                    $this->gateways[$key] = new StripeAdapter($gatewayConfig);
                    break;
                // Future gateways can be added here
                // case 'paypal':
                //     $this->gateways[$key] = new PayPalAdapter($gatewayConfig);
                //     break;
                default:
                    break;
            }
        }
    }

    /**
     * Get a payment gateway instance
     * 
     * @param string|null $key Gateway key (defaults to configured default)
     * @return PaymentGatewayInterface
     * @throws \RuntimeException If gateway not found
     */
    public function gateway(?string $key = null): PaymentGatewayInterface
    {
        $key = $key ?? $this->defaultGateway;

        if (!isset($this->gateways[$key])) {
            throw new \RuntimeException("Payment gateway '{$key}' not found");
        }

        return $this->gateways[$key];
    }

    /**
     * Get the default gateway
     * 
     * @return PaymentGatewayInterface
     */
    public function getDefaultGateway(): PaymentGatewayInterface
    {
        return $this->gateway();
    }
}
