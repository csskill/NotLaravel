<?php

/**
 * Payment Gateway Configuration
 * 
 * Configure payment gateways similar to filesystems.php
 * Supports multiple gateways (Stripe, PayPal, etc.)
 */

return [
    'default' => $_ENV['PAYMENT_GATEWAY'] ?? 'stripe',
    'gateways' => [
        'stripe' => [
            'driver' => 'stripe',
            'api_key' => $_ENV['STRIPE_SECRET_KEY'] ?? '',
            'public_key' => $_ENV['STRIPE_PUBLIC_KEY'] ?? '',
            'webhook_secret' => $_ENV['STRIPE_WEBHOOK_SECRET'] ?? '',
            'price_ids' => [
                'basic' => $_ENV['STRIPE_PRICE_ID_BASIC'] ?? '',
                'premium' => $_ENV['STRIPE_PRICE_ID_PREMIUM'] ?? '',
            ],
        ],
    ],
];
