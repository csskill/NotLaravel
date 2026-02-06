<?php

namespace Nraa\Payment\Adapters;

use Nraa\Payment\AbstractPaymentGateway;
use Nraa\Models\Users\User;
use Stripe\StripeClient;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;

/**
 * Stripe payment gateway adapter
 */
class StripeAdapter extends AbstractPaymentGateway
{
    private StripeClient $stripe;

    /**
     * @param array $config Stripe configuration
     */
    public function __construct(array $config)
    {
        parent::__construct($config);
        
        $apiKey = $this->getConfig('api_key');
        if (empty($apiKey)) {
            throw new \RuntimeException('Stripe API key is required');
        }
        
        $this->stripe = new StripeClient($apiKey);
    }

    /**
     * Create a customer in Stripe
     * 
     * @param User $user
     * @return string Stripe customer ID
     */
    public function createCustomer(User $user): string
    {
        $customer = $this->stripe->customers->create([
            'email' => $user->email,
            'name' => $user->username ?: $user->email,
            'metadata' => [
                'user_id' => (string)$user->id,
                'username' => $user->username ?? '',
            ],
        ]);

        return $customer->id;
    }

    /**
     * Create a subscription
     * 
     * @param string $customerId
     * @param string $priceId
     * @return array
     */
    public function createSubscription(string $customerId, string $priceId): array
    {
        $subscription = $this->stripe->subscriptions->create([
            'customer' => $customerId,
            'items' => [
                ['price' => $priceId],
            ],
            'payment_behavior' => 'default_incomplete',
            'payment_settings' => ['save_default_payment_method' => 'on_subscription'],
            'expand' => ['latest_invoice.payment_intent'],
        ]);

        return [
            'subscription_id' => $subscription->id,
            'status' => $subscription->status,
            'customer_id' => $subscription->customer,
            'current_period_end' => $subscription->current_period_end,
            'current_period_start' => $subscription->current_period_start,
        ];
    }

    /**
     * Get customer portal URL
     * 
     * @param string $customerId
     * @param string $returnUrl
     * @return string
     */
    public function getCustomerPortalUrl(string $customerId, string $returnUrl): string
    {
        $session = $this->stripe->billingPortal->sessions->create([
            'customer' => $customerId,
            'return_url' => $returnUrl,
        ]);

        return $session->url;
    }

    /**
     * Cancel a subscription
     * 
     * @param string $subscriptionId
     * @return bool
     */
    public function cancelSubscription(string $subscriptionId): bool
    {
        try {
            $this->stripe->subscriptions->cancel($subscriptionId);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get subscription details
     * 
     * @param string $subscriptionId
     * @return array
     */
    public function getSubscription(string $subscriptionId): array
    {
        $subscription = $this->stripe->subscriptions->retrieve($subscriptionId);

        return [
            'subscription_id' => $subscription->id,
            'status' => $subscription->status,
            'customer_id' => $subscription->customer,
            'current_period_end' => $subscription->current_period_end,
            'current_period_start' => $subscription->current_period_start,
            'cancel_at_period_end' => $subscription->cancel_at_period_end,
        ];
    }

    /**
     * Handle Stripe webhook
     * 
     * @param string $payload Raw JSON payload string (must be raw for signature verification)
     * @param string $signature Stripe signature header
     * @return object Stripe event object
     */
    public function handleWebhook(string $payload, string $signature): object
    {
        $webhookSecret = $this->getConfig('webhook_secret');
        
        if (empty($webhookSecret)) {
            throw new \RuntimeException('Stripe webhook secret is required. Check STRIPE_WEBHOOK_SECRET in .env');
        }

        try {
            // Stripe::Webhook::constructEvent requires the raw payload string
            // DO NOT decode/encode - it will break signature verification
            $event = Webhook::constructEvent(
                $payload,
                $signature,
                $webhookSecret
            );
        } catch (SignatureVerificationException $e) {
            throw new \RuntimeException('Invalid webhook signature: ' . $e->getMessage());
        }

        // Return the event object - the webhook controller will handle it
        return $event;
    }

    /**
     * Create a checkout session for subscription
     * 
     * @param string $customerId
     * @param string $priceId
     * @param string $successUrl
     * @param string $cancelUrl
     * @return string Checkout session URL
     */
    public function createCheckoutSession(string $customerId, string $priceId, string $successUrl, string $cancelUrl): string
    {
        $session = $this->stripe->checkout->sessions->create([
            'customer' => $customerId,
            'mode' => 'subscription',
            'line_items' => [
                [
                    'price' => $priceId,
                    'quantity' => 1,
                ],
            ],
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'allow_promotion_codes' => true,
        ]);

        return $session->url;
    }

    /**
     * Get Stripe client instance (for advanced usage)
     * 
     * @return StripeClient
     */
    public function getStripeClient(): StripeClient
    {
        return $this->stripe;
    }
}
