<?php

namespace Nraa\Models;

use Nraa\Database\Model;
use Nraa\Database\Attributes\Index;
use Nraa\Database\Attributes\BelongsToOne;
use MongoDB\BSON\UTCDateTime;

/**
 * User notifications
 * Stores notifications for users about various events (match ready, achievements, etc.)
 */
#[Index(keys: ['user_id' => 1, 'created_at' => -1])]
#[Index(keys: ['user_id' => 1, 'read_at' => 1])]
#[Index(keys: ['type' => 1])]
class Notification extends Model
{
    use \Nraa\Database\Traits\HasRelations;

    protected static $collection = 'notifications';

    // User reference
    public string $user_id;  // Reference to Users collection _id

    // Notification type (e.g., 'match_ready', 'achievement_unlocked', etc.)
    public string $type;

    // Notification title
    public string $title;

    // Notification message/body
    public string $message;

    // Action data (e.g., match_id for match_ready notifications)
    public ?array $action_data = null;  // JSON-like data for action (e.g., {'match_id': '...', 'url': '/cs2/match/...'})

    // Read status
    public bool $is_read = false;
    public ?UTCDateTime $read_at = null;

    // Timestamps
    public UTCDateTime $created_at;

    public function __construct()
    {
        parent::__construct();
        $this->created_at = new UTCDateTime();
    }
}
