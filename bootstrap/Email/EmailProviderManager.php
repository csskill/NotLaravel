<?php

namespace Nraa\Email;

use Nraa\Email\Adapters\MailerooAdapter;
use Nraa\Email\Adapters\NullEmailAdapter;
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
    protected bool $enabled;
    public array $providers = [];

    /**
     * @param array $config Email provider configurations
     */
    public function __construct(array $config = [])
    {
        $this->config = $config;
        $this->enabled = (bool)($config['enabled'] ?? true);
        $this->defaultProvider = $config['default'] ?? 'maileroo';
        $this->initialize();
    }

    /**
     * Initialize all configured email providers
     * 
     * @return void
     */
    public function initialize(): void
    {
        if (!$this->enabled) {
            $this->providers['null'] = new NullEmailAdapter([
                'driver' => 'null',
            ]);
            return;
        }

        if (!isset($this->config['providers'])) {
            return;
        }

        foreach ($this->config['providers'] as $key => $providerConfig) {
            $driver = $providerConfig['driver'] ?? null;
            
            if (empty($driver)) {
                continue;
            }

            try {
                switch ($driver) {
                    case 'maileroo':
                        $this->providers[$key] = new MailerooAdapter($providerConfig);
                        break;
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
            } catch (\Throwable $e) {
                $this->logInitializationWarning([
                    'provider' => $key,
                    'driver' => $driver,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function logInitializationWarning(array $context): void
    {
        try {
            \Nraa\Pillars\Log::warning('EmailProviderManager: Skipping misconfigured provider', $context);
            return;
        } catch (\Throwable) {
        }

        error_log('EmailProviderManager: Skipping misconfigured provider ' . json_encode($context));
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
        if (!$this->enabled) {
            return $this->providers['null'];
        }

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

    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}
