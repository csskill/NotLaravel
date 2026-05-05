<?php

namespace Nraa\Email\Adapters;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Nraa\Email\AbstractEmailProvider;

/**
 * Maileroo email provider adapter.
 */
class MailerooAdapter extends AbstractEmailProvider
{
    private Client $client;

    /**
     * @param array $config Maileroo configuration
     */
    public function __construct(array $config)
    {
        parent::__construct($config);

        $apiKey = trim((string)$this->getConfig('api_key', ''));
        if ($apiKey === '') {
            throw new \RuntimeException('Maileroo API key is required');
        }

        $this->client = new Client([
            'base_uri' => rtrim((string)$this->getConfig('base_uri', 'https://smtp.maileroo.com'), '/') . '/',
            'timeout' => (float)$this->getConfig('timeout_seconds', 10),
        ]);
    }

    public function send(
        string $to,
        string $subject,
        string $htmlBody,
        ?string $textBody = null,
        ?string $fromEmail = null,
        ?string $fromName = null,
        array $options = []
    ): bool {
        try {
            $fromEmail = $fromEmail ?? $this->getConfig('from_email');
            $fromName = $fromName ?? $this->getConfig('from_name', 'CSSkill.com');

            if (empty($fromEmail)) {
                throw new \RuntimeException('From email address is required');
            }

            $payload = [
                'from' => [
                    'address' => $fromEmail,
                    'name' => $fromName,
                ],
                'to' => [
                    ['address' => $to],
                ],
                'subject' => $subject,
                'html' => $htmlBody,
            ];

            if ($textBody !== null && trim($textBody) !== '') {
                $payload['plain'] = $textBody;
            }

            $bcc = array_values(array_filter(
                array_map(static fn ($email) => trim((string)$email), (array)($options['bcc'] ?? [])),
                static fn (string $email) => $email !== ''
            ));
            if ($bcc !== []) {
                $payload['bcc'] = array_map(static fn (string $email) => ['address' => $email], $bcc);
            }

            $response = $this->client->post('api/v2/emails', [
                'headers' => [
                    'Accept' => 'application/json',
                    'Authorization' => 'Bearer ' . $this->getConfig('api_key'),
                    'Content-Type' => 'application/json',
                ],
                'json' => $payload,
            ]);

            $statusCode = $response->getStatusCode();
            return $statusCode >= 200 && $statusCode < 300;
        } catch (GuzzleException $e) {
            \Nraa\Pillars\Log::error('MailerooAdapter: HTTP error sending email', [
                'to' => $to,
                'error' => $e->getMessage(),
            ]);
            return false;
        } catch (\Throwable $e) {
            \Nraa\Pillars\Log::error('MailerooAdapter: Error sending email', [
                'to' => $to,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
