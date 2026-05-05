<?php

namespace Nraa\Payment;

use Nraa\Models\Users\User;

/**
 * Interface for payment gateway implementations
 */
interface PaymentGatewayInterface
{
    /**
     * Create a customer in the payment gateway
     * 
     * @param User $user The user to create a customer for
     * @return string The gateway customer ID
     */
    public function createCustomer(User $user): string;

    /**
     * Create a subscription for a customer.
     *
     * @param string $customerId The gateway customer ID
     * @param string $priceId The price/product ID for the subscription
     * @param array{
     *   trial_end?:int,
     *   trial_settings?:array<string,mixed>,
     *   metadata?:array<string,mixed>,
     *   payment_behavior?:string,
     *   payment_settings?:array<string,mixed>
     * } $options Optional gateway-specific options for subscription creation
     * @return array Subscription data including 'subscription_id', 'status', etc.
     */
    public function createSubscription(string $customerId, string $priceId, array $options = []): array;

    /**
     * Get customer portal URL for managing subscription
     * 
     * @param string $customerId The gateway customer ID
     * @param string $returnUrl URL to return to after portal session
     * @return string The portal session URL
     */
    public function getCustomerPortalUrl(string $customerId, string $returnUrl): string;

    /**
     * Cancel a subscription
     * 
     * @param string $subscriptionId The gateway subscription ID
     * @return bool True if cancelled successfully
     */
    public function cancelSubscription(string $subscriptionId): bool;

    /**
     * Get subscription details
     * 
     * @param string $subscriptionId The gateway subscription ID
     * @return array Subscription data including 'status', 'current_period_end', etc.
     */
    public function getSubscription(string $subscriptionId): array;

    /**
     * Handle webhook payload from payment gateway
     * 
     * @param string $payload The raw webhook payload string (must be raw for signature verification)
     * @param string $signature The webhook signature for verification
     * @return object Gateway-specific event object
     */
    public function handleWebhook(string $payload, string $signature): object;

    /**
     * Create a checkout session for subscription
     * 
     * @param string $customerId The gateway customer ID
     * @param string $priceId The price/product ID for the subscription
     * @param string $successUrl URL to redirect to after successful payment
     * @param string $cancelUrl URL to redirect to if payment is cancelled
     * @return string The checkout session URL
     */
    public function createCheckoutSession(string $customerId, string $priceId, string $successUrl, string $cancelUrl): string;

    /**
     * Create a payment refund.
     *
     * @param array{
     *   payment_intent_id?:string,
     *   charge_id?:string,
     *   amount_minor?:int,
     *   currency?:string,
     *   reason?:string,
     *   metadata?:array<string,mixed>,
     *   idempotency_key?:string
     * } $input
     * @return array{
     *   ok:bool,
     *   gateway_refund_id:string,
     *   status:string,
     *   amount_minor:int,
     *   currency:string,
     *   raw:array<string,mixed>
     * }
     */
    public function refundPayment(array $input): array;
}
