<?php

/**
 * Email Provider Configuration
 * 
 * Configure email providers similar to payment.php and filesystems.php
 * Supports multiple providers (SendGrid, Mailgun, SES, etc.)
 */

return [
    'default' => $_ENV['EMAIL_PROVIDER'] ?? 'sendgrid',
    'providers' => [
        'sendgrid' => [
            'driver' => 'sendgrid',
            'api_key' => $_ENV['SENDGRID_API_KEY'] ?? '',
            'from_email' => $_ENV['SENDGRID_FROM_EMAIL'] ?? $_ENV['MAIL_FROM_ADDRESS'] ?? 'noreply@yourdomain.com',
            'from_name' => $_ENV['SENDGRID_FROM_NAME'] ?? $_ENV['MAIL_FROM_NAME'] ?? 'default',
        ],
    ],
];
