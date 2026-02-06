<?php

namespace Nraa\Email\Adapters;

use Nraa\Email\AbstractEmailProvider;
use SendGrid\Mail\Mail;
use SendGrid\Mail\TypeException;

/**
 * SendGrid email provider adapter
 */
class SendGridAdapter extends AbstractEmailProvider
{
    private \SendGrid $sendGrid;

    /**
     * @param array $config SendGrid configuration
     */
    public function __construct(array $config)
    {
        parent::__construct($config);
        
        $apiKey = $this->getConfig('api_key');
        if (empty($apiKey)) {
            throw new \RuntimeException('SendGrid API key is required');
        }
        
        $this->sendGrid = new SendGrid($apiKey);
    }

    /**
     * Send an email via SendGrid
     * 
     * @param string $to Recipient email address
     * @param string $subject Email subject
     * @param string $htmlBody HTML email body
     * @param string|null $textBody Plain text email body (optional)
     * @param string|null $fromEmail Sender email address (optional)
     * @param string|null $fromName Sender name (optional)
     * @return bool True if sent successfully, false otherwise
     */
    public function send(
        string $to,
        string $subject,
        string $htmlBody,
        ?string $textBody = null,
        ?string $fromEmail = null,
        ?string $fromName = null
    ): bool {
        try {
            $fromEmail = $fromEmail ?? $this->getConfig('from_email');
            $fromName = $fromName ?? $this->getConfig('from_name', 'Vimzzz');
            
            if (empty($fromEmail)) {
                throw new \RuntimeException('From email address is required');
            }

            $email = new Mail();
            $email->setFrom($fromEmail, $fromName);
            $email->setSubject($subject);
            $email->addTo($to);
            $email->addContent('text/html', $htmlBody);
            
            if ($textBody !== null) {
                $email->addContent('text/plain', $textBody);
            }

            $response = $this->sendGrid->send($email);
            
            // SendGrid returns 2xx status codes for success
            $statusCode = $response->statusCode();
            return $statusCode >= 200 && $statusCode < 300;
        } catch (TypeException $e) {
            \Nraa\Pillars\Log::error('SendGridAdapter: Type error sending email', [
                'to' => $to,
                'error' => $e->getMessage(),
            ]);
            return false;
        } catch (\Exception $e) {
            \Nraa\Pillars\Log::error('SendGridAdapter: Error sending email', [
                'to' => $to,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
