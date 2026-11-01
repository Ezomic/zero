<?php

namespace App\Support\MailRules;

/**
 * What a rule is allowed to look at on a message: the sender, the people it
 * was addressed to and the decoded subject.
 */
final readonly class MessageFacts
{
    /** @param list<string> $recipients "Name <address>" or bare addresses, To and Cc together */
    public function __construct(
        public ?string $fromAddress,
        public ?string $fromName,
        public array $recipients,
        public string $subject,
    ) {}
}
