<?php

namespace Nraa\Jobs;

use Nraa\Models\Users\User;
use Nraa\Services\EmailService;
use Nraa\Pillars\Log;
use Nraa\DOM\TwigLoader;

/**
 * Send Welcome Email Job
 * 
 * Sends a welcome email to new users 1 hour after signup
 */
class SendWelcomeEmailJob
{
    public function handle(User $user): void
    {
        try {
            $twig = TwigLoader::getInstance();
            $emailService = new EmailService();
            $provider = $emailService->getEmailProviderManager()->provider();

            // Render email template
            $htmlBody = $twig->render('emails/welcome.twig', [
                'user' => $user,
            ]);

            // Generate plain text version (simple strip_tags for now)
            $textBody = strip_tags($htmlBody);

            // Send email
            $sent = $provider->send(
                $user->email,
                'Welcome to our site!',
                $htmlBody,
                $textBody
            );

            if ($sent) {
                Log::info('SendWelcomeEmailJob: Welcome email sent successfully', [
                    'user_id' => (string)$user->id,
                    'email' => $user->email,
                ]);
            } else {
                Log::error('SendWelcomeEmailJob: Failed to send welcome email', [
                    'user_id' => (string)$user->id,
                    'email' => $user->email,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('SendWelcomeEmailJob: Error sending welcome email', [
                'user_id' => (string)$user->id,
                'email' => $user->email,
                'error' => $e->getMessage(),
                'trace' => substr($e->getTraceAsString(), 0, 500),
            ]);
            // Rethrow so the job can be retried by the queue system
            throw $e;
        }
    }
}
