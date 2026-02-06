<?php

namespace Nraa\Listeners;

use Nraa\Events\UserCreated;
use Nraa\Services\EmailService;
use Nraa\Pillars\Log;

/**
 * Listener for UserCreated event
 * 
 * This listener is triggered when a new user is created.
 * It handles post-registration tasks like sending welcome emails,
 * initializing user preferences, etc.
 */
class UserCreatedListener
{
    /**
     * Handle the UserCreated event
     * 
     * @param UserCreated $event
     * @return void
     */
    public function handle(UserCreated $event): void
    {

        Log::info('UserCreatedListener: Processing new user', [
            'user_id' => (string)$event->user->id,
            'steam_id' => $event->user->steam_id ?? null
        ]);

        try {
            // Schedule welcome email to be sent 1 hour after signup
            $emailService = new EmailService();
            $emailService->queueWelcomeEmail($event->user);

            Log::info('UserCreatedListener: Welcome email scheduled', [
                'user_id' => (string)$event->user->id,
            ]);
        } catch (\Exception $e) {
            Log::error('UserCreatedListener: Error scheduling welcome email', [
                'user_id' => (string)$event->user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
