<?php

namespace Nraa\Database\Models;

use MongoDB\BSON\UTCDateTime;
use Nraa\Database\Attributes\Index;
use Nraa\Database\Model;

/**
 * Transaction Model (Framework-level)
 * 
 * Logs all payment transactions across all payment gateways.
 * This is a framework-level model, not application-specific.
 */
#[Index(keys: ['user_id' => 1, 'createdAt' => -1], options: [])]
#[Index(keys: ['membership_id' => 1], options: [])]
#[Index(keys: ['gateway_transaction_id' => 1], options: ['unique' => true])]
#[Index(keys: ['status' => 1, 'processed_at' => 1], options: [])]
class Transaction extends Model
{
    protected static $collection = 'transactions';

    public string $user_id = '';
    public string $membership_id = '';
    public string $gateway = ''; // 'stripe', etc.
    public string $gateway_transaction_id = '';
    public string $gateway_customer_id = '';
    public string $type = ''; // 'subscription_created', 'payment_succeeded', 'payment_failed', 'subscription_cancelled', etc.
    public string $status = ''; // 'pending', 'succeeded', 'failed', 'cancelled'
    public float $amount = 0.0;
    public string $currency = 'usd';
    public array $metadata = [];
    public ?UTCDateTime $processed_at = null;
    public string $error_message = '';
}
