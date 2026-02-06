<?php

namespace Nraa\Email;

/**
 * Interface for email provider implementations
 */
interface EmailProviderInterface
{
    /**
     * Send an email
     * 
     * @param string $to Recipient email address
     * @param string $subject Email subject
     * @param string $htmlBody HTML email body
     * @param string|null $textBody Plain text email body (optional)
     * @param string|null $fromEmail Sender email address (optional, uses default if not provided)
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
    ): bool;
}
