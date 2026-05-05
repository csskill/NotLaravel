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
     * Create a subscription.
     *
     * @param string $customerId
     * @param string $priceId
     * @param array $options
     * @return array
     */
    public function createSubscription(string $customerId, string $priceId, array $options = []): array
    {
        $params = [
            'customer' => $customerId,
            'items' => [
                ['price' => $priceId],
            ],
            'payment_behavior' => (string)($options['payment_behavior'] ?? 'allow_incomplete'),
            'expand' => ['latest_invoice.payment_intent'],
        ];

        if (isset($options['trial_end'])) {
            $trialEnd = (int)$options['trial_end'];
            if ($trialEnd > 0) {
                $params['trial_end'] = $trialEnd;
            }
        }

        if (isset($options['trial_settings']) && is_array($options['trial_settings'])) {
            $params['trial_settings'] = $options['trial_settings'];
        }

        if (isset($options['metadata']) && is_array($options['metadata'])) {
            $params['metadata'] = $options['metadata'];
        }

        if (isset($options['payment_settings']) && is_array($options['payment_settings'])) {
            $params['payment_settings'] = $options['payment_settings'];
        }

        $subscription = $this->stripe->subscriptions->create($params);
        $subscriptionArray = $subscription->toArray();
        $periodEnd = $this->extractSubscriptionPeriodBoundary($subscriptionArray, 'end');
        $periodStart = $this->extractSubscriptionPeriodBoundary($subscriptionArray, 'start');

        return [
            'subscription_id' => (string)$subscription->id,
            'status' => (string)$subscription->status,
            'customer_id' => (string)$subscription->customer,
            'current_period_end' => $periodEnd,
            'current_period_start' => $periodStart,
            'trial_end' => isset($subscriptionArray['trial_end']) ? (int)$subscriptionArray['trial_end'] : null,
            'raw' => $subscriptionArray,
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
        $subscriptionArray = $subscription->toArray();
        $periodEnd = $this->extractSubscriptionPeriodBoundary($subscriptionArray, 'end');
        $periodStart = $this->extractSubscriptionPeriodBoundary($subscriptionArray, 'start');

        return [
            'subscription_id' => $subscription->id,
            'status' => $subscription->status,
            'customer_id' => $subscription->customer,
            'current_period_end' => $periodEnd,
            'current_period_start' => $periodStart,
            'trial_end' => isset($subscriptionArray['trial_end']) ? (int)$subscriptionArray['trial_end'] : null,
            'cancel_at_period_end' => $subscription->cancel_at_period_end,
            'raw' => $subscriptionArray,
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
    public function createCheckoutSession(string $customerId, string $priceId, string $successUrl, string $cancelUrl, array $options = []): string
    {
        $params = [
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
        ];

        $metadata = is_array($options['metadata'] ?? null) ? $options['metadata'] : [];
        if ($metadata !== []) {
            $params['metadata'] = $metadata;
            $params['subscription_data'] = [
                'metadata' => $metadata,
            ];
        }

        $clientReferenceId = trim((string)($options['client_reference_id'] ?? ''));
        if ($clientReferenceId !== '') {
            $params['client_reference_id'] = $clientReferenceId;
        }

        $session = $this->stripe->checkout->sessions->create($params);

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

    /**
     * @inheritDoc
     */
    public function refundPayment(array $input): array
    {
        $paymentIntentId = trim((string)($input['payment_intent_id'] ?? ''));
        $chargeId = trim((string)($input['charge_id'] ?? ''));

        if ($paymentIntentId === '' && $chargeId === '') {
            throw new \InvalidArgumentException('Stripe refund requires payment_intent_id or charge_id.');
        }

        $amountMinor = isset($input['amount_minor']) ? (int)$input['amount_minor'] : null;
        if ($amountMinor !== null && $amountMinor <= 0) {
            throw new \InvalidArgumentException('Stripe refund amount_minor must be positive when provided.');
        }

        $params = [
            'reason' => trim((string)($input['reason'] ?? 'requested_by_customer')) ?: 'requested_by_customer',
            'metadata' => is_array($input['metadata'] ?? null) ? $input['metadata'] : [],
        ];

        if ($paymentIntentId !== '') {
            $params['payment_intent'] = $paymentIntentId;
        } else {
            $params['charge'] = $chargeId;
        }
        if ($amountMinor !== null) {
            $params['amount'] = $amountMinor;
        }

        $requestOptions = [];
        $idempotencyKey = trim((string)($input['idempotency_key'] ?? ''));
        if ($idempotencyKey !== '') {
            $requestOptions['idempotency_key'] = $idempotencyKey;
        }

        $refund = $this->stripe->refunds->create($params, $requestOptions);
        $gatewayStatus = strtolower(trim((string)($refund->status ?? '')));

        $status = match ($gatewayStatus) {
            'succeeded' => 'succeeded',
            'pending' => 'pending',
            'requires_action' => 'requires_action',
            'failed', 'canceled' => 'failed',
            default => $gatewayStatus !== '' ? $gatewayStatus : 'pending',
        };

        return [
            'ok' => in_array($status, ['succeeded', 'pending', 'requires_action'], true),
            'gateway_refund_id' => (string)($refund->id ?? ''),
            'status' => $status,
            'amount_minor' => (int)($refund->amount ?? ($amountMinor ?? 0)),
            'currency' => strtoupper((string)($refund->currency ?? ($input['currency'] ?? 'USD'))),
            'raw' => $refund->toArray(),
        ];
    }

    private function extractSubscriptionPeriodBoundary(array $subscription, string $boundary): ?int
    {
        $key = $boundary === 'start' ? 'current_period_start' : 'current_period_end';
        $itemBoundary = $subscription['items']['data'][0][$key] ?? null;
        if (is_int($itemBoundary) && $itemBoundary > 0) {
            return $itemBoundary;
        }
        if (is_numeric($itemBoundary) && (int)$itemBoundary > 0) {
            return (int)$itemBoundary;
        }

        $topLevelBoundary = $subscription[$key] ?? null;
        if (is_int($topLevelBoundary) && $topLevelBoundary > 0) {
            return $topLevelBoundary;
        }
        if (is_numeric($topLevelBoundary) && (int)$topLevelBoundary > 0) {
            return (int)$topLevelBoundary;
        }

        if ($boundary === 'end') {
            $trialEnd = $subscription['trial_end'] ?? null;
            if (is_int($trialEnd) && $trialEnd > 0) {
                return $trialEnd;
            }
            if (is_numeric($trialEnd) && (int)$trialEnd > 0) {
                return (int)$trialEnd;
            }
        }

        return null;
    }
}
