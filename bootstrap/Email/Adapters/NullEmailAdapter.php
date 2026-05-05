<?php

namespace Nraa\Email\Adapters;

use Nraa\Email\AbstractEmailProvider;
use Nraa\Pillars\Log;

/**
 * No-op adapter used when outbound email is disabled.
 */
class NullEmailAdapter extends AbstractEmailProvider
{
    public function send(
        string $to,
        string $subject,
        string $htmlBody,
        ?string $textBody = null,
        ?string $fromEmail = null,
        ?string $fromName = null,
        array $options = []
    ): bool {
        Log::info('NullEmailAdapter: Suppressed outbound email', [
            'to' => $to,
            'subject' => $subject,
            'bcc' => array_values((array)($options['bcc'] ?? [])),
            'reason' => 'outbound_email_disabled',
        ]);

        return true;
    }
}
