<?php

namespace Nraa\Email;

use Nraa\Email\Adapters\SendGridAdapter;

/**
 * Email Provider Manager
 * 
 * Manages multiple email provider instances, similar to PaymentGatewayManager
 */
class EmailProviderManager
{
    protected array $config;
    protected string $defaultProvider;
    public array $providers = [];

    /**
     * @param array $config Email provider configurations
     */
    public function __construct(array $config = [])
    {
        $this->config = $config;
        $this->defaultProvider = $config['default'] ?? 'sendgrid';
        $this->initialize();
    }

    /**
     * Initialize all configured email providers
     * 
     * @return void
     */
    public function initialize(): void
    {
        if (!isset($this->config['providers'])) {
            return;
        }

        foreach ($this->config['providers'] as $key => $providerConfig) {
            $driver = $providerConfig['driver'] ?? null;
            
            if (empty($driver)) {
                continue;
            }

            switch ($driver) {
                case 'sendgrid':
                    $this->providers[$key] = new SendGridAdapter($providerConfig);
                    break;
                // Future providers can be added here
                // case 'mailgun':
                //     $this->providers[$key] = new MailgunAdapter($providerConfig);
                //     break;
                // case 'ses':
                //     $this->providers[$key] = new SESAdapter($providerConfig);
                //     break;
                default:
                    break;
            }
        }
    }

    /**
     * Get an email provider instance
     * 
     * @param string|null $key Provider key (defaults to configured default)
     * @return EmailProviderInterface
     * @throws \RuntimeException If provider not found
     */
    public function provider(?string $key = null): EmailProviderInterface
    {
        $key = $key ?? $this->defaultProvider;

        if (!isset($this->providers[$key])) {
            throw new \RuntimeException("Email provider '{$key}' not found");
        }

        return $this->providers[$key];
    }

    /**
     * Get the default provider
     * 
     * @return EmailProviderInterface
     */
    public function getDefaultProvider(): EmailProviderInterface
    {
        return $this->provider();
    }
}
